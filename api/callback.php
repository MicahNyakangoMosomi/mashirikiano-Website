<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/Mpesa.php';

/**
 * -------------------------------------------------
 * M-PESA C2B CONFIRMATION CALLBACK ENDPOINT
 * -------------------------------------------------
 * - Receives transaction data from Safaricom
 * - Logs raw payload for debugging
 * - Processes and stores transaction
 * - Always responds with HTTP 200
 * -------------------------------------------------
 */

header('Content-Type: application/json');

/**
 * 1. Read raw POST payload from Safaricom
 */
$rawPayload = file_get_contents('php://input') ?: '';

/**
 * 2. Setup logging directory
 */
$logDir = __DIR__ . '/../logs';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

/**
 * 3. Log raw callback payload (for debugging / audit)
 */
file_put_contents(
    $logDir . '/mpesa-c2b-callbacks.log',
    '[' . date('c') . '] ' . $rawPayload . PHP_EOL,
    FILE_APPEND
);

try {

    /**
     * 4. Validate payload existence
     */
    if ($rawPayload === '') {
        throw new RuntimeException("Empty callback payload received");
    }

    /**
     * 5. Decode JSON payload safely
     */
    $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($payload)) {
        throw new RuntimeException("Invalid callback payload format");
    }

    /**
     * 6. Process transaction via Mpesa service layer
     */
    $result = Mpesa::recordC2BCallback($payload);

    if (isset($result['status']) && $result['status'] === 'recorded' && isset($result['data'])) {
        $data = $result['data'];
        $amount = number_format($data['Amount'], 2);
        $sender_fullName = trim($data['FirstName'] . ' ' . $data['LastName']);
        $nationalId = $data['NationalID'];
        $tranId = $data['TranID'];
        $tranTime = $data['TranTime'] ?? date('Y-m-d H:i:s');

        // Fetch member phone number using NationalID for SMS notification
        $pdo = Database::connection();
        // Use prepared statement to prevent SQL injection to get member phone number and FirstName and LastName where NationalID = $nationalId
        $memberStmt = $pdo->prepare('SELECT PrimaryNumber, FirstName, LastName FROM members WHERE NationalID = :national_id LIMIT 1');

        // Execute the statement with the provided national ID
        $memberStmt->execute([':national_id' => $nationalId]);
        // place a varibale of user number, and name(first and last name) and combine them to a full name


        // Fetch the member data and extract phone number and full name
        $memberData = $memberStmt->fetch(PDO::FETCH_ASSOC);
        $memberPhone = trim((string)($memberData['PrimaryNumber'] ?? ''));
        $memberFirstName = trim((string)($memberData['FirstName'] ?? ''));
        $memberLastName = trim((string)($memberData['LastName'] ?? ''));
        $fullName = trim($memberFirstName . ' ' . $memberLastName);

        
        // -------------------------------------------------------
        // Wallet balances: query SaccoWallets (Akiba & Ushirika)
        // and withdrawals table for cash releases.
        // -------------------------------------------------------
        $memberId = $result['member_id'] ?? null;
        $totalContribution = 0.00;
        $totalAkiba = 0.00;
        $totalUshirika = 0.00;
        $totalWithdrawals = 0.00;

        try {
            if ($memberId !== null) {
                $walletStmt = $pdo->prepare(
                    "SELECT
                        COALESCE(SUM(Amount), 0) AS total_contrib,
                        COALESCE(SUM(AkibaAmount), 0) AS total_akiba,
                        COALESCE(SUM(UshirikaAmount), 0) AS total_ushirika
                     FROM SaccoWallets
                     WHERE MemberID = :member_id"
                );
                $walletStmt->execute([':member_id' => $memberId]);
                $wTotals = $walletStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $wWithdStmt = $pdo->prepare("SELECT COALESCE(SUM(Amount), 0) FROM withdrawals WHERE MemberID = :member_id");
                $wWithdStmt->execute([':member_id' => $memberId]);
                $totalWithdrawals = (float)$wWithdStmt->fetchColumn();
            } else {
                $walletStmt = $pdo->prepare(
                    "SELECT
                        COALESCE(SUM(Amount), 0) AS total_contrib,
                        COALESCE(SUM(AkibaAmount), 0) AS total_akiba,
                        COALESCE(SUM(UshirikaAmount), 0) AS total_ushirika
                     FROM SaccoWallets
                     WHERE NationalID = :national_id"
                );
                $walletStmt->execute([':national_id' => $nationalId]);
                $wTotals = $walletStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            }
            $totalContribution = (float)($wTotals['total_contrib'] ?? 0);
            $totalAkiba = (float)($wTotals['total_akiba'] ?? 0);
            $totalUshirika = (float)($wTotals['total_ushirika'] ?? 0);
        } catch (Throwable $e) {}

        // Fallback if SaccoWallets was empty
        if ($totalContribution <= 0) {
            if ($memberId !== null) {
                $savingsStmt = $pdo->prepare("SELECT TransactionType, SUM(Amount) AS total FROM member_transactions WHERE MemberID = :member_id AND TransactionType IN ('contribution', 'withdrawal') GROUP BY TransactionType");
                $savingsStmt->execute([':member_id' => $memberId]);
            } else {
                $savingsStmt = $pdo->prepare("SELECT TransactionType, SUM(Amount) AS total FROM member_transactions WHERE NationalID = :national_id AND TransactionType IN ('contribution', 'withdrawal') GROUP BY TransactionType");
                $savingsStmt->execute([':national_id' => $nationalId]);
            }
            foreach ($savingsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['TransactionType'] === 'contribution') {
                    $totalContribution = (float)$row['total'];
                    $totalAkiba = round($totalContribution * 0.95, 2);
                    $totalUshirika = round($totalContribution * 0.05, 2);
                } elseif ($row['TransactionType'] === 'withdrawal' && $totalWithdrawals <= 0) {
                    $totalWithdrawals = (float)$row['total'];
                }
            }
        }

        // Akiba Wallet = Total Akiba minus Total Withdrawals
        $akibaWallet = max(0.00, $totalAkiba - $totalWithdrawals);
        $netSavings = $akibaWallet;

        $segments = $result['segments'] ?? [];
        $depositAmount = 0.00;
        $contributionAmount = 0.00;
        foreach ($segments as $segment) {
            if (($segment['type'] ?? '') === 'deposit') {
                $depositAmount += (float)$segment['amount'];
            }
            if (($segment['type'] ?? '') === 'contribution') {
                $contributionAmount += (float)$segment['amount'];
            }
        }

        $allocation = [];
        if ($depositAmount > 0) {
            $allocation[] = 'Deposit KES ' . number_format($depositAmount, 2);
        }
        if ($contributionAmount > 0) {
            $ushirikaCut = round($contributionAmount * 0.05, 2);
            $akibaCut = round($contributionAmount - $ushirikaCut, 2);
            $allocation[] = 'Akiba (95%) KES ' . number_format($akibaCut, 2);
            $allocation[] = 'Ushirika (5%) KES ' . number_format($ushirikaCut, 2);
        }

        // $allocationText = $allocation ? ' Allocation: ' . implode(', ', $allocation) . '.' : '';

        $tranDate = !empty($data['TranTime']) ? date('d-M-Y', strtotime($data['TranTime'])) : date('d-M-Y');
        $smsMessage = "Confirmed. KES {$amount} credited to {$fullName} (ID {$nationalId}) Ref {$tranId} on {$tranDate}. Total savings: KES " . number_format($akibaWallet, 2) . ". Shares: KES " . number_format($totalUshirika, 2) . ". For queries call 0758500557.";
        
        require_once __DIR__ . '/../classes/SmsService.php';
        if ($memberPhone !== '') {
            SmsService::sendSms($memberPhone, $smsMessage);
        } else {
            file_put_contents(
                $logDir . '/mpesa-c2b-errors.log',
                '[' . date('c') . '] SMS skipped: no member phone found for NationalID ' . $nationalId . ' on transaction ' . $tranId . PHP_EOL,
                FILE_APPEND
            );
        }
    }

    /**
     * 7. Return success response to Safaricom
     * IMPORTANT: Always HTTP 200
     */
    http_response_code(200);

    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => $result['message'] ?? 'Accepted'
    ]);

} catch (Throwable $e) {

    /**
     * 8. Log error separately
     */
    file_put_contents(
        $logDir . '/mpesa-c2b-errors.log',
        '[' . date('c') . '] ' . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );

    require_once __DIR__ . '/../classes/SystemLogger.php';
    SystemLogger::error('mpesa', 'M-Pesa callback error: ' . $e->getMessage(), [
        'payload' => $rawPayload,
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);

    /**
     * 9. STILL return HTTP 200 (important for Safaricom)
     * We do NOT want repeated retries due to HTTP errors
     */
    http_response_code(200);

    echo json_encode([
        'ResultCode' => 1,
        'ResultDesc' => 'Transaction processing failed'
    ]);
}
