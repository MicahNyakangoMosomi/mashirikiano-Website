<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SmsService.php';
require_once __DIR__ . '/LoanService.php';

/**
 * Class Mpesa
 * Handles M-Pesa Daraja API operations:
 * - Access token generation
 * - C2B URL registration
 * - Callback processing & storage
 */
class Mpesa
{
    /**
     * Cached config to avoid reloading file multiple times
     */
    private static ?array $config = null;

    /**
     * Load config once (singleton-style)
     */
    private static function config(): array
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../config/config.php';
        }
        return self::$config;
    }

    /**
     * Get M-Pesa base URL depending on environment
     */
    private static function baseUrl(): string
    {
        $env = self::config()['mpesa']['environment'];

        return strtolower($env) === 'sandbox'
            ? 'https://sandbox.safaricom.co.ke'
            : 'https://api.safaricom.co.ke';
    }

    /**
     * Generate OAuth access token
     */
    public static function accessToken(): string
    {
        $mpesa = self::config()['mpesa'];

        if (empty($mpesa['consumer_key']) || empty($mpesa['consumer_secret'])) {
            throw new RuntimeException("M-Pesa credentials missing.");
        }

        $credentials = base64_encode(
            $mpesa['consumer_key'] . ':' . $mpesa['consumer_secret']
        );

        $response = self::request(
            self::baseUrl() . '/oauth/v1/generate?grant_type=client_credentials',
            'GET',
            [
                'Authorization: Basic ' . $credentials
            ]
        );

        if (empty($response['access_token'])) {
            throw new RuntimeException("Failed to generate access token.");
        }

        return $response['access_token'];
    }

    /**
     * Register C2B confirmation & validation URLs
     */
    public static function registerC2BUrls(
        string $confirmationUrl,
        string $validationUrl,
        string $responseType = 'Completed'
    ): array {

        $mpesa = self::config()['mpesa'];

        if (empty($mpesa['shortcode'])) {
            throw new RuntimeException("Shortcode is required.");
        }

        return self::request(
            self::baseUrl() . '/mpesa/c2b/v1/registerurl',
            'POST',
            [
                'Authorization: Bearer ' . self::accessToken(),
                'Content-Type: application/json'
            ],
            [
                'ShortCode' => $mpesa['shortcode'],
                'ResponseType' => $responseType,
                'ConfirmationURL' => $confirmationUrl,
                'ValidationURL' => $validationUrl
            ]
        );
    }

    /**
     * Handle and store C2B callback data
     */
    public static function recordC2BCallback(array $payload): array
    {
        $data = self::normalizeC2BPayload($payload);
        self::validateC2BData($data);

        $pdo = Database::connection();

        // Prevent duplicate transaction processing.
        $check = $pdo->prepare("SELECT TranID FROM member_transactions WHERE TranID = :id LIMIT 1");
        $check->execute([':id' => $data['TranID']]);

        if ($check->fetch()) {
            return [
                'status' => 'duplicate',
                'message' => 'Transaction already exists',
                'tran_id' => $data['TranID']
            ];
        }

        // Check if BillRefNumber matches a 5-character loan repayment account (e.g., 7M419)
        $billRef = strtoupper(trim((string)($payload['BillRefNumber'] ?? '')));
        if (preg_match('/^[0-9][A-Z][0-9]{3}$/', $billRef) || strlen($billRef) === 5) {
            $activeLoan = null;
            try {
                LoanService::ensureTables($pdo);
                $lStmt = $pdo->prepare('SELECT * FROM active_loan WHERE repayment_account = :acct LIMIT 1');
                $lStmt->execute([':acct' => $billRef]);
                $activeLoan = $lStmt->fetch();

                if ($activeLoan) {
                    $loanMember = self::findMemberByLoanAccount($pdo, $billRef);
                    if (!$loanMember) {
                        $applicationStmt = $pdo->prepare('SELECT memberID FROM loan_application WHERE loanApplicationID = :application_id LIMIT 1');
                        $applicationStmt->execute([':application_id' => $activeLoan['loanApplicationID']]);
                        $applicationMemberId = $applicationStmt->fetchColumn();
                        if ($applicationMemberId !== false) {
                            $loanMember = self::findMemberById((string)$applicationMemberId);
                        }
                    }
                    if (!$loanMember) {
                        throw new RuntimeException('Loan repayment account is not linked to a SACCO member.');
                    }

                    $pdo->beginTransaction();
                    try {
                        $loanReceiptStmt = $pdo->prepare("SELECT repayment_account FROM loan_transaction WHERE mpesaReceiptNumber = :receipt AND transactionType = 'REPAYMENT' LIMIT 1");
                        $loanReceiptStmt->execute([':receipt' => $data['TranID']]);
                        $processedAccount = $loanReceiptStmt->fetchColumn();
                        if ($processedAccount !== false && strtoupper((string)$processedAccount) !== $billRef) {
                            throw new RuntimeException('M-Pesa receipt is already linked to another loan account.');
                        }

                        $repaymentResult = ['status' => 'already_processed'];
                        if ($processedAccount === false && in_array(strtoupper((string)$activeLoan['loanStatus']), ['ACTIVE', 'IN_ARREARS'], true)) {
                            $repaymentResult = LoanService::processRepayment(
                                $pdo,
                                $billRef,
                                (float)$data['Amount'],
                                $data['TranID'],
                                date('Y-m-d', strtotime($data['TranTime'] ?: 'now'))
                            );
                        }

                        $memberLedgerStmt = $pdo->prepare('SELECT TransactionID FROM member_transactions WHERE TranID = :tran_id AND TransactionType = \'loan_repayment\' LIMIT 1');
                        $memberLedgerStmt->execute([':tran_id' => $data['TranID']]);
                        if (!$memberLedgerStmt->fetchColumn()) {
                            self::insertLedgerTransaction(
                                $pdo,
                                $data,
                                $loanMember,
                                (float)$data['Amount'],
                                'loan_repayment',
                                'loan_repayment',
                                'Loan repayment for account ' . $billRef
                            );
                        }
                        $pdo->commit();
                    } catch (Throwable $error) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $error;
                    }

                    return [
                        'status' => 'recorded',
                        'type' => 'loan_repayment',
                        'message' => 'Loan repayment processed via waterfall engine',
                        'tran_id' => $data['TranID'],
                        'member_id' => $loanMember['MemberID'],
                        'loan_repayment' => $repaymentResult,
                        'data' => $data,
                    ];
                }
            } catch (Throwable $loanErr) {
                error_log('Loan repayment routing error: ' . $loanErr->getMessage());
                if ($activeLoan) {
                    throw $loanErr;
                }
            }
        }

        // Match the M-Pesa account reference against the member's registered National ID.
        $member = self::findMemberByAccountNumber($data['AccountNumber'], $data['MSISDN']);
        $memberId = $member ? (string)$member['MemberID'] : null;
        $segments = [];
        $activationSms = null;

        $pdo->beginTransaction();
        try {
            $remaining = (float)$data['Amount'];

            if ($memberId !== null) {
                self::ensureDepositRecord($pdo, $memberId);
                $deposit = self::lockDeposit($pdo, $memberId);
                $depositBalance = $deposit ? (float)$deposit['Balance'] : 0.00;
                $depositAmount = min($remaining, max(0.00, $depositBalance));

                if ($depositAmount > 0) {
                    self::insertLedgerTransaction($pdo, $data, $member, $depositAmount, 'deposit', 'onboarding', 'Deposit payment from M-Pesa');
                    $segments[] = ['type' => 'deposit', 'amount' => $depositAmount];
                    $remaining -= $depositAmount;

                    $newPaidAmount = (float)$deposit['PaidAmount'] + $depositAmount;
                    $newBalance = max(0.00, (float)$deposit['RequiredAmount'] - $newPaidAmount);
                    $depositStatus = $newBalance <= 0 ? 'cleared' : 'pending';

                    $updateDeposit = $pdo->prepare(
                        'UPDATE deposits
                         SET PaidAmount = :paid_amount, Balance = :balance, Status = :status
                         WHERE DepositID = :deposit_id'
                    );
                    $updateDeposit->execute([
                        ':paid_amount' => $newPaidAmount,
                        ':balance' => $newBalance,
                        ':status' => $depositStatus,
                        ':deposit_id' => $deposit['DepositID'],
                    ]);

                    if ($depositStatus === 'cleared' && (string)($member['Status'] ?? '') === 'Pending') {
                        $temporaryPassword = self::generateTemporaryPassword();
                        $activate = $pdo->prepare(
                            "UPDATE members
                             SET Status = 'Active', Password = :password
                             WHERE MemberID = :member_id AND Status = 'Pending'"
                        );
                        $activate->execute([
                            ':password' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
                            ':member_id' => $memberId,
                        ]);

                        if ($activate->rowCount() > 0) {
                            $activationSms = [
                                'phone' => (string)($member['PrimaryNumber'] ?: $data['MSISDN']),
                                'member_id' => $memberId,
                                'first_name' => (string)$member['FirstName'],
                                'last_name' => (string)$member['LastName'],
                                'password' => $temporaryPassword,
                            ];
                        }
                    }
                }
            }

            if ($remaining > 0) {
                self::insertLedgerTransaction($pdo, $data, $member, $remaining, 'contribution', 'monthly_contribution', 'Contribution payment from M-Pesa');
                $segments[] = ['type' => 'contribution', 'amount' => $remaining];
            }

            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        $activationSmsSent = null;
        if ($activationSms !== null) {
            $activationSmsSent = self::sendActivationSms($activationSms);
        }

        return [
            'status' => 'recorded',
            'message' => $memberId
                ? 'Transaction linked to member'
                : 'Transaction stored without match',
            'tran_id' => $data['TranID'],
            'member_id' => $memberId,
            'segments' => $segments,
            'data' => $data,
            'activation_sms_sent' => $activationSmsSent,
        ];
    }

    private static function sendActivationSms(array $activation): bool
    {
        $fullName = trim($activation['first_name'] . ' ' . $activation['last_name']);
        $memberId = $activation['member_id'];
        $password = $activation['password'];
        $message = "Dear {$fullName}, Thank you for joining Mashirikiano Sacco. You have been successfully registered. Your Membership ID is {$memberId} and your password is {$password}. Use your membershipid and the password as your login.
            Login url:https://mashirikianosacco.co.ke/auth/login.php
            Keep saving to qualify for loans of up to 3 times your savings.
            for support contact: itsupport@mashirikianosacco.co.ke or call 0758500557";

        return SmsService::sendSms($activation['phone'], $message);
    }

    private static function generateTemporaryPassword(int $length = 8): string
    {
        $digits = '0123456789';
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $characters = [];

        for ($index = 0; $index < 5; $index++) {
            $characters[] = $digits[random_int(0, strlen($digits) - 1)];
        }

        for ($index = 0; $index < 3; $index++) {
            $characters[] = $letters[random_int(0, strlen($letters) - 1)];
        }

        for ($index = count($characters) - 1; $index > 0; $index--) {
            $swapIndex = random_int(0, $index);
            [$characters[$index], $characters[$swapIndex]] = [$characters[$swapIndex], $characters[$index]];
        }

        return implode('', $characters);
    }

    private static function ensureDepositRecord(PDO $pdo, string $memberId): void
    {
        $stmt = $pdo->prepare('SELECT DepositID FROM deposits WHERE MemberID = :member_id LIMIT 1');
        $stmt->execute([':member_id' => $memberId]);

        if ($stmt->fetch()) {
            return;
        }

        $insert = $pdo->prepare(
            "INSERT INTO deposits (MemberID, RequiredAmount, PaidAmount, Balance, Status)
             VALUES (:member_id, 0.00, 0.00, 0.00, 'cleared')"
        );
        $insert->execute([':member_id' => $memberId]);
    }

    private static function lockDeposit(PDO $pdo, string $memberId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM deposits WHERE MemberID = :member_id LIMIT 1 FOR UPDATE');
        $stmt->execute([':member_id' => $memberId]);

        return $stmt->fetch() ?: null;
    }

    private static function insertLedgerTransaction(PDO $pdo, array $data, ?array $member, float $amount, string $type, string $category, string $description): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO member_transactions
            (TranID, MemberID, AccountNumber, FirstName, LastName, MSISDN, Amount, TransactionType, TransactionCategory, Reference, Description, TranTime)
            VALUES
            (:tran_id, :member_id, :account_number, :first_name, :last_name, :msisdn, :amount, :transaction_type, :transaction_category, :reference, :description, :tran_time)
        ");

        $stmt->execute([
            ':tran_id' => $data['TranID'],
            ':member_id' => $member ? (string)$member['MemberID'] : null,
            ':account_number' => $data['AccountNumber'],
            ':first_name' => $member ? (string)$member['FirstName'] : $data['FirstName'],
            ':last_name' => $member ? (string)$member['LastName'] : $data['LastName'],
            ':msisdn' => $data['MSISDN'],
            ':amount' => $amount,
            ':transaction_type' => $type,
            ':transaction_category' => $category,
            ':reference' => $data['TranID'],
            ':description' => $description,
            ':tran_time' => $data['TranTime'],
        ]);

        $transactionId = (int)$pdo->lastInsertId();

        if ($type === 'contribution') {
            self::ensureSaccoWalletsTable($pdo);
            $ushirikaAmount = round($amount * 0.05, 2);
            $akibaAmount = round($amount - $ushirikaAmount, 2);

            $walletStmt = $pdo->prepare("
                INSERT INTO SaccoWallets
                (TransactionID, TranID, MemberID, NationalID, Amount, AkibaAmount, UshirikaAmount, CreatedAt)
                VALUES
                (:tx_id, :tran_id, :member_id, :national_id, :amount, :akiba, :ushirika, :created_at)
                ON DUPLICATE KEY UPDATE
                Amount = VALUES(Amount),
                AkibaAmount = VALUES(AkibaAmount),
                UshirikaAmount = VALUES(UshirikaAmount)
            ");
            $walletStmt->execute([
                ':tx_id' => $transactionId,
                ':tran_id' => $data['TranID'],
                ':member_id' => $member ? (string)$member['MemberID'] : null,
                ':national_id' => $data['AccountNumber'],
                ':amount' => $amount,
                ':akiba' => $akibaAmount,
                ':ushirika' => $ushirikaAmount,
                ':created_at' => $data['TranTime'] ?: date('Y-m-d H:i:s'),
            ]);
        }
    }

    public static function ensureSaccoWalletsTable(PDO $pdo): void
    {
        try {
            $pdo->query("SELECT 1 FROM `SaccoWallets` LIMIT 1");
        } catch (Throwable $e) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `SaccoWallets` (
              `WalletRecordID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `TransactionID` BIGINT UNSIGNED NOT NULL,
              `TranID` VARCHAR(60) NOT NULL,
              `MemberID` VARCHAR(40) NULL,
              `NationalID` VARCHAR(30) NOT NULL,
              `Amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `AkibaAmount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `UshirikaAmount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`WalletRecordID`),
              UNIQUE KEY `uniq_sacco_wallets_transaction` (`TransactionID`),
              KEY `idx_sacco_wallets_tran_id` (`TranID`),
              KEY `idx_sacco_wallets_member` (`MemberID`),
              KEY `idx_sacco_wallets_national_id` (`NationalID`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        }
    }

    /**
     * Normalize raw M-Pesa callback payload
     */
    private static function normalizeC2BPayload(array $payload): array
    {
        return [
            'AccountNumber' => self::cleanString($payload['BillRefNumber'] ?? ''),
            'TranID'     => self::cleanString($payload['TransID'] ?? ''),
            'Amount'     => (float)($payload['TransAmount'] ?? 0),
            'MSISDN'     => self::normalizePhone($payload['MSISDN'] ?? ''),
            'FirstName'  => self::cleanString($payload['FirstName'] ?? ''),
            'LastName'   => self::cleanString($payload['LastName'] ?? ''),
            'TranTime'   => self::formatTime($payload['TransTime'] ?? '')
        ];
    }

    /**
     * Basic validation for required fields
     */
    private static function validateC2BData(array $data): void
    {
        if ($data['TranID'] === '') {
            throw new InvalidArgumentException("Missing transaction ID");
        }

        if ($data['AccountNumber'] === '') {
            throw new InvalidArgumentException("Missing account number");
        }

        if ($data['Amount'] <= 0) {
            throw new InvalidArgumentException("Invalid amount");
        }

        if ($data['MSISDN'] === '') {
            throw new InvalidArgumentException("Missing phone number");
        }
    }

    /**
     * Find member by National ID
     */
    private static function findMemberByNationalId(string $id): ?array
    {
        $stmt = Database::connection()->prepare("
            SELECT * FROM members WHERE NationalID = :id LIMIT 1
        ");

        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    private static function findMemberByAccountNumber(string $accountNumber, string $phone): ?array
    {
        $member = self::findMemberByNationalId($accountNumber);
        if (!$member) {
            $memberStmt = Database::connection()->prepare('SELECT * FROM members WHERE MemberID = :member_id LIMIT 1');
            $memberStmt->execute([':member_id' => $accountNumber]);
            $member = $memberStmt->fetch() ?: null;
        }
        if ($member || $phone === '') {
            return $member;
        }

        $targetPhone = self::normalizePhone($phone);
        $stmt = Database::connection()->query('SELECT * FROM members WHERE PrimaryNumber IS NOT NULL');
        foreach ($stmt->fetchAll() as $candidate) {
            if (self::normalizePhone((string)$candidate['PrimaryNumber']) === $targetPhone) {
                return $candidate;
            }
        }

        return null;
    }


    // gets the member's details based on the loan repayment account number
    private static function findMemberByLoanAccount(PDO $pdo, string $repaymentAccount): ?array
    {

        $stmt = $pdo->prepare('SELECT m.MemberID, m.FirstName, m.LastName, m.PrimaryNumber
            FROM active_loan al
            JOIN members m ON m.MemberID = al.memberID
            WHERE al.repayment_account = :account
            LIMIT 1');
        $stmt->execute([':account' => $repaymentAccount]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Find member by Member ID
     */
    private static function findMemberById(string $memberId): ?array
    {
        $stmt = Database::connection()->prepare("
            SELECT * FROM members WHERE MemberID = :id LIMIT 1
        ");

        $stmt->execute([':id' => $memberId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Normalize phone number to 254 format
     */
    private static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone);

        if (str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        if (str_starts_with($phone, '+')) {
            return substr($phone, 1);
        }

        return $phone;
    }

    /**
     * Convert M-Pesa timestamp to MySQL format
     */
    private static function formatTime(string $time): ?string
    {
        if ($time === '') return null;

        $date = DateTime::createFromFormat('YmdHis', $time);

        return $date ? $date->format('Y-m-d H:i:s') : null;
    }

    /**
     * Clean string input
     */
    private static function cleanString($value): string
    {
        return trim((string)$value);
    }

    /**
     * Generic HTTP request handler (cURL)
     */
    private static function request(
        string $url,
        string $method,
        array $headers = [],
        ?array $payload = null
    ): array {

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_CONNECTTIMEOUT  => 10
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $error     = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        // Network failure
        if ($response === false || $httpCode === 0) {
            throw new RuntimeException("Request failed: " . $error);
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON response from M-Pesa");
        }

        // API error response
        if ($httpCode >= 400) {
            $msg = $decoded['errorMessage']
                ?? $decoded['ResponseDescription']
                ?? 'M-Pesa request failed';

            throw new RuntimeException($msg);
        }

        return $decoded;
    }
}
