<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SmsService.php';

class LoanService
{
    /**
     * Self-healing migration to ensure all 4 loan engine tables exist.
     */
    public static function ensureTables(?PDO $pdo = null): void
    {
        $pdo = $pdo ?: Database::connection();

        // 1. Table: loan (Admin Loan Product Catalog)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `loan` (
            `loanID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `loanType` VARCHAR(100) NOT NULL,
            `loanDescription` TEXT NULL,
            `maxAmount` DECIMAL(15,2) NOT NULL,
            `loanQualification_AkibaAmount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `loanQualification_UshirikaAmount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `loanInterest` DECIMAL(6,4) NOT NULL,
            `paymentPeriod` VARCHAR(50) NOT NULL,
            `interestType` VARCHAR(20) NOT NULL,
            `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`loanID`),
            KEY `idx_loan_type` (`loanType`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2. Table: loan_application (Member Submissions & Workflow)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `loan_application` (
            `loanApplicationID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `loanID` BIGINT UNSIGNED NOT NULL,
            `memberID` VARCHAR(40) NOT NULL,
            `loanType` VARCHAR(100) NOT NULL,
            `amount` DECIMAL(15,2) NOT NULL,
            `returndate` DATE NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
            `rejection` TEXT NULL,
            `repayment_account` VARCHAR(5) NULL,
            `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`loanApplicationID`),
            UNIQUE KEY `uniq_loan_app_repayment_account` (`repayment_account`),
            KEY `idx_loan_app_member` (`memberID`),
            KEY `idx_loan_app_loan` (`loanID`),
            KEY `idx_loan_app_status` (`status`),
            CONSTRAINT `fk_loan_app_member`
              FOREIGN KEY (`memberID`) REFERENCES `members` (`MemberID`)
              ON UPDATE CASCADE
              ON DELETE CASCADE,
            CONSTRAINT `fk_loan_app_loan`
              FOREIGN KEY (`loanID`) REFERENCES `loan` (`loanID`)
              ON UPDATE CASCADE
              ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 3. Table: active_loan (Live Operational Ledger)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `active_loan` (
            `activeLoanID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `loanApplicationID` BIGINT UNSIGNED NOT NULL,
            `memberID` VARCHAR(40) NOT NULL,
            `repayment_account` VARCHAR(5) NOT NULL,
            `interestType` VARCHAR(20) NOT NULL,
            `annualInterestRate` DECIMAL(6,4) NOT NULL,
            `originalPrincipal` DECIMAL(15,2) NOT NULL,
            `principalBalance` DECIMAL(15,2) NOT NULL,
            `accruedInterest` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `billedUnpaidInterest` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `penaltyBalance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `disbursementDate` DATE NOT NULL,
            `returnDate` DATE NOT NULL,
            `lastInterestCalcDate` DATE NOT NULL,
            `loanStatus` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`activeLoanID`),
            UNIQUE KEY `uniq_active_loan_application` (`loanApplicationID`),
            UNIQUE KEY `uniq_active_loan_repayment_acct` (`repayment_account`),
            KEY `idx_active_loan_member` (`memberID`),
            KEY `idx_active_loan_status` (`loanStatus`),
            KEY `idx_active_loan_return_date` (`returnDate`),
            CONSTRAINT `fk_active_loan_app`
              FOREIGN KEY (`loanApplicationID`) REFERENCES `loan_application` (`loanApplicationID`)
              ON UPDATE CASCADE
              ON DELETE CASCADE,
            CONSTRAINT `fk_active_loan_member`
              FOREIGN KEY (`memberID`) REFERENCES `members` (`MemberID`)
              ON UPDATE CASCADE
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 4. Table: loan_transaction (Immutable Audit Log & Snapshots)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `loan_transaction` (
            `transactionID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `activeLoanID` BIGINT UNSIGNED NOT NULL,
            `repayment_account` VARCHAR(5) NOT NULL,
            `mpesaReceiptNumber` VARCHAR(50) NULL,
            `transactionType` VARCHAR(30) NOT NULL,
            `transactionDate` DATE NOT NULL,
            `totalAmount` DECIMAL(15,2) NOT NULL,
            `allocatedPenalty` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `allocatedBilledInterest` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `allocatedAccruedInterest` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `allocatedPrincipal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `principalAfter` DECIMAL(15,2) NOT NULL,
            `accruedInterestAfter` DECIMAL(15,2) NOT NULL,
            `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`transactionID`),
            UNIQUE KEY `uniq_loan_trans_mpesa` (`mpesaReceiptNumber`),
            KEY `idx_loan_trans_active_loan` (`activeLoanID`),
            KEY `idx_loan_trans_repayment_acct` (`repayment_account`),
            KEY `idx_loan_trans_type` (`transactionType`),
            KEY `idx_loan_trans_date` (`transactionDate`),
            CONSTRAINT `fk_loan_trans_active_loan`
              FOREIGN KEY (`activeLoanID`) REFERENCES `active_loan` (`activeLoanID`)
              ON UPDATE CASCADE
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    /**
     * Generate a unique 5-character M-Pesa C2B routing code matching '^[0-9][A-Z][0-9]{3}$'
     * Example: 4K902, 7M419
     */
    public static function generateRepaymentAccount(PDO $pdo): string
    {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '0123456789';

        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $code = $digits[random_int(0, 9)]
                  . $letters[random_int(0, strlen($letters) - 1)]
                  . $digits[random_int(0, 9)]
                  . $digits[random_int(0, 9)]
                  . $digits[random_int(0, 9)];

            $check1 = $pdo->prepare("SELECT 1 FROM loan_application WHERE repayment_account = :code LIMIT 1");
            $check1->execute([':code' => $code]);
            if ($check1->fetch()) {
                continue;
            }

            $check2 = $pdo->prepare("SELECT 1 FROM active_loan WHERE repayment_account = :code LIMIT 1");
            $check2->execute([':code' => $code]);
            if ($check2->fetch()) {
                continue;
            }

            return $code;
        }

        throw new RuntimeException("Unable to generate unique repayment account code after multiple attempts.");
    }

    /**
     * Get member wallet balances (Akiba & Ushirika) from member_wallet_balances view or SaccoWallets
     */
    public static function getMemberWalletBalances(PDO $pdo, string $memberId): array
    {
        try {
            $stmt = $pdo->prepare("SELECT AkibaWalletBalance, TotalUshirikaShares, TotalContributions FROM member_wallet_balances WHERE MemberID = :id LIMIT 1");
            $stmt->execute([':id' => $memberId]);
            $res = $stmt->fetch();
            if ($res) {
                return [
                    'akiba' => (float)$res['AkibaWalletBalance'],
                    'ushirika' => (float)$res['TotalUshirikaShares'],
                    'totalContributions' => (float)$res['TotalContributions'],
                ];
            }
        } catch (Throwable $e) {
            // View might not exist or fallback to table aggregation
        }

        // Direct fallback query from SaccoWallets and withdrawals
        $wStmt = $pdo->prepare("SELECT COALESCE(SUM(AkibaAmount), 0) AS total_akiba, COALESCE(SUM(UshirikaAmount), 0) AS total_ushirika, COALESCE(SUM(Amount), 0) AS total_contrib FROM SaccoWallets WHERE MemberID = :id");
        $wStmt->execute([':id' => $memberId]);
        $row = $wStmt->fetch() ?: [];

        $withdStmt = $pdo->prepare("SELECT COALESCE(SUM(Amount), 0) FROM withdrawals WHERE MemberID = :id");
        $withdStmt->execute([':id' => $memberId]);
        $totalWithdrawals = (float)$withdStmt->fetchColumn();

        $totalAkiba = (float)($row['total_akiba'] ?? 0);
        $totalUshirika = (float)($row['total_ushirika'] ?? 0);
        $totalContrib = (float)($row['total_contrib'] ?? 0);
        $akibaBalance = max(0.00, $totalAkiba - $totalWithdrawals);

        return [
            'akiba' => $akibaBalance,
            'ushirika' => $totalUshirika,
            'totalContributions' => $totalContrib,
        ];
    }

    /**
     * Check member qualification for a specific loan product
     */
    public static function checkEligibility(PDO $pdo, string $memberId, int $loanId): array
    {
        $productStmt = $pdo->prepare("SELECT * FROM loan WHERE loanID = :id LIMIT 1");
        $productStmt->execute([':id' => $loanId]);
        $product = $productStmt->fetch();

        if (!$product) {
            return [
                'is_eligible' => false,
                'reason' => 'Loan product not found',
                'req_akiba' => 0.00,
                'req_ushirika' => 0.00,
                'current_akiba' => 0.00,
                'current_ushirika' => 0.00,
            ];
        }

        $wallets = self::getMemberWalletBalances($pdo, $memberId);
        $reqAkiba = (float)$product['loanQualification_AkibaAmount'];
        $reqUshirika = (float)$product['loanQualification_UshirikaAmount'];

        $akibaOk = ($wallets['akiba'] >= $reqAkiba);
        $ushirikaOk = ($wallets['ushirika'] >= $reqUshirika);

        $isEligible = ($akibaOk && $ushirikaOk);
        $reasons = [];
        if (!$akibaOk) {
            $reasons[] = "Requires KES " . number_format($reqAkiba, 2) . " in Akiba (Current: KES " . number_format($wallets['akiba'], 2) . ")";
        }
        if (!$ushirikaOk) {
            $reasons[] = "Requires KES " . number_format($reqUshirika, 2) . " in Ushirika (Current: KES " . number_format($wallets['ushirika'], 2) . ")";
        }

        return [
            'is_eligible' => $isEligible,
            'reason' => empty($reasons) ? 'Eligible' : implode(' & ', $reasons),
            'req_akiba' => $reqAkiba,
            'req_ushirika' => $reqUshirika,
            'current_akiba' => $wallets['akiba'],
            'current_ushirika' => $wallets['ushirika'],
            'product' => $product
        ];
    }

    /**
     * Submit a loan application
     */
    public static function submitApplication(PDO $pdo, string $memberId, int $loanId, float $amount, string $returnDate): int
    {
        self::ensureTables($pdo);

        $productStmt = $pdo->prepare("SELECT * FROM loan WHERE loanID = :id LIMIT 1");
        $productStmt->execute([':id' => $loanId]);
        $product = $productStmt->fetch();

        if (!$product) {
            throw new InvalidArgumentException("Selected loan product not found.");
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException("Loan amount must be greater than zero.");
        }

        $maxAmount = (float)$product['maxAmount'];
        if ($amount > $maxAmount) {
            throw new InvalidArgumentException("Requested amount KES " . number_format($amount, 2) . " exceeds the product maximum limit of KES " . number_format($maxAmount, 2));
        }

        // Eligibility validation
        $eligibility = self::checkEligibility($pdo, $memberId, $loanId);
        if (!$eligibility['is_eligible']) {
            throw new InvalidArgumentException("Eligibility criteria not met: " . $eligibility['reason']);
        }

        $today = date('Y-m-d');
        $isCustom = (strtoupper(trim($product['paymentPeriod'])) === 'CUSTOM');

        if ($isCustom) {
            // Strict rule: (returndate - appliedDate) >= 121 (enforcing > 120 days)
            $diffDays = (int)round((strtotime($returnDate) - strtotime($today)) / 86400);
            if ($diffDays < 121) {
                throw new InvalidArgumentException("Custom clearance date must be at least 121 days from application date (greater than 120 days). Selected: $diffDays days.");
            }
            $finalReturnDate = $returnDate;
        } else {
            // Predefined payment period
            $durationDays = 30;
            if (preg_match('/(\d+)/', $product['paymentPeriod'], $matches)) {
                $durationDays = (int)$matches[1];
            }
            $finalReturnDate = date('Y-m-d', strtotime("+$durationDays days"));
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO loan_application 
            (loanID, memberID, loanType, amount, returndate, status, rejection, repayment_account)
            VALUES 
            (:loan_id, :member_id, :loan_type, :amount, :return_date, 'PENDING', NULL, NULL)
        ");
        $insertStmt->execute([
            ':loan_id' => $loanId,
            ':member_id' => $memberId,
            ':loan_type' => $product['loanType'],
            ':amount' => $amount,
            ':return_date' => $finalReturnDate,
        ]);

        $appId = (int)$pdo->lastInsertId();

        // Send submission SMS to member
        $memberStmt = $pdo->prepare("SELECT FirstName, PrimaryNumber FROM members WHERE MemberID = :id LIMIT 1");
        $memberStmt->execute([':id' => $memberId]);
        $member = $memberStmt->fetch();

        if ($member && !empty($member['PrimaryNumber'])) {
            $formattedDate = date('d-M-Y', strtotime($finalReturnDate));
            $sms = "Dear {$member['FirstName']}, your application for {$product['loanType']} of KES " . number_format($amount, 2) . " scheduled for return by {$formattedDate} has been received. You will receive an update once reviewed.";
            SmsService::sendSms($member['PrimaryNumber'], $sms);
        }

        return $appId;
    }

    /**
     * Approve loan application: generates unique 5-char code, creates active_loan and initial DISBURSEMENT transaction
     */
    public static function approveApplication(PDO $pdo, int $applicationId): array
    {
        self::ensureTables($pdo);

        $pdo->beginTransaction();
        try {
            $appStmt = $pdo->prepare("
                SELECT la.*, l.interestType, l.loanInterest 
                FROM loan_application la
                JOIN loan l ON l.loanID = la.loanID
                WHERE la.loanApplicationID = :id FOR UPDATE
            ");
            $appStmt->execute([':id' => $applicationId]);
            $app = $appStmt->fetch();

            if (!$app) {
                throw new RuntimeException("Loan application #$applicationId not found.");
            }

            if ($app['status'] !== 'PENDING') {
                throw new RuntimeException("This application is already processed (Status: {$app['status']}).");
            }

            // Generate unique 5-character M-Pesa C2B routing code
            $repaymentAccount = self::generateRepaymentAccount($pdo);
            $today = date('Y-m-d');
            $principal = (float)$app['amount'];
            $annualRate = (float)$app['loanInterest'];
            $interestType = $app['interestType'];

            // 1. Update loan_application
            $upApp = $pdo->prepare("
                UPDATE loan_application 
                SET status = 'APPROVED', repayment_account = :account, updatedAt = NOW() 
                WHERE loanApplicationID = :id
            ");
            $upApp->execute([
                ':account' => $repaymentAccount,
                ':id' => $applicationId,
            ]);

            // 2. Insert into active_loan
            $actStmt = $pdo->prepare("
                INSERT INTO active_loan 
                (loanApplicationID, memberID, repayment_account, interestType, annualInterestRate,
                 originalPrincipal, principalBalance, accruedInterest, billedUnpaidInterest, penaltyBalance,
                 disbursementDate, returnDate, lastInterestCalcDate, loanStatus)
                VALUES 
                (:app_id, :member_id, :repayment_account, :interest_type, :annual_rate,
                 :orig_principal, :principal_bal, 0.00, 0.00, 0.00,
                 :disb_date, :return_date, :last_calc_date, 'ACTIVE')
            ");
            $actStmt->execute([
                ':app_id' => $applicationId,
                ':member_id' => $app['memberID'],
                ':repayment_account' => $repaymentAccount,
                ':interest_type' => $interestType,
                ':annual_rate' => $annualRate,
                ':orig_principal' => $principal,
                ':principal_bal' => $principal,
                ':disb_date' => $today,
                ':return_date' => $app['returndate'],
                ':last_calc_date' => $today,
            ]);
            $activeLoanId = (int)$pdo->lastInsertId();

            // 3. Insert DISBURSEMENT into loan_transaction
            $txStmt = $pdo->prepare("
                INSERT INTO loan_transaction 
                (activeLoanID, repayment_account, mpesaReceiptNumber, transactionType, transactionDate,
                 totalAmount, allocatedPenalty, allocatedBilledInterest, allocatedAccruedInterest, allocatedPrincipal,
                 principalAfter, accruedInterestAfter)
                VALUES 
                (:active_id, :account, NULL, 'DISBURSEMENT', :tx_date,
                 :total_amt, 0.00, 0.00, 0.00, :allocated_principal,
                 :principal_after, 0.00)
            ");
            $txStmt->execute([
                ':active_id'           => $activeLoanId,
                ':account'             => $repaymentAccount,
                ':tx_date'             => $today,
                ':total_amt'           => $principal,
                ':allocated_principal' => $principal,
                ':principal_after'     => $principal,
            ]);

            $pdo->commit();

            // Notify member via SMS
            $memStmt = $pdo->prepare("SELECT FirstName, PrimaryNumber FROM members WHERE MemberID = :id LIMIT 1");
            $memStmt->execute([':id' => $app['memberID']]);
            $member = $memStmt->fetch();

            if ($member && !empty($member['PrimaryNumber'])) {
                $smsMsg = "Dear {$member['FirstName']}, your {$app['loanType']} of KES " . number_format($principal, 2) . " has been APPROVED! Your M-Pesa Repayment Account Number is {$repaymentAccount}. When repaying via Paybill, enter Account: {$repaymentAccount}. Due date: " . date('d-M-Y', strtotime($app['returndate']));
                SmsService::sendSms($member['PrimaryNumber'], $smsMsg);
            }

            return [
                'activeLoanID' => $activeLoanId,
                'repayment_account' => $repaymentAccount,
                'status' => 'APPROVED',
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reject loan application
     */
    public static function rejectApplication(PDO $pdo, int $applicationId, string $rejectionReason): void
    {
        self::ensureTables($pdo);

        $pdo->beginTransaction();
        try {
            $appStmt = $pdo->prepare("SELECT * FROM loan_application WHERE loanApplicationID = :id FOR UPDATE");
            $appStmt->execute([':id' => $applicationId]);
            $app = $appStmt->fetch();

            if (!$app) {
                throw new RuntimeException("Loan application #$applicationId not found.");
            }

            if ($app['status'] !== 'PENDING') {
                throw new RuntimeException("This application is already processed (Status: {$app['status']}).");
            }

            $up = $pdo->prepare("UPDATE loan_application SET status = 'REJECTED', rejection = :reason, updatedAt = NOW() WHERE loanApplicationID = :id");
            $up->execute([
                ':reason' => trim($rejectionReason),
                ':id' => $applicationId,
            ]);

            $pdo->commit();

            // Send SMS notification
            $memStmt = $pdo->prepare("SELECT FirstName, PrimaryNumber FROM members WHERE MemberID = :id LIMIT 1");
            $memStmt->execute([':id' => $app['memberID']]);
            $member = $memStmt->fetch();

            if ($member && !empty($member['PrimaryNumber'])) {
                $sms = "Dear {$member['FirstName']}, your application for {$app['loanType']} has not been approved. Reason: " . trim($rejectionReason) . ". For queries call 0758500557.";
                SmsService::sendSms($member['PrimaryNumber'], $sms);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * CORE ACCRUAL ENGINE: Event-Driven Accrual catchUpInterest(activeLoan)
     * Day-Count Convention: Actual/365 (annualRate / 365)
     * Reducing Balance computes on principalBalance; Flat Rate computes on originalPrincipal.
     */
    public static function catchUpInterest(PDO $pdo, int $activeLoanId): array
    {
        if (!$pdo->inTransaction()) {
            self::ensureTables($pdo);
        }

        $loanStmt = $pdo->prepare("SELECT * FROM active_loan WHERE activeLoanID = :id FOR UPDATE");
        $loanStmt->execute([':id' => $activeLoanId]);
        $loan = $loanStmt->fetch();

        if (!$loan) {
            throw new RuntimeException("Active loan #$activeLoanId not found.");
        }

        if ($loan['loanStatus'] === 'CLOSED') {
            return $loan;
        }

        $todayStr = date('Y-m-d');
        $lastCalcStr = $loan['lastInterestCalcDate'];

        // Calculate Day Delta: days = CURRENT_DATE - lastInterestCalcDate
        $days = (int)round((strtotime($todayStr) - strtotime($lastCalcStr)) / 86400);

        // If days <= 0, do nothing and exit
        if ($days <= 0) {
            return $loan;
        }

        // Determine Base Principal
        $base = ($loan['interestType'] === 'REDUCING_BALANCE')
            ? (float)$loan['principalBalance']
            : (float)$loan['originalPrincipal'];

        if ($base <= 0) {
            // No base principal remaining, just advance the date
            $upDate = $pdo->prepare("UPDATE active_loan SET lastInterestCalcDate = :today, updatedAt = NOW() WHERE activeLoanID = :id");
            $upDate->execute([':today' => $todayStr, ':id' => $activeLoanId]);
            $loan['lastInterestCalcDate'] = $todayStr;
            return $loan;
        }

        // Compute New Interest: Base * (annualInterestRate / 365) * days
        $annualRate = (float)$loan['annualInterestRate'];
        $dailyRate = $annualRate / 365.0;
        $newInterest = round($base * $dailyRate * $days, 2);

        $currentAccrued = (float)$loan['accruedInterest'];
        $updatedAccrued = round($currentAccrued + $newInterest, 2);

        // Commit State & Audit
        $upLoan = $pdo->prepare("
            UPDATE active_loan 
            SET accruedInterest = :accrued, lastInterestCalcDate = :today, updatedAt = NOW() 
            WHERE activeLoanID = :id
        ");
        $upLoan->execute([
            ':accrued' => $updatedAccrued,
            ':today' => $todayStr,
            ':id' => $activeLoanId,
        ]);

        if ($newInterest > 0) {
            $insTx = $pdo->prepare("
                INSERT INTO loan_transaction 
                (activeLoanID, repayment_account, mpesaReceiptNumber, transactionType, transactionDate,
                 totalAmount, allocatedPenalty, allocatedBilledInterest, allocatedAccruedInterest, allocatedPrincipal,
                 principalAfter, accruedInterestAfter)
                VALUES 
                (:active_id, :account, NULL, 'INTEREST_ACCRUAL', :tx_date,
                 :amount, 0.00, 0.00, 0.00, 0.00,
                 :pb_after, :ai_after)
            ");
            $insTx->execute([
                ':active_id' => $activeLoanId,
                ':account' => $loan['repayment_account'],
                ':tx_date' => $todayStr,
                ':amount' => $newInterest,
                ':pb_after' => (float)$loan['principalBalance'],
                ':ai_after' => $updatedAccrued,
            ]);
        }

        $loan['accruedInterest'] = $updatedAccrued;
        $loan['lastInterestCalcDate'] = $todayStr;

        return $loan;
    }

    /**
     * WATERFALL ALLOCATION & REPAYMENT ROUTING
     * Executes atomic waterfall when cash arrives via 5-character repayment_account.
     * Priority:
     *   1st: penaltyBalance
     *   2nd: billedUnpaidInterest
     *   3rd: accruedInterest (full coverage resets to 0.00, partial coverage leaves unpaid remainder)
     *   4th: principalBalance (any remaining cash lowers principal)
     */
    public static function processRepayment(
        PDO $pdo,
        string $repaymentAccount,
        float $amount,
        ?string $mpesaReceiptNumber = null,
        ?string $transactionDate = null
    ): array {
        $wasInTx = $pdo->inTransaction();
        if (!$wasInTx) {
            self::ensureTables($pdo);
        }

        $transactionDate = $transactionDate ?: date('Y-m-d');
        $repaymentAccount = strtoupper(trim($repaymentAccount));

        $wasInTx = $pdo->inTransaction();
        if (!$wasInTx) {
            $pdo->beginTransaction();
        }

        try {
            // Find active loan
            $stmt = $pdo->prepare("
                SELECT * FROM active_loan 
                WHERE repayment_account = :account AND loanStatus IN ('ACTIVE', 'IN_ARREARS') 
                FOR UPDATE
            ");
            $stmt->execute([':account' => $repaymentAccount]);
            $loan = $stmt->fetch();

            if (!$loan) {
                throw new RuntimeException("No active loan found for repayment account: $repaymentAccount");
            }

            $activeLoanId = (int)$loan['activeLoanID'];

            // Step 1 - Catch Up Interest First
            $loan = self::catchUpInterest($pdo, $activeLoanId);

            // Step 2 - Waterfall Allocation
            $cash = $amount;

            // 1st: penaltyBalance
            $penaltyBal = (float)$loan['penaltyBalance'];
            $payPenalty = min($cash, $penaltyBal);
            $cash -= $payPenalty;
            $newPenalty = round($penaltyBal - $payPenalty, 2);

            // 2nd: billedUnpaidInterest
            $billedBal = (float)$loan['billedUnpaidInterest'];
            $payBilled = min($cash, $billedBal);
            $cash -= $payBilled;
            $newBilled = round($billedBal - $payBilled, 2);

            // 3rd: accruedInterest
            $accruedBal = (float)$loan['accruedInterest'];
            $payAccrued = min($cash, $accruedBal);
            $cash -= $payAccrued;
            $newAccrued = round($accruedBal - $payAccrued, 2);

            // 4th: principalBalance
            $principalBal = (float)$loan['principalBalance'];
            $payPrincipal = min($cash, $principalBal);
            $cash -= $payPrincipal;
            $newPrincipal = round(max(0.00, $principalBal - $payPrincipal), 2);

            // Step 3 - Snapshots & Status Check
            $principalAfter = $newPrincipal;
            $accruedInterestAfter = $newAccrued;

            $newStatus = $loan['loanStatus'];
            if ($principalAfter <= 0.00) {
                $newStatus = 'CLOSED';
            } elseif ($newPenalty <= 0.00 && $newBilled <= 0.00) {
                $newStatus = 'ACTIVE';
            }

            // Update active_loan
            $upStmt = $pdo->prepare("
                UPDATE active_loan 
                SET principalBalance = :pb,
                    accruedInterest = :ai,
                    billedUnpaidInterest = :bui,
                    penaltyBalance = :pen,
                    loanStatus = :status,
                    updatedAt = NOW()
                WHERE activeLoanID = :id
            ");
            $upStmt->execute([
                ':pb' => $principalAfter,
                ':ai' => $accruedInterestAfter,
                ':bui' => $newBilled,
                ':pen' => $newPenalty,
                ':status' => $newStatus,
                ':id' => $activeLoanId,
            ]);

            // Insert REPAYMENT into loan_transaction
            $txStmt = $pdo->prepare("
                INSERT INTO loan_transaction 
                (activeLoanID, repayment_account, mpesaReceiptNumber, transactionType, transactionDate,
                 totalAmount, allocatedPenalty, allocatedBilledInterest, allocatedAccruedInterest, allocatedPrincipal,
                 principalAfter, accruedInterestAfter)
                VALUES 
                (:active_id, :account, :receipt, 'REPAYMENT', :tx_date,
                 :total, :pen, :billed, :accrued, :principal,
                 :pb_after, :ai_after)
            ");
            $txStmt->execute([
                ':active_id' => $activeLoanId,
                ':account' => $repaymentAccount,
                ':receipt' => $mpesaReceiptNumber,
                ':tx_date' => $transactionDate,
                ':total' => $amount,
                ':pen' => $payPenalty,
                ':billed' => $payBilled,
                ':accrued' => $payAccrued,
                ':principal' => $payPrincipal,
                ':pb_after' => $principalAfter,
                ':ai_after' => $accruedInterestAfter,
            ]);

            $memberStmt = $pdo->prepare('SELECT MemberID, FirstName, LastName, PrimaryNumber FROM members WHERE MemberID = :member_id LIMIT 1');
            $memberStmt->execute([':member_id' => $loan['memberID']]);
            $member = $memberStmt->fetch();
            if (!$member) {
                throw new RuntimeException('Loan repayment member could not be found.');
            }

            $ledgerTranId = $mpesaReceiptNumber ?: ('LOAN-' . $activeLoanId . '-' . $pdo->lastInsertId());
            $ledgerStmt = $pdo->prepare("INSERT INTO member_transactions
                (TranID, MemberID, AccountNumber, FirstName, LastName, MSISDN, Amount, TransactionType, TransactionCategory, Reference, Description, TranTime)
                VALUES (:tran_id, :member_id, :account_number, :first_name, :last_name, :phone, :amount, 'loan_repayment', 'loan_repayment', :reference, :description, :tran_time)");
            $ledgerStmt->execute([
                ':tran_id' => $ledgerTranId,
                ':member_id' => $member['MemberID'],
                ':account_number' => $repaymentAccount,
                ':first_name' => $member['FirstName'],
                ':last_name' => $member['LastName'],
                ':phone' => $member['PrimaryNumber'],
                ':amount' => $amount,
                ':reference' => $mpesaReceiptNumber ?: $ledgerTranId,
                ':description' => 'Loan repayment for account ' . $repaymentAccount,
                ':tran_time' => $transactionDate . ' 00:00:00',
            ]);

            if (!$wasInTx) {
                $pdo->commit();
            }

            // Send repayment confirmation SMS
            if (!$wasInTx && $member && !empty($member['PrimaryNumber'])) {
                $totalOwedAfter = round($principalAfter + $newBilled + $accruedInterestAfter + $newPenalty, 2);
                $paymentDate = date('d M Y', strtotime($transactionDate));
                $portalUrl = 'https://mashirikianosacco.co.ke/member/dashboard.php?tab=active';
                $sms = "Dear {$member['FirstName']}, your loan repayment of KES " . number_format($amount, 2) . " was received on {$paymentDate} (Ref: " . ($mpesaReceiptNumber ?: $repaymentAccount) . "). Remaining principal: KES " . number_format($principalAfter, 2) . ". Total payoff balance: KES " . number_format($totalOwedAfter, 2) . ". Status: {$newStatus}. Check your loan status: {$portalUrl}";
                SmsService::sendSms($member['PrimaryNumber'], $sms);
            }

            return [
                'activeLoanID' => $activeLoanId,
                'memberID' => $loan['memberID'],
                'repayment_account' => $repaymentAccount,
                'totalAmount' => $amount,
                'allocatedPenalty' => $payPenalty,
                'allocatedBilledInterest' => $payBilled,
                'allocatedAccruedInterest' => $payAccrued,
                'allocatedPrincipal' => $payPrincipal,
                'principalAfter' => $principalAfter,
                'accruedInterestAfter' => $accruedInterestAfter,
                'newStatus' => $newStatus,
                'overpayment' => $cash,
            ];
        } catch (Throwable $e) {
            if (!$wasInTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * ARREARS & NIGHTLY EOD BATCH JOBS
     * Checks past-due repayment schedules, flags loans as 'IN_ARREARS',
     * and shifts unpaid rolling interest from accruedInterest into billedUnpaidInterest.
     */
    public static function runEndOfDayBatch(PDO $pdo): array
    {
        self::ensureTables($pdo);

        $todayStr = date('Y-m-d');
        $stmt = $pdo->query("SELECT * FROM active_loan WHERE loanStatus IN ('ACTIVE', 'IN_ARREARS')");
        $loans = $stmt->fetchAll();

        $processedCount = 0;
        $flaggedArrearsCount = 0;
        $shiftedInterestTotal = 0.00;

        foreach ($loans as $loan) {
            $loanId = (int)$loan['activeLoanID'];

            // 1. Daily accrual catch up
            $loan = self::catchUpInterest($pdo, $loanId);
            $processedCount++;

            // 2. Check if past-due
            $isPastDue = ($todayStr > $loan['returnDate'] && (float)$loan['principalBalance'] > 0);
            if ($isPastDue && $loan['loanStatus'] !== 'IN_ARREARS') {
                $upArrears = $pdo->prepare("UPDATE active_loan SET loanStatus = 'IN_ARREARS', updatedAt = NOW() WHERE activeLoanID = :id");
                $upArrears->execute([':id' => $loanId]);
                $flaggedArrearsCount++;
                $loan['loanStatus'] = 'IN_ARREARS';
            }

            // 3. Shift rolling accruedInterest into billedUnpaidInterest if overdue or on monthly milestones
            $accrued = (float)$loan['accruedInterest'];
            if ($accrued > 0 && ($isPastDue || date('d') === '01')) {
                $newBilled = round((float)$loan['billedUnpaidInterest'] + $accrued, 2);
                $shiftStmt = $pdo->prepare("
                    UPDATE active_loan 
                    SET billedUnpaidInterest = :bui, accruedInterest = 0.00, updatedAt = NOW() 
                    WHERE activeLoanID = :id
                ");
                $shiftStmt->execute([
                    ':bui' => $newBilled,
                    ':id' => $loanId,
                ]);
                $shiftedInterestTotal += $accrued;
            }
        }

        return [
            'processed' => $processedCount,
            'flagged_in_arrears' => $flaggedArrearsCount,
            'shifted_interest' => $shiftedInterestTotal,
            'batch_date' => $todayStr,
        ];
    }

    /**
     * Top Executive KPI Summary Cards & Analytics Data
     */
    public static function getPortfolioAnalytics(PDO $pdo): array
    {
        self::ensureTables($pdo);

        // 1. Total Principal Disbursed
        $disbursed = (float)$pdo->query("
            SELECT COALESCE(SUM(originalPrincipal), 0) FROM active_loan
        ")->fetchColumn();

        // 2. Total Outstanding Principal Balance
        $outstanding = (float)$pdo->query("
            SELECT COALESCE(SUM(principalBalance), 0) FROM active_loan WHERE loanStatus != 'CLOSED'
        ")->fetchColumn();

        // 3. Total Interest & Penalties Collected
        $collected = (float)$pdo->query("
            SELECT COALESCE(SUM(allocatedPenalty + allocatedBilledInterest + allocatedAccruedInterest), 0) 
            FROM loan_transaction WHERE transactionType = 'REPAYMENT'
        ")->fetchColumn();

        // Total Principal Repaid
        $principalRepaid = (float)$pdo->query("
            SELECT COALESCE(SUM(allocatedPrincipal), 0) FROM loan_transaction WHERE transactionType = 'REPAYMENT'
        ")->fetchColumn();

        // 4. Portfolio at Risk (PAR) / Arrears Rate
        $activeTotal = (int)$pdo->query("
            SELECT COUNT(*) FROM active_loan WHERE loanStatus IN ('ACTIVE', 'IN_ARREARS')
        ")->fetchColumn();

        $arrearsCount = (int)$pdo->query("
            SELECT COUNT(*) FROM active_loan WHERE loanStatus = 'IN_ARREARS'
        ")->fetchColumn();

        $arrearsRate = ($activeTotal > 0) ? round(($arrearsCount / $activeTotal) * 100, 2) : 0.00;

        // Loan Status Distribution
        $statusStmt = $pdo->query("
            SELECT loanStatus, COUNT(*) as cnt FROM active_loan GROUP BY loanStatus
        ");
        $statusRows = $statusStmt->fetchAll();
        $statusDist = ['ACTIVE' => 0, 'IN_ARREARS' => 0, 'CLOSED' => 0];
        foreach ($statusRows as $row) {
            $statusDist[$row['loanStatus']] = (int)$row['cnt'];
        }

        // Borrowing by Loan Product
        $productStmt = $pdo->query("
            SELECT la.loanType, COUNT(al.activeLoanID) as loan_count, COALESCE(SUM(al.originalPrincipal), 0) as total_principal
            FROM active_loan al
            JOIN loan_application la ON la.loanApplicationID = al.loanApplicationID
            GROUP BY la.loanType
        ");
        $productStats = $productStmt->fetchAll();

        // Monthly Disbursements vs Repayments (Last 6 months)
        $monthlyDisb = [];
        $monthlyRepay = [];
        $monthLabels = [];

        for ($i = 5; $i >= 0; $i--) {
            $monthStr = date('Y-m', strtotime("-$i months"));
            $monthLabels[] = date('M Y', strtotime("-$i months"));

            // Disbursement in month
            $dStmt = $pdo->prepare("
                SELECT COALESCE(SUM(totalAmount), 0) FROM loan_transaction 
                WHERE transactionType = 'DISBURSEMENT' AND DATE_FORMAT(transactionDate, '%Y-%m') = :m
            ");
            $dStmt->execute([':m' => $monthStr]);
            $monthlyDisb[] = (float)$dStmt->fetchColumn();

            // Repayment in month
            $rStmt = $pdo->prepare("
                SELECT COALESCE(SUM(totalAmount), 0) FROM loan_transaction 
                WHERE transactionType = 'REPAYMENT' AND DATE_FORMAT(transactionDate, '%Y-%m') = :m
            ");
            $rStmt->execute([':m' => $monthStr]);
            $monthlyRepay[] = (float)$rStmt->fetchColumn();
        }

        return [
            'total_principal_disbursed' => $disbursed,
            'total_outstanding_principal' => $outstanding,
            'total_interest_penalties_collected' => $collected,
            'total_principal_repaid' => $principalRepaid,
            'arrears_rate' => $arrearsRate,
            'active_loans_count' => $activeTotal,
            'arrears_loans_count' => $arrearsCount,
            'status_distribution' => $statusDist,
            'product_stats' => $productStats,
            'chart_months' => $monthLabels,
            'chart_disbursements' => $monthlyDisb,
            'chart_repayments' => $monthlyRepay,
        ];
    }
}

