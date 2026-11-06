<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/MemberService.php';
require_once __DIR__ . '/../classes/SmsService.php';
require_once __DIR__ . '/admin_layout.php';

Auth::requireAdmin();
Auth::startSession();

$pdo = Database::connection();
$message = '';
$messageType = 'info';

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_message_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
}

$adminRole = Auth::adminRole();
$adminUserId = $_SESSION['admin_user_id'] ?? null;
$adminUsername = $_SESSION['admin_username'] ?? 'System Admin';

// Fetch current admin's full name if available
$adminName = $adminUsername;
if ($adminUserId) {
    try {
        $aStmt = $pdo->prepare("SELECT FullName, Username FROM admin_users WHERE AdminUserID = :uid LIMIT 1");
        $aStmt->execute([':uid' => $adminUserId]);
        $aUser = $aStmt->fetch();
        if ($aUser) {
            $adminName = !empty($aUser['FullName']) ? $aUser['FullName'] : $aUser['Username'];
        }
    } catch (Throwable $e) {}
}

// Self-healing check for credit_transfers table
try {
    $pdo->query("SELECT 1 FROM `credit_transfers` LIMIT 1");
} catch (Throwable $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `credit_transfers` (
      `TransferID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `TransferCode` VARCHAR(60) NOT NULL,
      `MemberID` VARCHAR(40) NOT NULL,
      `NationalID` VARCHAR(30) NOT NULL,
      `TransferFrom` ENUM('akiba', 'ushirika') NOT NULL,
      `TransferTo` ENUM('akiba', 'ushirika') NOT NULL,
      `Amount` DECIMAL(12,2) NOT NULL,
      `PreviousAkiba` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `PreviousUshirika` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `NewAkiba` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `NewUshirika` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `AdminUserID` INT UNSIGNED NULL,
      `AdminName` VARCHAR(100) NULL,
      `Notes` VARCHAR(255) NULL,
      `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`TransferID`),
      UNIQUE KEY `uniq_credit_transfers_code` (`TransferCode`),
      KEY `idx_credit_transfers_member` (`MemberID`),
      KEY `idx_credit_transfers_national` (`NationalID`),
      KEY `idx_credit_transfers_admin` (`AdminUserID`),
      KEY `idx_credit_transfers_created` (`CreatedAt`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
}

// Self-healing check for SaccoWallets
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

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Handle Credit Transfer Processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_credit_transfer'])) {
    try {
        $memberId = trim($_POST['member_id'] ?? '');
        $transferTo = trim($_POST['transfer_to'] ?? ''); // 'akiba' or 'ushirika'
        $amount = (float)($_POST['amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if (empty($memberId)) {
            throw new Exception("Please select a valid member.");
        }
        if (!in_array($transferTo, ['akiba', 'ushirika'], true)) {
            throw new Exception("Please select a valid transfer destination (Akiba or Ushirika).");
        }
        if ($amount <= 0) {
            throw new Exception("Transfer amount must be greater than zero.");
        }

        $transferFrom = ($transferTo === 'ushirika') ? 'akiba' : 'ushirika';

        $pdo->beginTransaction();

        // 1. Fetch member & lock for update if possible
        $mStmt = $pdo->prepare("SELECT MemberID, NationalID, FirstName, LastName, PrimaryNumber, Status FROM members WHERE MemberID = :mid LIMIT 1 FOR UPDATE");
        $mStmt->execute([':mid' => $memberId]);
        $member = $mStmt->fetch();

        if (!$member) {
            throw new Exception("Member record not found.");
        }
        if ($member['Status'] !== 'Active') {
            throw new Exception("Only active members can have credit transfers processed.");
        }

        // 2. Calculate current balances
        $wStmt = $pdo->prepare("SELECT
            COALESCE(SUM(Amount), 0) AS TotalContributions,
            COALESCE(SUM(AkibaAmount), 0) AS TotalAkiba,
            COALESCE(SUM(UshirikaAmount), 0) AS TotalUshirika
            FROM SaccoWallets
            WHERE MemberID = :mid");
        $wStmt->execute([':mid' => $memberId]);
        $wTotals = $wStmt->fetch() ?: [];

        $totalAkibaFromWallets = (float)($wTotals['TotalAkiba'] ?? 0);
        $totalUshirikaFromWallets = (float)($wTotals['TotalUshirika'] ?? 0);

        // Fallback to member_transactions if SaccoWallets was not yet backfilled for this member
        if ($totalAkibaFromWallets <= 0 && $totalUshirikaFromWallets <= 0) {
            $cStmt = $pdo->prepare("SELECT COALESCE(SUM(Amount), 0) FROM member_transactions WHERE MemberID = :mid AND TransactionType = 'contribution'");
            $cStmt->execute([':mid' => $memberId]);
            $rawContrib = (float)$cStmt->fetchColumn();
            if ($rawContrib > 0) {
                $totalAkibaFromWallets = round($rawContrib * 0.95, 2);
                $totalUshirikaFromWallets = round($rawContrib * 0.05, 2);
            }
        }

        $withdStmt = $pdo->prepare("SELECT COALESCE(SUM(Amount), 0) FROM withdrawals WHERE MemberID = :mid");
        $withdStmt->execute([':mid' => $memberId]);
        $totalWithdrawals = (float)$withdStmt->fetchColumn();

        $currentAkiba = max(0.00, $totalAkibaFromWallets - $totalWithdrawals);
        $currentUshirika = max(0.00, $totalUshirikaFromWallets);

        // 3. Validate balance limits
        if ($transferFrom === 'akiba') {
            if ($amount > $currentAkiba) {
                throw new Exception("Requested transfer of KES " . number_format($amount, 2) . " exceeds member's available Akiba Wallet balance of KES " . number_format($currentAkiba, 2) . ".");
            }
            $newAkiba = round($currentAkiba - $amount, 2);
            $newUshirika = round($currentUshirika + $amount, 2);
            $walletAkibaDelta = -$amount;
            $walletUshirikaDelta = +$amount;
            $directionLabel = "Akiba Wallet to Ushirika Shares";
        } else {
            if ($amount > $currentUshirika) {
                throw new Exception("Requested transfer of KES " . number_format($amount, 2) . " exceeds member's available Ushirika Shares balance of KES " . number_format($currentUshirika, 2) . ".");
            }
            $newAkiba = round($currentAkiba + $amount, 2);
            $newUshirika = round($currentUshirika - $amount, 2);
            $walletAkibaDelta = +$amount;
            $walletUshirikaDelta = -$amount;
            $directionLabel = "Ushirika Shares to Akiba Wallet";
        }

        $transferCode = 'TR-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 10));
        $now = date('Y-m-d H:i:s');
        $desc = "Credit transfer of KES " . number_format($amount, 2) . " from {$directionLabel}";
        if ($notes !== '') {
            $desc .= " (" . $notes . ")";
        }

        // 4. Record in credit_transfers audit table
        $ctStmt = $pdo->prepare("INSERT INTO credit_transfers
            (TransferCode, MemberID, NationalID, TransferFrom, TransferTo, Amount, PreviousAkiba, PreviousUshirika, NewAkiba, NewUshirika, AdminUserID, AdminName, Notes, CreatedAt)
            VALUES
            (:code, :mid, :nid, :from, :to, :amount, :prev_akiba, :prev_ushirika, :new_akiba, :new_ushirika, :admin_id, :admin_name, :notes, :created_at)");
        $ctStmt->execute([
            ':code' => $transferCode,
            ':mid' => $memberId,
            ':nid' => $member['NationalID'],
            ':from' => $transferFrom,
            ':to' => $transferTo,
            ':amount' => $amount,
            ':prev_akiba' => $currentAkiba,
            ':prev_ushirika' => $currentUshirika,
            ':new_akiba' => $newAkiba,
            ':new_ushirika' => $newUshirika,
            ':admin_id' => $adminUserId,
            ':admin_name' => $adminName,
            ':notes' => $notes ?: null,
            ':created_at' => $now,
        ]);

        // 5. Record ledger transaction in member_transactions
        $ledgerStmt = $pdo->prepare("INSERT INTO member_transactions
            (TranID, MemberID, NationalID, FirstName, LastName, MSISDN, Amount, TransactionType, TransactionCategory, Reference, Description, TranTime)
            VALUES
            (:tran_id, :member_id, :national_id, :first_name, :last_name, :msisdn, :amount, 'contribution', 'credit_transfer', :reference, :description, :tran_time)");
        $ledgerStmt->execute([
            ':tran_id' => $transferCode,
            ':member_id' => $memberId,
            ':national_id' => $member['NationalID'],
            ':first_name' => $member['FirstName'],
            ':last_name' => $member['LastName'],
            ':msisdn' => $member['PrimaryNumber'],
            ':amount' => $amount,
            ':reference' => $transferCode,
            ':description' => $desc,
            ':tran_time' => $now,
        ]);
        $transactionId = (int)$pdo->lastInsertId();

        // 6. Record wallet shift in SaccoWallets
        // AkibaAmount and UshirikaAmount offset each other exactly so gross SACCO amount = 0
        $walletStmt = $pdo->prepare("INSERT INTO SaccoWallets
            (TransactionID, TranID, MemberID, NationalID, Amount, AkibaAmount, UshirikaAmount, CreatedAt)
            VALUES
            (:tx_id, :tran_id, :member_id, :national_id, :amount, :akiba, :ushirika, :created_at)");
        $walletStmt->execute([
            ':tx_id' => $transactionId,
            ':tran_id' => $transferCode,
            ':member_id' => $memberId,
            ':national_id' => $member['NationalID'],
            ':amount' => 0.00,
            ':akiba' => $walletAkibaDelta,
            ':ushirika' => $walletUshirikaDelta,
            ':created_at' => $now,
        ]);

        $pdo->commit();

        // 7. Send SMS notification to member
        $fullName = trim($member['FirstName'] . ' ' . $member['LastName']);
        $fromNameLabel = ($transferFrom === 'akiba') ? 'Akiba Wallet' : 'Ushirika Shares';
        $toNameLabel = ($transferTo === 'akiba') ? 'Akiba Wallet' : 'Ushirika Shares';

        $smsMsg = "Dear {$member['FirstName']}, a credit transfer of KES " . number_format($amount, 2) . " from your {$fromNameLabel} to {$toNameLabel} has been processed successfully (Ref: {$transferCode}). Current Akiba Wallet: KES " . number_format($newAkiba, 2) . ", Ushirika Shares: KES " . number_format($newUshirika, 2) . ". Mashirikiano SACCO.";
        SmsService::sendSms($member['PrimaryNumber'], $smsMsg);

        $_SESSION['flash_message'] = "Credit transfer of KES " . number_format($amount, 2) . " for " . e($fullName) . " ({$memberId}) processed successfully!";
        $_SESSION['flash_message_type'] = "success";

        header("Location: credit_transfer.php");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_message'] = $e->getMessage();
        $_SESSION['flash_message_type'] = 'danger';
        header("Location: credit_transfer.php");
        exit;
    }
}

// Filtering & Pagination for Active Members
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;

$whereClause = "WHERE m.Status = 'Active'";
$params = [];

if ($search !== '') {
    $whereClause .= " AND (m.MemberID LIKE :search OR m.FirstName LIKE :search OR m.LastName LIKE :search OR m.NationalID LIKE :search OR CONCAT(m.FirstName, ' ', m.LastName) LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM members m {$whereClause}");
$countStmt->execute($params);
$totalActiveMembers = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalActiveMembers / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

// Fetch members with live Akiba and Ushirika amounts
$membersStmt = $pdo->prepare("
    SELECT m.*,
        COALESCE(sw.TotalContributions, c.TotalContributions, 0) AS TotalContributions,
        COALESCE(sw.TotalAkiba, c.TotalContributions * 0.95, 0) AS TotalAkiba,
        COALESCE(sw.TotalUshirika, c.TotalContributions * 0.05, 0) AS TotalUshirika,
        COALESCE(w.TotalWithdrawals, 0) AS TotalWithdrawals,
        GREATEST(0.00, COALESCE(sw.TotalAkiba, c.TotalContributions * 0.95, 0) - COALESCE(w.TotalWithdrawals, 0)) AS CurrentAkiba,
        GREATEST(0.00, COALESCE(sw.TotalUshirika, c.TotalContributions * 0.05, 0)) AS CurrentUshirika
    FROM members m
    LEFT JOIN (
        SELECT MemberID,
               SUM(Amount) AS TotalContributions,
               SUM(AkibaAmount) AS TotalAkiba,
               SUM(UshirikaAmount) AS TotalUshirika
        FROM SaccoWallets
        WHERE MemberID IS NOT NULL
        GROUP BY MemberID
    ) sw ON sw.MemberID = m.MemberID
    LEFT JOIN (
        SELECT MemberID, SUM(Amount) AS TotalContributions
        FROM member_transactions
        WHERE TransactionType = 'contribution' AND MemberID IS NOT NULL
        GROUP BY MemberID
    ) c ON c.MemberID = m.MemberID
    LEFT JOIN (
        SELECT MemberID, SUM(Amount) AS TotalWithdrawals
        FROM withdrawals
        WHERE MemberID IS NOT NULL
        GROUP BY MemberID
    ) w ON w.MemberID = m.MemberID
    {$whereClause}
    ORDER BY m.FirstName ASC, m.LastName ASC
    LIMIT {$limit} OFFSET {$offset}
");
$membersStmt->execute($params);
$activeMembers = $membersStmt->fetchAll();

// Summary stats
$summaryStats = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM members WHERE Status = 'Active') AS ActiveCount,
        (SELECT COALESCE(SUM(AkibaAmount), 0) FROM SaccoWallets) AS TotalAkibaPool,
        (SELECT COALESCE(SUM(UshirikaAmount), 0) FROM SaccoWallets) AS TotalUshirikaPool,
        (SELECT COALESCE(SUM(Amount), 0) FROM withdrawals) AS TotalWithdrawals,
        (SELECT COUNT(*) FROM credit_transfers) AS TotalTransfersCount,
        (SELECT COALESCE(SUM(Amount), 0) FROM credit_transfers) AS TotalTransferredAmount
")->fetch();

$activeCount = (int)($summaryStats['ActiveCount'] ?? 0);
$totalWithdrawalsAll = (float)($summaryStats['TotalWithdrawals'] ?? 0);
$allAkibaPool = max(0.00, (float)($summaryStats['TotalAkibaPool'] ?? 0) - $totalWithdrawalsAll);
$allUshirikaPool = (float)($summaryStats['TotalUshirikaPool'] ?? 0);
$totalTransfersCount = (int)($summaryStats['TotalTransfersCount'] ?? 0);
$totalTransferredAmount = (float)($summaryStats['TotalTransferredAmount'] ?? 0);

// Recent credit transfers history
$historyStmt = $pdo->query("
    SELECT ct.*, m.FirstName, m.LastName
    FROM credit_transfers ct
    JOIN members m ON m.MemberID = ct.MemberID
    ORDER BY ct.CreatedAt DESC
    LIMIT 8
");
$recentTransfers = $historyStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Credit Transfer | Mashirikiano SACCO Admin</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <style>
    body { background-color: #f4f6f9; font-family: system-ui, -apple-system, sans-serif; }
    .card-metric { border: none; border-radius: 10px; transition: transform 0.2s; }
    .card-metric:hover { transform: translateY(-2px); }
    .table-custom th { background-color: #f8f9fa; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .table-custom td { vertical-align: middle; }
    .modal-header-transfer { background: linear-gradient(135deg, #1d72d2 0%, #0d47a1 100%); color: white; }
    .badge-akiba { background-color: #e7f5ed; color: #087a43; border: 1px solid #c3e6cb; }
    .badge-ushirika { background-color: #f3e8ff; color: #7c3aed; border: 1px solid #ddd6fe; }
    .transfer-arrow { color: #64748b; font-size: 1.1rem; }
  </style>
</head>
<body>

  <?php admin_header('credit_transfer', 'Internal Wallet Credit Transfer'); ?>

  <main class="container-fluid admin-shell py-4">

    <!-- Flash Message -->
    <?php if ($message): ?>
      <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show shadow-sm" role="alert">
        <i class="bi <?= $messageType === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
        <?= e($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Summary Metrics -->
    <div class="row g-3 mb-4">
      <div class="col-md-3">
        <div class="card card-metric shadow-sm bg-white p-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small text-uppercase fw-bold">Active Members</div>
              <div class="h3 fw-bold mb-0 text-primary"><?= number_format($activeCount) ?></div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-metric shadow-sm bg-white p-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small text-uppercase fw-bold">Akiba Wallet Pool</div>
              <div class="h3 fw-bold mb-0 text-success">KES <?= number_format($allAkibaPool, 2) ?></div>
              <div class="small text-muted" style="font-size:0.75rem;">Everyday accessible savings</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-metric shadow-sm bg-white p-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small text-uppercase fw-bold">Ushirika Shares Pool</div>
              <div class="h3 fw-bold mb-0" style="color:#7c3aed;">KES <?= number_format($allUshirikaPool, 2) ?></div>
              <div class="small text-muted" style="font-size:0.75rem;">Retained member capital shares</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-metric shadow-sm bg-white p-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small text-uppercase fw-bold">Transfers Completed</div>
              <div class="h3 fw-bold mb-0 text-dark"><?= number_format($totalTransfersCount) ?></div>
              <div class="small text-muted" style="font-size:0.75rem;">KES <?= number_format($totalTransferredAmount, 2) ?> moved</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Active Members Section (Rows of Users) -->
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between gap-3 border-bottom">
        <div>
          <h2 class="h5 mb-0 fw-bold text-dark">Member Wallet Accounts</h2>
          <div class="text-muted small">Select a member to transfer balances between Akiba Wallet and Ushirika Shares.</div>
        </div>
        <form class="d-flex gap-2" method="GET" action="credit_transfer.php">
          <div class="input-group input-group-sm">
            <input type="text" name="search" class="form-control" placeholder="Search by name, ID or phone..." value="<?= e($search) ?>">
            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i> Search</button>
            <?php if ($search !== ''): ?>
              <a href="credit_transfer.php" class="btn btn-outline-secondary" title="Clear Search"><i class="bi bi-x-circle"></i></a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover table-custom align-middle mb-0">
            <thead>
              <tr>
                <th class="ps-3">Member ID</th>
                <th>Full Name</th>
                <th>National ID</th>
                <th>Phone Number</th>
                <th class="text-end">Current Akiba</th>
                <th class="text-end">Current Ushirika</th>
                <th class="text-center pe-3">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($activeMembers)): ?>
                <tr>
                  <td colspan="7" class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                    No active members found matching criteria.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($activeMembers as $m): ?>
                  <?php
                    $mId = e($m['MemberID']);
                    $mName = e(trim($m['FirstName'] . ' ' . $m['LastName']));
                    $mNatId = e($m['NationalID']);
                    $mPhone = e($m['PrimaryNumber']);
                    $akiba = (float)$m['CurrentAkiba'];
                    $ushirika = (float)$m['CurrentUshirika'];
                  ?>
                  <tr>
                    <td class="ps-3 fw-semibold text-primary"><?= $mId ?></td>
                    <td class="fw-bold"><?= $mName ?></td>
                    <td><?= $mNatId ?></td>
                    <td><?= $mPhone ?></td>
                    <td class="text-end text-success font-monospace fw-bold">KES <?= number_format($akiba, 2) ?></td>
                    <td class="text-end font-monospace fw-bold" style="color:#7c3aed;">KES <?= number_format($ushirika, 2) ?></td>
                    <td class="text-center pe-3">
                      <button type="button"
                              class="btn btn-sm btn-primary px-3 shadow-sm btn-transfer-trigger"
                              data-bs-toggle="modal"
                              data-bs-target="#transferModal"
                              data-member-id="<?= $mId ?>"
                              data-member-name="<?= $mName ?>"
                              data-national-id="<?= $mNatId ?>"
                              data-akiba="<?= number_format($akiba, 2, '.', '') ?>"
                              data-ushirika="<?= number_format($ushirika, 2, '.', '') ?>">
                        <i class="bi bi-arrow-left-right me-1"></i> Transfer
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white py-2 d-flex justify-content-between align-items-center">
          <div class="small text-muted">Showing page <?= $page ?> of <?= $totalPages ?> (Total: <?= $totalActiveMembers ?> members)</div>
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">Previous</a>
              </li>
              <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                  <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                </li>
              <?php endfor; ?>
              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">Next</a>
              </li>
            </ul>
          </nav>
        </div>
      <?php endif; ?>
    </div>

    <!-- Recent Transfer Audit Log -->
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white py-3 border-bottom">
        <h3 class="h6 mb-0 fw-bold text-dark"><i class="bi bi-clock-history text-primary me-2"></i>Recent Credit Transfers Audit Log</h3>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover table-custom align-middle mb-0">
            <thead>
              <tr>
                <th class="ps-3">Ref Code</th>
                <th>Member</th>
                <th>Transfer Path</th>
                <th class="text-end">Amount</th>
                <th>Akiba Before &rarr; After</th>
                <th>Ushirika Before &rarr; After</th>
                <th>Authorized Admin</th>
                <th>Timestamp</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentTransfers)): ?>
                <tr>
                  <td colspan="8" class="text-center py-4 text-muted">No credit transfers have been executed yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentTransfers as $t): ?>
                  <?php $isToUshirika = ($t['TransferTo'] === 'ushirika'); ?>
                  <tr>
                    <td class="ps-3 fw-bold text-primary"><?= e($t['TransferCode']) ?></td>
                    <td>
                      <div class="fw-bold"><?= e(trim($t['FirstName'] . ' ' . $t['LastName'])) ?></div>
                      <div class="small text-muted"><?= e($t['MemberID']) ?> &middot; ID <?= e($t['NationalID']) ?></div>
                    </td>
                    <td>
                      <?php if ($isToUshirika): ?>
                        <span class="badge badge-akiba">Akiba</span>
                        <i class="bi bi-arrow-right transfer-arrow mx-1"></i>
                        <span class="badge badge-ushirika">Ushirika</span>
                      <?php else: ?>
                        <span class="badge badge-ushirika">Ushirika</span>
                        <i class="bi bi-arrow-right transfer-arrow mx-1"></i>
                        <span class="badge badge-akiba">Akiba</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end fw-bold font-monospace text-dark">KES <?= number_format((float)$t['Amount'], 2) ?></td>
                    <td class="font-monospace small">
                      <?= number_format((float)$t['PreviousAkiba'], 2) ?> &rarr; <strong class="text-success"><?= number_format((float)$t['NewAkiba'], 2) ?></strong>
                    </td>
                    <td class="font-monospace small">
                      <?= number_format((float)$t['PreviousUshirika'], 2) ?> &rarr; <strong style="color:#7c3aed;"><?= number_format((float)$t['NewUshirika'], 2) ?></strong>
                    </td>
                    <td class="small text-muted"><?= e($t['AdminName'] ?: 'Admin #' . $t['AdminUserID']) ?></td>
                    <td class="small text-muted"><?= date('d-M-Y H:i', strtotime($t['CreatedAt'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </main>

  <!-- TRANSFER MODAL -->
  <div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg">
        <div class="modal-header modal-header-transfer">
          <h5 class="modal-title fw-bold" id="transferModalLabel">
            <i class="bi bi-arrow-left-right me-2"></i>Transfer Member Credit
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="credit_transfer.php" id="transferForm">
          <input type="hidden" name="process_credit_transfer" value="1">
          <input type="hidden" name="member_id" id="modalMemberId">

          <div class="modal-body p-4">
            <!-- Member Info Banner -->
            <div class="alert alert-light border shadow-sm rounded-3 mb-3">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small">Member Name:</span>
                <span class="fw-bold text-dark" id="modalMemberName">---</span>
              </div>
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small">Member ID:</span>
                <span class="fw-semibold text-primary" id="modalMemberIdDisplay">---</span>
              </div>
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small">National ID:</span>
                <span class="fw-semibold text-secondary" id="modalNationalId">---</span>
              </div>
              <hr class="my-2">
              <div class="row g-2 text-center">
                <div class="col-6">
                  <div class="p-2 border rounded bg-white">
                    <span class="d-block text-muted small">Current Akiba</span>
                    <strong class="text-success font-monospace" id="modalAkibaDisplay">KES 0.00</strong>
                  </div>
                </div>
                <div class="col-6">
                  <div class="p-2 border rounded bg-white">
                    <span class="d-block text-muted small">Current Ushirika</span>
                    <strong style="color:#7c3aed;" class="font-monospace" id="modalUshirikaDisplay">KES 0.00</strong>
                  </div>
                </div>
              </div>
            </div>

            <!-- Transfer Direction Dropdown -->
            <div class="mb-3">
              <label for="transferToSelect" class="form-label fw-bold">Transfer To: <span class="text-danger">*</span></label>
              <select name="transfer_to" id="transferToSelect" class="form-select form-select-lg fw-semibold" required>
                <option value="ushirika" selected>Ushirika Shares (Transfer from Akiba Wallet)</option>
                <option value="akiba">Akiba Wallet (Transfer from Ushirika Shares)</option>
              </select>
              <div class="form-text text-muted" id="sourceHint">
                Source wallet: <span class="fw-bold text-success">Akiba Wallet</span>
              </div>
            </div>

            <!-- Transfer Amount -->
            <div class="mb-3">
              <label for="transferAmount" class="form-label fw-bold">Transfer Amount (KES) <span class="text-danger">*</span></label>
              <div class="input-group input-group-lg">
                <span class="input-group-text bg-light fw-bold">KES</span>
                <input type="number"
                       step="0.01"
                       min="1"
                       name="amount"
                       id="transferAmount"
                       class="form-control fw-bold"
                       placeholder="0.00"
                       required>
              </div>
              <div class="form-text text-danger d-none" id="transferAmountError">Amount exceeds available source balance!</div>
            </div>

            <!-- Reason / Notes -->
            <div class="mb-3">
              <label for="transferNotes" class="form-label fw-bold">Transfer Reason / Notes (Optional)</label>
              <input type="text"
                     name="notes"
                     id="transferNotes"
                     class="form-control"
                     placeholder="e.g. Member requested share capital top-up">
            </div>
          </div>

          <div class="modal-footer bg-light px-4 py-3">
            <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Close</button>
            <button type="button" class="btn btn-primary px-4 fw-bold shadow-sm" id="btnOpenConfirmation">
              <i class="bi bi-send me-1"></i> Send Transfer
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- SECONDARY CONFIRMATION MODAL -->
  <div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg">
        <div class="modal-header bg-warning text-dark border-0">
          <h5 class="modal-title fw-bold" id="confirmationModalLabel">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Credit Transfer
          </h5>
          <button type="button" class="btn-close" id="btnCloseConfirmation" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <p class="mb-3">Are you sure you want to execute this transfer? This action will immediately adjust the member's wallet balances and send an SMS notification.</p>
          <div class="p-3 bg-light rounded border">
            <div class="mb-2"><strong>Member:</strong> <span id="confMemberName"></span> (<span id="confMemberId"></span>)</div>
            <div class="mb-2"><strong>Transfer Direction:</strong> <span id="confDirection" class="badge bg-primary"></span></div>
            <div class="mb-2"><strong>Transfer Amount:</strong> <span class="h5 fw-bold text-success font-monospace mb-0" id="confAmount"></span></div>
            <div><strong>Authorized By:</strong> <span><?= e($adminName) ?></span></div>
          </div>
        </div>
        <div class="modal-footer bg-light px-4 py-3">
          <button type="button" class="btn btn-outline-secondary px-4 fw-semibold" id="btnCancelConfirmation">Cancel</button>
          <button type="button" class="btn btn-success px-4 fw-bold shadow-sm" id="btnConfirmExecution">
            <i class="bi bi-check-circle me-1"></i> Yes, Transfer Now
          </button>
        </div>
      </div>
    </div>
  </div>

  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const transferModalEl = document.getElementById('transferModal');
      const confirmationModalEl = document.getElementById('confirmationModal');
      const transferModal = new bootstrap.Modal(transferModalEl);
      const confirmationModal = new bootstrap.Modal(confirmationModalEl);

      const modalMemberId = document.getElementById('modalMemberId');
      const modalMemberIdDisplay = document.getElementById('modalMemberIdDisplay');
      const modalMemberName = document.getElementById('modalMemberName');
      const modalNationalId = document.getElementById('modalNationalId');
      const modalAkibaDisplay = document.getElementById('modalAkibaDisplay');
      const modalUshirikaDisplay = document.getElementById('modalUshirikaDisplay');
      const transferToSelect = document.getElementById('transferToSelect');
      const sourceHint = document.getElementById('sourceHint');
      const transferAmount = document.getElementById('transferAmount');
      const transferAmountError = document.getElementById('transferAmountError');
      const btnOpenConfirmation = document.getElementById('btnOpenConfirmation');

      // Confirmation modal elements
      const confMemberName = document.getElementById('confMemberName');
      const confMemberId = document.getElementById('confMemberId');
      const confDirection = document.getElementById('confDirection');
      const confAmount = document.getElementById('confAmount');
      const btnConfirmExecution = document.getElementById('btnConfirmExecution');
      const btnCancelConfirmation = document.getElementById('btnCancelConfirmation');
      const btnCloseConfirmation = document.getElementById('btnCloseConfirmation');
      const transferForm = document.getElementById('transferForm');

      let currentAkiba = 0;
      let currentUshirika = 0;
      let currentMemberName = '';
      let currentMemberIdVal = '';

      // Trigger transfer modal
      document.querySelectorAll('.btn-transfer-trigger').forEach(function (btn) {
        btn.addEventListener('click', function () {
          currentMemberIdVal = this.getAttribute('data-member-id');
          currentMemberName = this.getAttribute('data-member-name');
          const natId = this.getAttribute('data-national-id');
          currentAkiba = parseFloat(this.getAttribute('data-akiba')) || 0;
          currentUshirika = parseFloat(this.getAttribute('data-ushirika')) || 0;

          modalMemberId.value = currentMemberIdVal;
          modalMemberIdDisplay.textContent = currentMemberIdVal;
          modalMemberName.textContent = currentMemberName;
          modalNationalId.textContent = natId;
          modalAkibaDisplay.textContent = 'KES ' + currentAkiba.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          modalUshirikaDisplay.textContent = 'KES ' + currentUshirika.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

          transferAmount.value = '';
          transferAmountError.classList.add('d-none');
          transferToSelect.value = 'ushirika';
          updateDirectionHint();
        });
      });

      function updateDirectionHint() {
        const to = transferToSelect.value;
        if (to === 'ushirika') {
          sourceHint.innerHTML = 'Source wallet: <span class="fw-bold text-success">Akiba Wallet</span> (Available: KES ' + currentAkiba.toFixed(2) + ')';
          transferAmount.max = currentAkiba;
        } else {
          sourceHint.innerHTML = 'Source wallet: <span class="fw-bold" style="color:#7c3aed;">Ushirika Shares</span> (Available: KES ' + currentUshirika.toFixed(2) + ')';
          transferAmount.max = currentUshirika;
        }
        validateAmount();
      }

      function validateAmount() {
        const val = parseFloat(transferAmount.value) || 0;
        const to = transferToSelect.value;
        const maxVal = (to === 'ushirika') ? currentAkiba : currentUshirika;

        if (val <= 0) {
          transferAmountError.textContent = 'Please enter an amount greater than 0.';
          transferAmountError.classList.remove('d-none');
          btnOpenConfirmation.disabled = true;
          return false;
        } else if (val > maxVal) {
          transferAmountError.textContent = 'Amount exceeds available ' + (to === 'ushirika' ? 'Akiba Wallet' : 'Ushirika Shares') + ' balance (KES ' + maxVal.toFixed(2) + ')!';
          transferAmountError.classList.remove('d-none');
          btnOpenConfirmation.disabled = true;
          return false;
        } else {
          transferAmountError.classList.add('d-none');
          btnOpenConfirmation.disabled = false;
          return true;
        }
      }

      transferToSelect.addEventListener('change', updateDirectionHint);
      transferAmount.addEventListener('input', validateAmount);

      // Open secondary confirmation modal
      btnOpenConfirmation.addEventListener('click', function () {
        if (!validateAmount()) return;

        const amt = parseFloat(transferAmount.value) || 0;
        const to = transferToSelect.value;
        const dirText = (to === 'ushirika') ? 'Akiba Wallet → Ushirika Shares' : 'Ushirika Shares → Akiba Wallet';

        confMemberName.textContent = currentMemberName;
        confMemberId.textContent = currentMemberIdVal;
        confDirection.textContent = dirText;
        confAmount.textContent = 'KES ' + amt.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        transferModal.hide();
        confirmationModal.show();
      });

      // Cancel confirmation -> return to transfer modal
      function returnToTransferModal() {
        confirmationModal.hide();
        setTimeout(function () {
          transferModal.show();
        }, 300);
      }

      btnCancelConfirmation.addEventListener('click', returnToTransferModal);
      btnCloseConfirmation.addEventListener('click', returnToTransferModal);

      // Submit execution
      btnConfirmExecution.addEventListener('click', function () {
        btnConfirmExecution.disabled = true;
        btnConfirmExecution.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Processing...';
        transferForm.submit();
      });
    });
  </script>
</body>
</html>

