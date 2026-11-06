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

// Self-healing: ensure credit_transfers table exists
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
      KEY `idx_credit_transfers_created` (`CreatedAt`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
}

function ctl_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ── PDF Export ────────────────────────────────────────────────────────────────
if (($_GET['download'] ?? '') === 'pdf') {
    $pdfSearch = trim($_GET['search'] ?? '');
    $pdfWhere  = '';
    $pdfParams = [];

    if ($pdfSearch !== '') {
        $pdfWhere = "WHERE (ct.MemberID LIKE :search
            OR m.FirstName LIKE :search
            OR m.LastName LIKE :search
            OR ct.NationalID LIKE :search
            OR CONCAT(m.FirstName,' ',m.LastName) LIKE :search
            OR ct.TransferCode LIKE :search
            OR ct.AdminName LIKE :search)";
        $pdfParams[':search'] = '%' . $pdfSearch . '%';
    }

    $pdfStmt = $pdo->prepare("
        SELECT ct.TransferCode, ct.MemberID, ct.TransferFrom, ct.TransferTo,
               ct.Amount, ct.CreatedAt, m.FirstName, m.LastName
        FROM   credit_transfers ct
        LEFT JOIN members m ON m.MemberID = ct.MemberID
        {$pdfWhere}
        ORDER BY ct.CreatedAt DESC
    ");
    $pdfStmt->execute($pdfParams);
    $pdfRows = $pdfStmt->fetchAll();

    SimplePdfTable::download(
        'credit-transfer-logs-report.pdf',
        'SACCO Credit Transfer Audit Log',
        ['#', 'Ref Code', 'Member', 'Direction', 'Amount (KES)', 'Date & Time (EAT)'],
        array_map(function ($r, $index) {
            $memberName = trim(($r['FirstName'] ?? '') . ' ' . ($r['LastName'] ?? ''));
            if ($memberName === '') {
                $memberName = $r['MemberID'];
            }

            return [
                $index + 1,
                $r['TransferCode'],
                $memberName . ' (' . $r['MemberID'] . ')',
                strtoupper($r['TransferFrom']) . ' -> ' . strtoupper($r['TransferTo']),
                number_format((float)$r['Amount'], 2),
                date('d M Y H:i', strtotime($r['CreatedAt'])),
            ];
        }, $pdfRows, array_keys($pdfRows))
    );
    exit;
}

