<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/MemberService.php';
require_once __DIR__ . '/../classes/SimplePdfTable.php';
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

// Self-healing table creation for withdrawals
try {
    $pdo->query("SELECT 1 FROM `withdrawals` LIMIT 1");
} catch (Throwable $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `withdrawals` (
      `WithdrawalID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `MemberID` VARCHAR(40) NOT NULL,
      `Amount` DECIMAL(12,2) NOT NULL,
      `WithdrawalDate` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `Description` VARCHAR(255) NULL,
      `AdminUserID` INT UNSIGNED NULL,
      `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`WithdrawalID`),
      KEY `idx_withdrawals_member` (`MemberID`),
      KEY `idx_withdrawals_date` (`WithdrawalDate`),
      CONSTRAINT `fk_withdrawals_member`
        FOREIGN KEY (`MemberID`) REFERENCES `members` (`MemberID`)
        ON UPDATE CASCADE
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// PDF Export for Withdrawals History
if (($_GET['download'] ?? '') === 'pdf') {
    $pdfSearch = trim($_GET['search'] ?? '');
    $pdfWhere = "";
    $pdfParams = [];

    if ($pdfSearch !== '') {
        $pdfWhere = "WHERE (w.MemberID LIKE :search OR m.FirstName LIKE :search OR m.LastName LIKE :search OR m.NationalID LIKE :search OR CONCAT(m.FirstName, ' ', m.LastName) LIKE :search OR w.Description LIKE :search)";
        $pdfParams[':search'] = '%' . $pdfSearch . '%';
    }

    $pdfStmt = $pdo->prepare("
        SELECT w.*, m.FirstName, m.LastName, m.NationalID, m.PrimaryNumber
        FROM withdrawals w
        JOIN members m ON m.MemberID = w.MemberID
        {$pdfWhere}
        ORDER BY w.WithdrawalDate DESC
    ");
    $pdfStmt->execute($pdfParams);
    $withdrawalsPdf = $pdfStmt->fetchAll();

    SimplePdfTable::download(
        'withdrawals-logs-report.pdf',
        'SACCO Member Withdrawal Logs Report',
        ['Ref #', 'Member ID', 'Member Name', 'National ID', 'Amount', 'Date & Time', 'Description'],
        array_map(static function (array $w): array {
            return [
                'WD-' . $w['WithdrawalID'],
                (string)$w['MemberID'],
                trim($w['FirstName'] . ' ' . $w['LastName']),
                (string)$w['NationalID'],
                number_format((float)$w['Amount'], 2),
                date('Y-m-d H:i', strtotime($w['WithdrawalDate'])),
                (string)($w['Description'] ?: 'Member Withdrawal')
            ];
        }, $withdrawalsPdf),
        [50, 75, 120, 75, 80, 90, 110]
    );
}

// Filtering & Pagination for Withdrawal Records
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;

$whereClause = "";
$params = [];

if ($search !== '') {
    $whereClause = "WHERE (w.MemberID LIKE :search OR m.FirstName LIKE :search OR m.LastName LIKE :search OR m.NationalID LIKE :search OR CONCAT(m.FirstName, ' ', m.LastName) LIKE :search OR w.Description LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM withdrawals w 
    JOIN members m ON m.MemberID = w.MemberID 
    {$whereClause}
");
$countStmt->execute($params);
$totalWithdrawalRecords = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalWithdrawalRecords / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$historyStmt = $pdo->prepare("
    SELECT w.*, m.FirstName, m.LastName, m.NationalID, m.PrimaryNumber, au.FullName AS AdminName
    FROM withdrawals w
    JOIN members m ON m.MemberID = w.MemberID
    LEFT JOIN admin_users au ON au.AdminUserID = w.AdminUserID
    {$whereClause}
    ORDER BY w.WithdrawalDate DESC
    LIMIT {$limit} OFFSET {$offset}
");
$historyStmt->execute($params);
$withdrawalHistory = $historyStmt->fetchAll();

// Summary Metrics
$summaryStats = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM withdrawals) AS TotalLogsCount,
        (SELECT COALESCE(SUM(Amount), 0) FROM withdrawals) AS TotalAmountWithdrawn,
        (SELECT COUNT(*) FROM members WHERE Status = 'Active') AS ActiveMembersCount,
        (SELECT COALESCE(SUM(Amount), 0) FROM member_transactions WHERE TransactionType = 'contribution') AS TotalContributions
")->fetch();

$logsCount = (int)($summaryStats['TotalLogsCount'] ?? 0);
$totalWithdrawn = (float)($summaryStats['TotalAmountWithdrawn'] ?? 0);
$activeCount = (int)($summaryStats['ActiveMembersCount'] ?? 0);
$allContributions = (float)($summaryStats['TotalContributions'] ?? 0);
$netSavingsPool = max(0.00, $allContributions - $totalWithdrawn);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Withdrawal Logs | Mashirikiano SACCO Admin</title>
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
  </style>
</head>
<body>

  <?php admin_header('withdrawal_logs', 'Member Withdrawal Logs & Transaction Records'); ?>

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
              <div class="text-muted small text-uppercase fw-bold">Total Withdrawal Logs</div>
              <div class="h3 fw-bold mb-0 text-primary"><?= number_format($logsCount) ?></div>
            </div>
            <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary">
              <i class="bi bi-journal-text fs-4"></i>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-metric shadow-sm bg-white p-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small text-uppercase fw-bold">Total Amount Withdrawn</div>
              <div class="h3 fw-bold mb-0 text-danger"><?= number_format($totalWithdrawn, 2) ?></div>
            </div>
            <div class="bg-danger bg-opacity-10 p-3 rounded-circle text-danger">
              <i class="bi bi-arrow-up-right-circle-fill fs-4"></i>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-metric shadow-sm bg-white p-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small text-uppercase fw-bold">Active Members</div>
              <div class="h3 fw-bold mb-0 text-success"><?= number_format($activeCount) ?></div>
            </div>
            <div class="bg-success bg-opacity-10 p-3 rounded-circle text-success">
              <i class="bi bi-person-check-fill fs-4"></i>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-metric shadow-sm bg-white p-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small text-uppercase fw-bold">Net Savings Pool</div>
              <div class="h3 fw-bold mb-0 text-info"><?= number_format($netSavingsPool, 2) ?></div>
            </div>
            <div class="bg-info bg-opacity-10 p-3 rounded-circle text-info">
              <i class="bi bi-bank2 fs-4"></i>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Withdrawal Logs Section -->
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between gap-3 border-bottom">
        <div>
          <h2 class="h5 mb-0 fw-bold text-dark"><i class="bi bi-clock-history text-danger me-2"></i>Member Withdrawal Logs</h2>
          <div class="text-muted small">Complete audit trail of member cash withdrawals and transaction records.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <form class="d-flex gap-2" method="GET" action="withdrawal_logs.php">
            <div class="input-group input-group-sm">
              <input type="text" name="search" class="form-control" placeholder="Search logs by name, ID or ref..." value="<?= e($search) ?>">
              <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i> Search</button>
              <?php if ($search !== ''): ?>
                <a href="withdrawal_logs.php" class="btn btn-outline-secondary" title="Clear Search"><i class="bi bi-x-circle"></i></a>
              <?php endif; ?>
            </div>
          </form>
          <a href="withdrawal_logs.php?download=pdf&search=<?= urlencode($search) ?>" class="btn btn-sm btn-danger shadow-sm fw-semibold">
            <i class="bi bi-file-earmark-pdf me-1"></i> Download PDF Report
          </a>
        </div>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover table-custom align-middle mb-0">
            <thead>
              <tr>
                <th class="ps-3">Ref ID</th>
                <th>Member ID</th>
                <th>Member Name</th>
                <th>National ID</th>
                <th class="text-end">Amount Withdrawn</th>
                <th>Withdrawal Date</th>
                <th>Description</th>
                <th>Processed By</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($withdrawalHistory)): ?>
                <tr>
                  <td colspan="8" class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                    No withdrawal logs found matching your criteria.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($withdrawalHistory as $w): ?>
                  <tr>
                    <td class="ps-3 fw-bold text-secondary">WD-<?= (int)$w['WithdrawalID'] ?></td>
                    <td class="fw-semibold text-primary"><?= e($w['MemberID']) ?></td>
                    <td class="fw-bold"><?= e(trim($w['FirstName'] . ' ' . $w['LastName'])) ?></td>
                    <td><?= e($w['NationalID']) ?></td>
                    <td class="text-end fw-bold text-danger font-monospace"><?= number_format((float)$w['Amount'], 2) ?></td>
                    <td class="small text-muted"><i class="bi bi-calendar3 me-1"></i><?= date('d-M-Y H:i', strtotime($w['WithdrawalDate'])) ?></td>
                    <td><?= e($w['Description'] ?: 'Member Withdrawal') ?></td>
                    <td class="small text-muted"><?= e($w['AdminName'] ?: 'System Admin') ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- History Pagination -->
      <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white py-2 d-flex justify-content-between align-items-center">
          <div class="small text-muted">Showing logs page <?= $page ?> of <?= $totalPages ?> (Total: <?= $totalWithdrawalRecords ?> records)</div>
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

  </main>

  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
