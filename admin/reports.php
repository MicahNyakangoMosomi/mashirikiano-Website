<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/SimplePdfTable.php';
require_once __DIR__ . '/admin_layout.php';

Auth::requireAdmin();

try {
    $pdo = Database::connection();
    $pdo->query('SELECT 1 FROM members LIMIT 1');
    $pdo->query('SELECT 1 FROM deposits LIMIT 1');
    $pdo->query('SELECT 1 FROM member_transactions LIMIT 1');
} catch (Throwable $error) {
    error_log('Admin reports setup error: ' . $error->getMessage());
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Admin Setup Required | Mashirikiano SACCO</title>
      <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body style="background:#f3f6fa;">
      <main class="container py-5">
        <section class="card border-0 shadow-sm">
          <div class="card-body p-4">
            <h1 class="h4 fw-bold">Admin setup is not complete</h1>
            <p class="text-muted">The admin login worked, but the reports cannot load because the database schema is missing or not fully migrated.</p>
            <div class="alert alert-warning">
              <?= htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <p>Run the migration in phpMyAdmin, then reload this page:</p>
            <pre class="bg-light p-3 rounded">database/financial_workflow_migration.sql</pre>
            <p class="mb-0">If the migration keeps failing, open the migration file and run the required table SQL manually.</p>
          </div>
        </section>
      </main>
    </body>
    </html>
    <?php
    exit;
}