// ── Pagination & Search ───────────────────────────────────────────────────────
$search  = trim($_GET['search'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = '';
$params = [];

if ($search !== '') {
    $where = "WHERE (ct.MemberID LIKE :search
        OR m.FirstName LIKE :search
        OR m.LastName LIKE :search
        OR ct.NationalID LIKE :search
        OR CONCAT(m.FirstName,' ',m.LastName) LIKE :search
        OR ct.TransferCode LIKE :search
        OR ct.AdminName LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// Count for pagination
$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM credit_transfers ct
    LEFT JOIN members m ON m.MemberID = ct.MemberID
    {$where}
");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch page rows
$stmt = $pdo->prepare("
    SELECT ct.TransferCode, ct.MemberID, ct.TransferFrom, ct.TransferTo,
           ct.Amount, ct.CreatedAt, m.FirstName, m.LastName
    FROM   credit_transfers ct
    LEFT JOIN members m ON m.MemberID = ct.MemberID
    {$where}
    ORDER BY ct.CreatedAt DESC
    LIMIT  :limit OFFSET :offset
");
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$transfers = $stmt->fetchAll();

// ── Summary Metrics ───────────────────────────────────────────────────────────
$metrics = $pdo->query("
    SELECT
        COUNT(*)                                              AS total_transfers,
        COALESCE(SUM(Amount), 0)                             AS total_amount,
        COALESCE(SUM(CASE WHEN TransferFrom='akiba'    AND TransferTo='ushirika' THEN Amount ELSE 0 END), 0) AS akiba_to_ushirika,
        COALESCE(SUM(CASE WHEN TransferFrom='ushirika' AND TransferTo='akiba'    THEN Amount ELSE 0 END), 0) AS ushirika_to_akiba
    FROM credit_transfers
")->fetch();

// ── Page Output ───────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Credit Transfer Logs | Mashirikiano SACCO Admin</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <style>
    body { background-color:#f4f6f9; font-family:system-ui,-apple-system,sans-serif; }
    .ctl-metric { text-align:center; }
    .ctl-metric .val { font-size:1.5rem; font-weight:700; color:#0d6efd; }
    .ctl-metric .lbl { font-size:.8rem; color:#6c757d; text-transform:uppercase; letter-spacing:.04em; }
    .arrow-sep { margin:0 .3em; color:#64748b; }
    .tbl-sm td, .tbl-sm th { font-size:.82rem; padding:.55rem .65rem; vertical-align:middle; }
    .search-row { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; }
    .search-row input { max-width:360px; }
  </style>
</head>
<body>
<?php admin_header('credit_transfer_logs', 'Credit Transfer Audit Logs'); ?>

<main class="container-fluid admin-shell py-4">

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?= ctl_e($messageType) ?> alert-dismissible fade show" role="alert">
      <?= ctl_e($message) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Page heading -->
  <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
    <div>
      <h1 class="h3 mb-1 fw-bold text-dark">Credit Transfer Logs</h1>
      <p class="text-muted mb-0" style="font-size:.85rem;">Complete history of transfers between <span class="wallet-akiba">Akiba</span> and <span class="wallet-ushirika">Ushirika</span>.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="?<?= http_build_query(array_merge($_GET, ['download' => 'pdf'])) ?>" class="btn btn-danger fw-semibold shadow-sm">
        <i class="bi bi-file-earmark-pdf me-1"></i> Download PDF
      </a>
    </div>
  </div>

  <!-- Summary metric cards -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card shadow-sm border-0 bg-white p-3 h-100 ctl-metric">
        <div class="val"><?= number_format((int)$metrics['total_transfers']) ?></div>
        <div class="lbl">Total Transfers</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card shadow-sm border-0 bg-white p-3 h-100 ctl-metric">
        <div class="val">KES <?= number_format((float)$metrics['total_amount'], 2) ?></div>
        <div class="lbl">Total Amount Moved</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card shadow-sm border-0 bg-white p-3 h-100 ctl-metric" style="border-top:3px solid #e47001;">
        <div class="val wallet-akiba">KES <?= number_format((float)$metrics['akiba_to_ushirika'], 2) ?></div>
        <div class="lbl"><span class="wallet-akiba">Akiba</span> → <span class="wallet-ushirika">Ushirika</span></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card shadow-sm border-0 bg-white p-3 h-100 ctl-metric" style="border-top:3px solid #489a12;">
        <div class="val wallet-ushirika">KES <?= number_format((float)$metrics['ushirika_to_akiba'], 2) ?></div>
        <div class="lbl"><span class="wallet-ushirika">Ushirika</span> → <span class="wallet-akiba">Akiba</span></div>
      </div>
    </div>
  </div>

  <!-- Table card -->
  <div class="card shadow-sm border-0">

    <!-- Search & filter -->
    <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between gap-3 border-bottom">
    <form method="GET" action="credit_transfer_logs.php" class="search-row">
      <input type="text" name="search" class="form-control form-control-sm"
             placeholder="Search by member, name, NatID, ref code, admin…"
             value="<?= ctl_e($search) ?>">
      <button type="submit" class="btn btn-sm btn-primary">Search</button>
      <?php if ($search !== ''): ?>
        <a href="credit_transfer_logs.php" class="btn btn-sm btn-outline-secondary">Clear</a>
      <?php endif; ?>
      <span class="ms-auto text-muted" style="font-size:.8rem;">
        <?= number_format($totalRows) ?> record<?= $totalRows !== 1 ? 's' : '' ?>
      </span>
    </form>
    </div>

    <div class="card-body p-0">
    <?php if (empty($transfers)): ?>
      <div class="alert alert-info mb-0">No credit transfer records found.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover table-custom align-middle mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>Ref Code</th>
              <th>Member</th>
              <th>Direction</th>
              <th class="text-end">Amount (KES)</th>
              <th>Date &amp; Time (EAT)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transfers as $i => $t):
                $memberName = trim(($t['FirstName'] ?? '') . ' ' . ($t['LastName'] ?? ''));
                if ($memberName === '') $memberName = $t['MemberID'];
                $isAkibaToUsh = ($t['TransferFrom'] === 'akiba');
            ?>
              <tr>
                <td class="text-muted"><?= $offset + $i + 1 ?></td>
                <td><code style="font-size:.78rem;"><?= ctl_e($t['TransferCode']) ?></code></td>
                <td>
                  <strong><?= ctl_e($memberName) ?></strong><br>
                  <span class="text-muted small"><?= ctl_e($t['MemberID']) ?></span>
                </td>
                <td>
                  <?php if ($isAkibaToUsh): ?>
                    <span class="badge-akiba">Akiba</span>
                    <span class="arrow-sep">&#8594;</span>
                    <span class="badge-ushirika">Ushirika</span>
                  <?php else: ?>
                    <span class="badge-ushirika">Ushirika</span>
                    <span class="arrow-sep">&#8594;</span>
                    <span class="badge-akiba">Akiba</span>
                  <?php endif; ?>
                </td>
                <td class="text-end fw-semibold font-monospace">KES <?= number_format((float)$t['Amount'], 2) ?></td>
                <td style="white-space:nowrap;"><?= ctl_e(date('d M Y H:i', strtotime($t['CreatedAt']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <nav aria-label="Pagination" class="mt-2">
          <ul class="pagination pagination-sm justify-content-center mb-0">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
              <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link"
                   href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>">
                  <?= $p ?>
                </a>
              </li>
            <?php endfor; ?>
          </ul>
        </nav>
      <?php endif; ?>
    <?php endif; ?>

    </div><!-- /.card-body -->
  </div><!-- /.card -->

</main>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>

