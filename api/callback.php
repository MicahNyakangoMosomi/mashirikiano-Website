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
        // Net savings: query the actual ledger (member_transactions)
        // Net Savings = Total Contributions − Total Withdrawals
        // One query covers all three types in a single round-trip.
        // Use MemberID (already resolved) when available, fall back
        // to NationalID for unlinked / unregistered members.
        // -------------------------------------------------------
        $memberId = $result['member_id'] ?? null;

        if ($memberId !== null) {
            // Preferred: look up by MemberID — exact, no ambiguity
            $savingsStmt = $pdo->prepare(
                "SELECT
                    TransactionType,
                    SUM(Amount) AS total
                 FROM member_transactions
                 WHERE MemberID = :member_id
                   AND TransactionType IN ('contribution', 'withdrawal')
                 GROUP BY TransactionType"
            );
            $savingsStmt->execute([':member_id' => $memberId]);
        } else {
            // Fallback: unregistered sender — use NationalID
            $savingsStmt = $pdo->prepare(
                "SELECT
                    TransactionType,
                    SUM(Amount) AS total
                 FROM member_transactions
                 WHERE NationalID = :national_id
                   AND TransactionType IN ('contribution', 'withdrawal')
                 GROUP BY TransactionType"
            );
            $savingsStmt->execute([':national_id' => $nationalId]);
        }

        $totalContribution = 0.00;
        $totalWithdrawals  = 0.00;
        foreach ($savingsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['TransactionType'] === 'contribution') {
                $totalContribution = (float)$row['total'];
            } elseif ($row['TransactionType'] === 'withdrawal') {
                $totalWithdrawals = (float)$row['total'];
            }
        }

        // Net savings = Total Contributions − Total Withdrawals
        $netSavings = $totalContribution - $totalWithdrawals;

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
            $allocation[] = 'Contribution KES ' . number_format($contributionAmount, 2);
        }
        $allocationText = $allocation ? ' Allocation: ' . implode(', ', $allocation) . '.' : '';

        $tranDate = !empty($data['TranTime']) ? date('d-M-Y', strtotime($data['TranTime'])) : date('d-M-Y');
        $smsMessage = "Confirmed. KES {$amount} received from {$fullName} (ID {$nationalId}) Ref {$tranId} on {$tranDate}.{$allocationText} Total Contributions: KES " . number_format($totalContribution, 2) . ". Net Savings: KES " . number_format($netSavings, 2) . ". For queries call 0758500557 or email support@mashirikianosacco.co.ke.";
        
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
