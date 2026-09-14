<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/admin_layout.php';

Auth::requireAdmin();

try {
    $pdo = Database::connection();
    $pdo->query('SELECT 1 FROM members LIMIT 1');
    $pdo->query('SELECT 1 FROM deposits LIMIT 1');
    $pdo->query('SELECT 1 FROM member_transactions LIMIT 1');

    try {
        $pdo->query('SELECT 1 FROM loan_applications LIMIT 1');
    } catch (Throwable $error) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `loan_applications` (
          `LoanApplicationID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `MemberID` VARCHAR(40) NOT NULL,
          `LoanType` VARCHAR(100) NOT NULL,
          `Amount` DECIMAL(12,2) NOT NULL,
          `ReturnDate` DATE NOT NULL,
          `Status` ENUM('Pending', 'Approved', 'Not Approved') NOT NULL DEFAULT 'Pending',
          `RejectionReason` VARCHAR(255) NULL,
          `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`LoanApplicationID`),
          KEY `idx_loan_applications_member` (`MemberID`),
          KEY `idx_loan_applications_status` (`Status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }
} catch (Throwable $error) {
    error_log('Admin dashboard setup error: ' . $error->getMessage());
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
            <p class="text-muted">The dashboard cannot load because the database schema is missing or not fully migrated.</p>
            <div class="alert alert-warning"><?= htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8') ?></div>
            <pre class="bg-light p-3 rounded">database/financial_workflow_migration.sql</pre>
          </div>
        </section>
      </main>
    </body>
    </html>
    <?php
    exit;
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function moneyShort(float $amount): string
{
    if (abs($amount) >= 1000000) {
        return number_format($amount / 1000000, 2) . 'M';
    }

    if (abs($amount) >= 1000) {
        return number_format($amount / 1000, 1) . 'K';
    }

    return number_format($amount, 0);
}

function monthBuckets(): array
{
    $months = [];
    $start = new DateTime('first day of this month');
    $start->modify('-11 months');

    for ($index = 0; $index < 12; $index++) {
        $key = $start->format('Y-m');
        $months[$key] = [
            'label' => $start->format('M'),
            'members' => 0,
            'savings' => 0.0,
            'loans' => 0.0,
        ];
        $start->modify('+1 month');
    }

    return $months;
}

function hydrateMonthly(PDO $pdo, array $months, string $sql, string $valueKey, bool $floatValue = false): array
{
    foreach ($pdo->query($sql)->fetchAll() as $row) {
        $month = (string)$row['MonthKey'];
        if (isset($months[$month])) {
            $months[$month][$valueKey] = $floatValue ? (float)$row['Value'] : (int)$row['Value'];
        }
    }

    return $months;
}

function barChart(array $months, string $key, string $color): string
{
    $max = 0.0;
    foreach ($months as $month) {
        $max = max($max, (float)$month[$key]);
    }
    $max = max($max, 1);

    ob_start(); ?>
      <div class="admin-bars">
        <?php foreach ($months as $month): ?>
          <?php $height = max(6, ((float)$month[$key] / $max) * 132); ?>
          <div class="admin-bar-wrap">
            <div class="admin-bar" style="height: <?= e(number_format($height, 2, '.', '')) ?>px; background: <?= e($color) ?>;"></div>
            <span><?= e($month['label']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php return trim((string)ob_get_clean());
}

function lineChart(array $months, string $firstKey, string $secondKey): string
{
    $width = 640;
    $height = 220;
    $pad = 26;
    $max = 1.0;
    foreach ($months as $month) {
        $max = max($max, (float)$month[$firstKey], (float)$month[$secondKey]);
    }

    $pointsA = [];
    $pointsB = [];
    $labels = array_values($months);
    $count = max(1, count($labels) - 1);
    foreach ($labels as $index => $month) {
        $x = $pad + (($width - ($pad * 2)) * ($index / $count));
        $yA = $height - $pad - (((float)$month[$firstKey] / $max) * ($height - ($pad * 2)));
        $yB = $height - $pad - (((float)$month[$secondKey] / $max) * ($height - ($pad * 2)));
        $pointsA[] = number_format($x, 2, '.', '') . ',' . number_format($yA, 2, '.', '');
        $pointsB[] = number_format($x, 2, '.', '') . ',' . number_format($yB, 2, '.', '');
    }

    ob_start(); ?>
      <svg class="admin-line-chart" viewBox="0 0 <?= $width ?> <?= $height ?>" role="img" aria-label="Savings and loan trend">
        <line x1="<?= $pad ?>" y1="<?= $height - $pad ?>" x2="<?= $width - $pad ?>" y2="<?= $height - $pad ?>" />
        <line x1="<?= $pad ?>" y1="<?= $pad ?>" x2="<?= $pad ?>" y2="<?= $height - $pad ?>" />
        <polyline class="savings-line" points="<?= e(implode(' ', $pointsA)) ?>" />
        <polyline class="loans-line" points="<?= e(implode(' ', $pointsB)) ?>" />
      </svg>
    <?php return trim((string)ob_get_clean());
}

$memberStats = $pdo->query(
    "SELECT
        COUNT(*) AS TotalMembers,
        SUM(CASE WHEN Status = 'Active' THEN 1 ELSE 0 END) AS ActiveMembers,
        SUM(CASE WHEN Status = 'Pending' THEN 1 ELSE 0 END) AS PendingMembers
     FROM members"
)->fetch() ?: [];

$transactionStats = $pdo->query(
    "SELECT
    COALESCE(SUM(CASE WHEN TransactionType = 'contribution' THEN Amount ELSE 0 END), 0) AS TotalContributing,
    COALESCE(SUM(CASE WHEN TransactionType = 'withdrawal' THEN Amount ELSE 0 END), 0) AS TotalWithdrawals,
        COALESCE(SUM(CASE WHEN TransactionType = 'deposit' THEN Amount ELSE 0 END), 0) AS TotalDeposits,
        COALESCE(SUM(CASE WHEN TransactionType = 'contribution' AND DATE_FORMAT(COALESCE(TranTime, CreatedAt), '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m') THEN Amount ELSE 0 END), 0) AS ThisMonthSavings
     FROM member_transactions"
)->fetch() ?: [];

$depositStats = $pdo->query(
    'SELECT COALESCE(SUM(PaidAmount), 0) AS ShareCapital FROM deposits'
)->fetch() ?: [];

$loanStats = $pdo->query(
    "SELECT
        COUNT(*) AS TotalLoanRequests,
        SUM(CASE WHEN Status = 'Pending' THEN 1 ELSE 0 END) AS PendingLoans,
        SUM(CASE WHEN Status = 'Approved' THEN 1 ELSE 0 END) AS ActiveLoans,
        COALESCE(SUM(CASE WHEN Status = 'Approved' THEN Amount ELSE 0 END), 0) AS LoanPortfolio,
        COALESCE(SUM(CASE WHEN Status = 'Approved' AND DATE_FORMAT(CreatedAt, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m') THEN Amount ELSE 0 END), 0) AS ThisMonthDisbursed
     FROM loan_applications"
)->fetch() ?: [];

$totalMembers = (int)($memberStats['TotalMembers'] ?? 0);
$activeMembers = (int)($memberStats['ActiveMembers'] ?? 0);
$pendingMembers = (int)($memberStats['PendingMembers'] ?? 0);
$totalContributing = (float)($transactionStats['TotalContributing'] ?? 0);
$totalWithdrawals = (float)($transactionStats['TotalWithdrawals'] ?? 0);
$netSavings = max(0.0, $totalContributing - $totalWithdrawals);
$withdrawalRate = $totalContributing > 0 ? min(100, ($totalWithdrawals / $totalContributing) * 100) : 0;
$shareCapital = (float)($depositStats['ShareCapital'] ?? 0);
$activeLoans = (int)($loanStats['ActiveLoans'] ?? 0);
$loanPortfolio = (float)($loanStats['LoanPortfolio'] ?? 0);
$pendingLoans = (int)($loanStats['PendingLoans'] ?? 0);
$thisMonthSavings = (float)($transactionStats['ThisMonthSavings'] ?? 0);
$thisMonthDisbursed = (float)($loanStats['ThisMonthDisbursed'] ?? 0);
$coverageRate = $loanPortfolio > 0 ? min(999, ($netSavings / $loanPortfolio) * 100) : 0;
$activeMemberRate = $totalMembers > 0 ? ($activeMembers / $totalMembers) * 100 : 0;

$months = monthBuckets();
$months = hydrateMonthly(
    $pdo,
    $months,
    "SELECT DATE_FORMAT(CreatedAt, '%Y-%m') AS MonthKey, COUNT(*) AS Value
     FROM members
     WHERE CreatedAt >= DATE_FORMAT(DATE_SUB(CURRENT_DATE(), INTERVAL 11 MONTH), '%Y-%m-01')
     GROUP BY DATE_FORMAT(CreatedAt, '%Y-%m')",
    'members'
);
$months = hydrateMonthly(
    $pdo,
    $months,
    "SELECT DATE_FORMAT(COALESCE(TranTime, CreatedAt), '%Y-%m') AS MonthKey, COALESCE(SUM(Amount), 0) AS Value
     FROM member_transactions
     WHERE TransactionType = 'contribution'
       AND COALESCE(TranTime, CreatedAt) >= DATE_FORMAT(DATE_SUB(CURRENT_DATE(), INTERVAL 11 MONTH), '%Y-%m-01')
     GROUP BY DATE_FORMAT(COALESCE(TranTime, CreatedAt), '%Y-%m')",
    'savings',
    true
);
$months = hydrateMonthly(
    $pdo,
    $months,
    "SELECT DATE_FORMAT(CreatedAt, '%Y-%m') AS MonthKey, COALESCE(SUM(Amount), 0) AS Value
     FROM loan_applications
     WHERE Status = 'Approved'
       AND CreatedAt >= DATE_FORMAT(DATE_SUB(CURRENT_DATE(), INTERVAL 11 MONTH), '%Y-%m-01')
     GROUP BY DATE_FORMAT(CreatedAt, '%Y-%m')",
    'loans',
    true
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Dashboard | Mashirikiano SACCO</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <style>
    .bi-hero {
      background: #28309a;
      color: #fff;
      border-radius: 8px;
      padding: 24px;
      box-shadow: 0 14px 36px rgba(40, 48, 154, .18);
    }
    .bi-hero h1 { font-size: 1.45rem; font-weight: 800; margin-bottom: 6px; }
    .bi-hero p { color: rgba(255,255,255,.78); margin: 0; }
    .metric-grid {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 14px;
    }
    .bi-metric {
      background: #fff;
      border-left: 4px solid #218c74;
      border-radius: 8px;
      box-shadow: 0 10px 28px rgba(13, 38, 67, .08);
      padding: 16px;
      min-height: 118px;
    }
    .bi-metric.orange { border-left-color: #f97316; }
    .bi-metric.purple { border-left-color: #7c3aed; }
    .bi-metric.blue { border-left-color: #1d72d2; }
    .bi-metric.red { border-left-color: #dc2626; }
    .bi-metric-label {
      color: #667085;
      font-size: .75rem;
      font-weight: 800;
      text-transform: uppercase;
      margin-bottom: 10px;
    }
    .bi-metric-value {
      color: #10253a;
      font-size: 1.55rem;
      font-weight: 900;
      line-height: 1.08;
    }
    .bi-metric-note {
      color: #758195;
      font-size: .78rem;
      margin-top: 8px;
    }
    .chart-card {
      background: #fff;
      border: 0;
      border-radius: 8px;
      box-shadow: 0 10px 28px rgba(13, 38, 67, .08);
    }
    .chart-card h2 {
      color: #344054;
      font-size: .98rem;
      font-weight: 800;
      margin: 0;
    }
    .admin-bars {
      height: 190px;
      display: grid;
      grid-template-columns: repeat(12, 1fr);
      align-items: end;
      gap: 10px;
      padding-top: 22px;
    }
    .admin-bar-wrap {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: end;
      height: 100%;
      gap: 8px;
      color: #7a8699;
      font-size: .72rem;
      font-weight: 700;
    }
    .admin-bar {
      width: min(28px, 90%);
      border-radius: 6px 6px 0 0;
      box-shadow: inset 0 1px rgba(255,255,255,.25);
    }
    .admin-line-chart {
      width: 100%;
      min-height: 220px;
    }
    .admin-line-chart line {
      stroke: #d8e0ea;
      stroke-width: 2;
    }
    .admin-line-chart polyline {
      fill: none;
      stroke-width: 5;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .savings-line { stroke: #218c74; }
    .loans-line { stroke: #f97316; }
    .chart-legend {
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
      color: #667085;
      font-size: .82rem;
      font-weight: 700;
    }
    .legend-dot {
      width: 12px;
      height: 12px;
      display: inline-block;
      border-radius: 3px;
      margin-right: 6px;
      vertical-align: -1px;
    }
    @media (max-width: 1200px) {
      .metric-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (max-width: 767px) {
      .metric-grid { grid-template-columns: 1fr; }
      .bi-hero { padding: 18px; }
      .admin-bars { gap: 5px; }
    }
  </style>
</head>
<body>
  <?php admin_header('dashboard', 'Executive overview'); ?>

  <main class="container-fluid admin-shell py-4">
    <section class="bi-hero mb-4">
      <h1>Business Intelligence Dashboard</h1>
      <p>Executive overview of SACCO performance metrics as at <?= e(date('d M Y H:i')) ?></p>
    </section>

    <section class="metric-grid mb-4">
      <div class="bi-metric blue">
        <div class="bi-metric-label">Total Members</div>
        <div class="bi-metric-value"><?= number_format($totalMembers) ?></div>
        <div class="bi-metric-note"><?= number_format($activeMembers) ?> active, <?= number_format($pendingMembers) ?> pending</div>
      </div>
      <div class="bi-metric">
        <div class="bi-metric-label">Total Contributions</div>
        <div class="bi-metric-value"><?= e(moneyShort($totalContributing)) ?></div>
        <div class="bi-metric-note"><?= e(moneyShort($thisMonthSavings)) ?> saved this month</div>
      </div>
      <div class="bi-metric blue">
        <div class="bi-metric-label">Net Savings</div>
        <div class="bi-metric-value"><?= e(moneyShort($netSavings)) ?></div>
        <div class="bi-metric-note">Contributing less withdrawals</div>
      </div>
      <div class="bi-metric purple">
        <div class="bi-metric-label">Share Capital</div>
        <div class="bi-metric-value"><?= e(moneyShort($shareCapital)) ?></div>
        <div class="bi-metric-note">Based on paid deposits</div>
      </div>
      <div class="bi-metric orange">
        <div class="bi-metric-label">Active Loans</div>
        <div class="bi-metric-value"><?= number_format($activeLoans) ?></div>
        <div class="bi-metric-note"><?= number_format($pendingLoans) ?> pending approvals</div>
      </div>
      <div class="bi-metric">
        <div class="bi-metric-label">Loan Portfolio</div>
        <div class="bi-metric-value"><?= e(moneyShort($loanPortfolio)) ?></div>
        <div class="bi-metric-note"><?= e(moneyShort($thisMonthDisbursed)) ?> disbursed this month</div>
      </div>
      <div class="bi-metric">
        <div class="bi-metric-label">Savings Cover</div>
        <div class="bi-metric-value"><?= number_format($coverageRate, 1) ?>%</div>
        <div class="bi-metric-note">Savings compared with approved loans</div>
      </div>
      <div class="bi-metric red">
        <div class="bi-metric-label">Pending Loan Requests</div>
        <div class="bi-metric-value"><?= number_format($pendingLoans) ?></div>
        <div class="bi-metric-note">Awaiting admin action</div>
      </div>
      <div class="bi-metric blue">
        <div class="bi-metric-label">Active Member Rate</div>
        <div class="bi-metric-value"><?= number_format($activeMemberRate, 1) ?>%</div>
        <div class="bi-metric-note">Active members out of total membership</div>
      </div>
    </section>

    <div class="row g-4">
      <section class="col-xl-5">
        <div class="card chart-card h-100">
          <div class="card-body">
            <h2>Membership Growth (12 Months)</h2>
            <?= barChart($months, 'members', '#27339a') ?>
          </div>
        </div>
      </section>
      <section class="col-xl-7">
        <div class="card chart-card h-100">
          <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
              <h2>Savings and Loan Disbursements (12 Months)</h2>
              <div class="chart-legend">
                <span><span class="legend-dot" style="background:#218c74;"></span>Contributions</span>
                <span><span class="legend-dot" style="background:#f97316;"></span>Loans</span>
              </div>
            </div>
            <?= lineChart($months, 'savings', 'loans') ?>
          </div>
        </div>
      </section>
    </div>

    <section class="card chart-card mt-4">
      <div class="card-body">
        <h2 class="mb-3">Savings Position Analysis</h2>
        <div class="row g-3">
          <div class="col-md-4">
            <div class="text-muted small">Total Withdrawals</div>
            <div class="h4 fw-bold mb-1"><?= e(moneyShort($totalWithdrawals)) ?></div>
            <div class="text-muted small">Cash released from contributions</div>
          </div>
          <div class="col-md-4">
            <div class="text-muted small">Savings Retained</div>
            <div class="h4 fw-bold mb-1"><?= number_format(max(0, 100 - $withdrawalRate), 1) ?>%</div>
            <div class="text-muted small">Share of contributions still retained</div>
          </div>
          <div class="col-md-4">
            <div class="text-muted small">Withdrawal Rate</div>
            <div class="h4 fw-bold mb-1"><?= number_format($withdrawalRate, 1) ?>%</div>
            <div class="text-muted small">Withdrawals compared with contributions</div>
          </div>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