$allowedTypes = ['contribution', 'deposit'];
$allowedLimits = [5, 10, 25, 50];
$typeInput = $_GET['type'] ?? 'contribution';
$reportType = in_array($typeInput, $allowedTypes, true) ? $typeInput : 'contribution';
$rowLimit = (int)($_GET['limit'] ?? 10);
if (!in_array($rowLimit, $allowedLimits, true)) {
    $rowLimit = 10;
}
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$transactionSearch = trim($_GET['transaction_id'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$summary = loadReportSummary($pdo);
[$transactions, $totalRecords, $totalPages, $currentPage] = loadReportTransactions($pdo, $reportType, $rowLimit, $currentPage, $transactionSearch, $dateFrom, $dateTo);

if (($_GET['download'] ?? '') === 'pdf') {
    $pdfRows = loadReportTransactionsForPdf($pdo, $reportType, $transactionSearch, $dateFrom, $dateTo);
    SimplePdfTable::download(
        $reportType . '-report.pdf',
        ucfirst($reportType) . ' Report',
        ['TranID', 'Type', 'Category', 'NationalID', 'Name', 'Phone', 'MemberID', 'Date', 'Amount', 'Description'],
        array_map('transactionPdfRow', $pdfRows),
        [72, 58, 78, 68, 100, 70, 62, 82, 62, 110]
    );
}

if (($_GET['ajax'] ?? '') === 'reports') {
    header('Content-Type: application/json');
    echo json_encode([
        'rows' => renderTransactionRows($transactions),
        'summary' => reportResultSummary(count($transactions), $totalRecords, $currentPage, $rowLimit),
        'pagination' => renderPagination($currentPage, $totalPages),
        'page' => $currentPage,
        'totalPages' => $totalPages,
    ]);
    exit;
}

$downloadReportParams = $_GET;
unset($downloadReportParams['ajax'], $downloadReportParams['page'], $downloadReportParams['limit']);
$downloadReportParams['download'] = 'pdf';
$downloadReportParams['type'] = $reportType;

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function loadReportSummary(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            COALESCE(SUM(CASE WHEN TransactionType = 'contribution' THEN Amount ELSE 0 END), 0) AS TotalContributions,
            COALESCE(SUM(CASE WHEN TransactionType = 'deposit' THEN Amount ELSE 0 END), 0) AS TotalDeposits,
            SUM(CASE WHEN TransactionType = 'contribution' THEN 1 ELSE 0 END) AS ContributionCount,
            SUM(CASE WHEN TransactionType = 'deposit' THEN 1 ELSE 0 END) AS DepositCount,
            SUM(CASE WHEN MemberID IS NULL THEN 1 ELSE 0 END) AS UnmatchedTransactions
         FROM member_transactions"
    );

    return $stmt->fetch() ?: [];
}

function reportFilterSql(string $type, string $transactionSearch, string $dateFrom, string $dateTo): array
{
    $where = 'WHERE TransactionType = :type';
    $params = [':type' => $type];

    if ($transactionSearch !== '') {
        $where .= ' AND TranID LIKE :transaction_id';
        $params[':transaction_id'] = '%' . $transactionSearch . '%';
    }

    if ($dateFrom !== '') {
        $where .= ' AND DATE(COALESCE(TranTime, CreatedAt)) >= :date_from';
        $params[':date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where .= ' AND DATE(COALESCE(TranTime, CreatedAt)) <= :date_to';
        $params[':date_to'] = $dateTo;
    }

    return [$where, $params];
}

function loadReportTransactions(PDO $pdo, string $type, int $limit, int $page, string $transactionSearch, string $dateFrom, string $dateTo): array
{
    [$where, $params] = reportFilterSql($type, $transactionSearch, $dateFrom, $dateTo);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM member_transactions {$where}");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRecords / $limit));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->prepare(
        "SELECT *
         FROM member_transactions
         {$where}
         ORDER BY COALESCE(TranTime, CreatedAt) DESC
         LIMIT {$limit} OFFSET {$offset}"
    );
    $stmt->execute($params);

    return [$stmt->fetchAll(), $totalRecords, $totalPages, $page];
}

function loadReportTransactionsForPdf(PDO $pdo, string $type, string $transactionSearch, string $dateFrom, string $dateTo): array
{
    [$where, $params] = reportFilterSql($type, $transactionSearch, $dateFrom, $dateTo);

    $stmt = $pdo->prepare(
        "SELECT *
         FROM member_transactions
         {$where}
         ORDER BY COALESCE(TranTime, CreatedAt) DESC"
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function transactionPdfRow(array $row): array
{
    return [
        (string)$row['TranID'],
        (string)$row['TransactionType'],
        (string)$row['TransactionCategory'],
        (string)$row['NationalID'],
        trim($row['FirstName'] . ' ' . $row['LastName']),
        (string)$row['MSISDN'],
        (string)($row['MemberID'] ?: 'NULL'),
        (string)($row['TranTime'] ?: $row['CreatedAt']),
        number_format((float)$row['Amount'], 2),
        (string)($row['Description'] ?? ''),
    ];
}

function renderTransactionRows(array $transactions): string
{
    ob_start();
    foreach ($transactions as $row): ?>
      <tr>
        <td><?= e($row['TranID']) ?></td>
        <td><?= e($row['TransactionCategory']) ?></td>
        <td><?= e($row['NationalID']) ?></td>
        <td><?= e(trim($row['FirstName'] . ' ' . $row['LastName'])) ?></td>
        <td><?= e($row['MSISDN']) ?></td>
        <td><?= $row['MemberID'] ? e($row['MemberID']) : '<span class="badge text-bg-warning">NULL</span>' ?></td>
        <td><?= e($row['TranTime'] ?: $row['CreatedAt']) ?></td>
        <td><?= e($row['Description'] ?? '') ?></td>
        <td class="text-end"><?= number_format((float)$row['Amount'], 2) ?></td>
      </tr>
    <?php endforeach;

    if (!$transactions): ?>
      <tr><td colspan="9" class="text-muted">No records found.</td></tr>
    <?php endif;

    return trim((string)ob_get_clean());
}

function reportResultSummary(int $visibleCount, int $totalRecords, int $page, int $limit): string
{
    if ($totalRecords === 0) {
        return '0 records found';
    }

    $start = (($page - 1) * $limit) + 1;
    $end = $start + $visibleCount - 1;

    return "Showing {$start}-{$end} of {$totalRecords} record" . ($totalRecords === 1 ? '' : 's');
}

function renderPagination(int $currentPage, int $totalPages): string
{
    if ($totalPages <= 1) {
        return '';
    }

    ob_start(); ?>
      <nav aria-label="Report pages">
        <ul class="pagination pagination-sm mb-0">
          <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="#" data-page="<?= max(1, $currentPage - 1) ?>">Previous</a>
          </li>
          <?php for ($page = 1; $page <= $totalPages; $page++): ?>
            <li class="page-item <?= $page === $currentPage ? 'active' : '' ?>">
              <a class="page-link" href="#" data-page="<?= $page ?>"><?= $page ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="#" data-page="<?= min($totalPages, $currentPage + 1) ?>">Next</a>
          </li>
        </ul>
      </nav>
    <?php return trim((string)ob_get_clean());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reports | Mashirikiano SACCO Admin</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <style>
    @media (max-width: 767px) { .table { min-width: 1040px; } }
  </style>
</head>
<body>
  <?php admin_header('reports', 'Transaction reporting'); ?>

  <main class="container-fluid admin-shell py-4">
    <div class="row g-4 mb-4">
      <div class="col-md-4">
        <div class="card metric h-100"><div class="card-body"><div class="text-muted small">Total Contributions</div><div class="h3 fw-bold"><?= number_format((float)($summary['TotalContributions'] ?? 0), 2) ?></div></div></div>
      </div>
      <div class="col-md-4">
        <div class="card metric h-100"><div class="card-body"><div class="text-muted small">Total Deposits</div><div class="h3 fw-bold"><?= number_format((float)($summary['TotalDeposits'] ?? 0), 2) ?></div></div></div>
      </div>
      <div class="col-md-4">
        <div class="card metric h-100"><div class="card-body"><div class="text-muted small">Unmatched Records</div><div class="h3 fw-bold"><?= (int)($summary['UnmatchedTransactions'] ?? 0) ?></div></div></div>
      </div>
    </div>

    <section class="card panel">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
          <h1 class="h5 fw-bold mb-0">Transaction Report</h1>
          <form method="get" id="reportControls" class="row g-2 flex-grow-1 justify-content-end">
            <div class="col-lg-3">
              <select class="form-select" id="reportType" name="type">
                <option value="contribution" <?= $reportType === 'contribution' ? 'selected' : '' ?>>Contributions</option>
                <option value="deposit" <?= $reportType === 'deposit' ? 'selected' : '' ?>>Deposits</option>
              </select>
            </div>
            <div class="col-lg-2">
              <input class="form-control" id="transactionIdSearch" name="transaction_id" value="<?= e($transactionSearch) ?>" placeholder="Transaction ID">
            </div>
            <div class="col-lg-2">
              <input class="form-control" id="dateFrom" name="date_from" type="date" value="<?= e($dateFrom) ?>" aria-label="Date from">
            </div>
            <div class="col-lg-2">
              <input class="form-control" id="dateTo" name="date_to" type="date" value="<?= e($dateTo) ?>" aria-label="Date to">
            </div>
            <div class="col-lg-2">
              <select class="form-select" id="rowLimit" name="limit">
                <?php foreach ($allowedLimits as $limitOption): ?>
                  <option value="<?= (int)$limitOption ?>" <?= $rowLimit === $limitOption ? 'selected' : '' ?>><?= (int)$limitOption ?> rows</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-lg-2">
              <button class="btn btn-primary w-100" type="submit">Search</button>
            </div>
            <div class="col-lg-2">
              <a class="btn btn-outline-primary w-100" id="downloadReportPdf" href="reports.php?<?= e(http_build_query($downloadReportParams)) ?>">Download PDF</a>
            </div>
            <div class="col-lg-1">
              <a class="btn btn-outline-secondary w-100" href="reports.php">Clear</a>
            </div>
          </form>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <div class="text-muted small" id="reportResultSummary"><?= e(reportResultSummary(count($transactions), $totalRecords, $currentPage, $rowLimit)) ?></div>
          <div id="reportPagination">
            <?= renderPagination($currentPage, $totalPages) ?>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table align-middle">
            <thead><tr><th>TranID</th><th>Category</th><th>National ID</th><th>Name</th><th>Phone</th><th>MemberID</th><th>Date</th><th>Description</th><th class="text-end">Amount</th></tr></thead>
            <tbody id="reportTableBody">
              <?= renderTransactionRows($transactions) ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </main>

  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    const reportControls = document.getElementById('reportControls');
    const reportType = document.getElementById('reportType');
    const transactionIdSearch = document.getElementById('transactionIdSearch');
    const dateFrom = document.getElementById('dateFrom');
    const dateTo = document.getElementById('dateTo');
    const rowLimit = document.getElementById('rowLimit');
    const reportTableBody = document.getElementById('reportTableBody');
    const reportResultSummary = document.getElementById('reportResultSummary');
    const reportPagination = document.getElementById('reportPagination');
    const downloadReportPdf = document.getElementById('downloadReportPdf');
    let currentPage = <?= (int)$currentPage ?>;
    let activeController = null;

    function updateDownloadLink() {
      const url = new URL(window.location.href);
      url.searchParams.set('download', 'pdf');
      url.searchParams.set('type', reportType.value);
      if (transactionIdSearch.value) {
        url.searchParams.set('transaction_id', transactionIdSearch.value);
      } else {
        url.searchParams.delete('transaction_id');
      }
      if (dateFrom.value) {
        url.searchParams.set('date_from', dateFrom.value);
      } else {
        url.searchParams.delete('date_from');
      }
      if (dateTo.value) {
        url.searchParams.set('date_to', dateTo.value);
      } else {
        url.searchParams.delete('date_to');
      }
      url.searchParams.delete('ajax');
      url.searchParams.delete('page');
      url.searchParams.delete('limit');
      downloadReportPdf.href = url.toString();
    }

    function updateReport(page = currentPage) {
      if (activeController) {
        activeController.abort();
      }

      activeController = new AbortController();
      currentPage = Math.max(1, parseInt(page, 10) || 1);
      const params = new URLSearchParams(window.location.search);
      params.set('ajax', 'reports');
      params.set('type', reportType.value);
      params.set('transaction_id', transactionIdSearch.value);
      params.set('date_from', dateFrom.value);
      params.set('date_to', dateTo.value);
      params.set('limit', rowLimit.value);
      params.set('page', currentPage);

      fetch(`reports.php?${params.toString()}`, {
        credentials: 'same-origin',
        signal: activeController.signal
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Report load failed');
          }
          return response.json();
        })
        .then(function (data) {
          reportTableBody.innerHTML = data.rows;
          reportResultSummary.textContent = data.summary;
          reportPagination.innerHTML = data.pagination;
          currentPage = data.page;

          const url = new URL(window.location.href);
          url.searchParams.set('type', reportType.value);
          if (transactionIdSearch.value) {
            url.searchParams.set('transaction_id', transactionIdSearch.value);
          } else {
            url.searchParams.delete('transaction_id');
          }
          if (dateFrom.value) {
            url.searchParams.set('date_from', dateFrom.value);
          } else {
            url.searchParams.delete('date_from');
          }
          if (dateTo.value) {
            url.searchParams.set('date_to', dateTo.value);
          } else {
            url.searchParams.delete('date_to');
          }
          url.searchParams.set('limit', rowLimit.value);
          url.searchParams.set('page', currentPage);
          window.history.replaceState({}, '', url);
          updateDownloadLink();
        })
        .catch(function (error) {
          if (error.name !== 'AbortError') {
            reportTableBody.innerHTML = '<tr><td colspan="9" class="text-danger">Unable to load report right now.</td></tr>';
          }
        });
    }

    reportControls.addEventListener('submit', function (event) {
      event.preventDefault();
      updateReport(1);
    });

    reportType.addEventListener('change', function () {
      updateReport(1);
    });

    rowLimit.addEventListener('change', function () {
      updateReport(1);
    });

    reportPagination.addEventListener('click', function (event) {
      const link = event.target.closest('[data-page]');
      if (!link || link.parentElement.classList.contains('disabled') || link.parentElement.classList.contains('active')) {
        return;
      }

      event.preventDefault();
      updateReport(link.dataset.page);
    });

    updateDownloadLink();
  </script>
</body>
</html>
