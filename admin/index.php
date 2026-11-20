<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/LoanService.php';
require_once __DIR__ . '/admin_layout.php';

Auth::requireAdmin();

try {
    $pdo = Database::connection();
    $pdo->query('SELECT 1 FROM members LIMIT 1');
    $pdo->query('SELECT 1 FROM deposits LIMIT 1');
    $pdo->query('SELECT 1 FROM member_transactions LIMIT 1');

    LoanService::ensureTables($pdo);
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

function moneyAxisLabel(float $amount): string
{
    if (abs($amount) < 1000) {
        return number_format($amount, abs($amount) < 10 ? 1 : 0);
    }

    return moneyShort($amount);
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
            'withdrawals' => 0.0,
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

function chartScale(float $maximum, int $steps = 4, bool $integerValues = false): array
{
    $maximum = max(1, $maximum);
    $rawStep = $maximum / max(1, $steps);
    $magnitude = pow(10, floor(log10($rawStep)));
    $fraction = $rawStep / $magnitude;
    $step = ($fraction <= 1 ? 1 : ($fraction <= 2 ? 2 : ($fraction <= 5 ? 5 : 10))) * $magnitude;
    if ($integerValues) {
        $step = max(1, ceil($step));
    }
    $scaleMaximum = $integerValues
        ? max($maximum, ceil($maximum / $step) * $step)
        : max($step * $steps, ceil($maximum / $step) * $step);
    $tickCount = (int)round($scaleMaximum / $step);
    $ticks = [];
    for ($index = 0; $index <= $tickCount; $index++) {
        $ticks[] = $index * $step;
    }

    return ['max' => $scaleMaximum, 'ticks' => $ticks];
}

function barChart(array $months, string $key, string $color, string $unitLabel): string
{
    $maximum = max(array_map(static fn(array $month): float => (float)$month[$key], array_values($months)) ?: [0.0]);
    $scale = chartScale($maximum, 4, true);
    $width = 780;
    $height = 300;
    $left = 66;
    $right = 16;
    $top = 20;
    $bottom = 48;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $count = max(1, count($months));
    $slotWidth = $plotWidth / $count;

    ob_start(); ?>
      <svg class="analysis-chart" viewBox="0 0 <?= $width ?> <?= $height ?>" role="img" aria-label="<?= e($unitLabel) ?> by month for the last 12 months">
        <?php foreach ($scale['ticks'] as $tick): ?>
          <?php $y = $top + $plotHeight - (($tick / $scale['max']) * $plotHeight); ?>
          <line class="chart-gridline" x1="<?= $left ?>" y1="<?= e(number_format($y, 2, '.', '')) ?>" x2="<?= $width - $right ?>" y2="<?= e(number_format($y, 2, '.', '')) ?>" />
          <text class="chart-tick" x="<?= $left - 10 ?>" y="<?= e(number_format($y + 4, 2, '.', '')) ?>" text-anchor="end"><?= e(number_format((float)$tick)) ?></text>
        <?php endforeach; ?>
        <line class="chart-axis" x1="<?= $left ?>" y1="<?= $top ?>" x2="<?= $left ?>" y2="<?= $top + $plotHeight ?>" />
        <line class="chart-axis" x1="<?= $left ?>" y1="<?= $top + $plotHeight ?>" x2="<?= $width - $right ?>" y2="<?= $top + $plotHeight ?>" />
        <text class="chart-axis-title" transform="translate(17 <?= $top + ($plotHeight / 2) ?>) rotate(-90)" text-anchor="middle"><?= e($unitLabel) ?></text>
        <?php foreach (array_values($months) as $index => $month): ?>
          <?php
            $value = (float)$month[$key];
            $barHeight = ($value / $scale['max']) * $plotHeight;
            $x = $left + ($slotWidth * $index) + ($slotWidth * .22);
            $barWidth = $slotWidth * .56;
            $y = $top + $plotHeight - $barHeight;
            $centerX = $x + ($barWidth / 2);
          ?>
          <rect x="<?= e(number_format($x, 2, '.', '')) ?>" y="<?= e(number_format($y, 2, '.', '')) ?>" width="<?= e(number_format($barWidth, 2, '.', '')) ?>" height="<?= e(number_format(max(1, $barHeight), 2, '.', '')) ?>" rx="3" fill="<?= e($color) ?>">
            <title><?= e($month['label']) ?>: <?= e(number_format($value)) ?> <?= e(strtolower($unitLabel)) ?></title>
          </rect>
          <text class="chart-tick" x="<?= e(number_format($centerX, 2, '.', '')) ?>" y="<?= $height - 18 ?>" text-anchor="middle"><?= e($month['label']) ?></text>
          <?php if ($value > 0): ?><text class="chart-value" x="<?= e(number_format($centerX, 2, '.', '')) ?>" y="<?= e(number_format(max($top + 11, $y - 6), 2, '.', '')) ?>" text-anchor="middle"><?= e(number_format($value)) ?></text><?php endif; ?>
        <?php endforeach; ?>
      </svg>
    <?php return trim((string)ob_get_clean());
}

function lineChart(array $months, array $series): string
{
    $width = 860;
    $height = 330;
    $left = 82;
    $right = 20;
    $top = 22;
    $bottom = 54;
    $maximum = 0.0;
    foreach ($months as $month) {
        foreach ($series as $item) {
            $maximum = max($maximum, (float)$month[$item['key']]);
        }
    }
    $scale = chartScale($maximum);
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;

    $labels = array_values($months);
    $count = max(1, count($labels) - 1);

    ob_start(); ?>
      <svg class="analysis-chart" viewBox="0 0 <?= $width ?> <?= $height ?>" role="img" aria-label="Monthly contributions, withdrawals, and loan disbursements in Kenyan shillings">
        <?php foreach ($scale['ticks'] as $tick): ?>
          <?php $y = $top + $plotHeight - (($tick / $scale['max']) * $plotHeight); ?>
          <line class="chart-gridline" x1="<?= $left ?>" y1="<?= e(number_format($y, 2, '.', '')) ?>" x2="<?= $width - $right ?>" y2="<?= e(number_format($y, 2, '.', '')) ?>" />
          <text class="chart-tick" x="<?= $left - 10 ?>" y="<?= e(number_format($y + 4, 2, '.', '')) ?>" text-anchor="end"><?= e(moneyAxisLabel((float)$tick)) ?></text>
        <?php endforeach; ?>
        <line class="chart-axis" x1="<?= $left ?>" y1="<?= $top ?>" x2="<?= $left ?>" y2="<?= $top + $plotHeight ?>" />
        <line class="chart-axis" x1="<?= $left ?>" y1="<?= $top + $plotHeight ?>" x2="<?= $width - $right ?>" y2="<?= $top + $plotHeight ?>" />
        <text class="chart-axis-title" transform="translate(17 <?= $top + ($plotHeight / 2) ?>) rotate(-90)" text-anchor="middle">Amount (KES)</text>
        <?php foreach ($labels as $index => $month): ?>
          <?php $x = $left + ($plotWidth * ($index / $count)); ?>
          <text class="chart-tick" x="<?= e(number_format($x, 2, '.', '')) ?>" y="<?= $height - 18 ?>" text-anchor="middle"><?= e($month['label']) ?></text>
        <?php endforeach; ?>
        <?php foreach ($series as $item): ?>
          <?php
            $points = [];
            foreach ($labels as $index => $month) {
                $x = $left + ($plotWidth * ($index / $count));
                $y = $top + $plotHeight - (((float)$month[$item['key']] / $scale['max']) * $plotHeight);
                $points[] = [number_format($x, 2, '.', ''), number_format($y, 2, '.', ''), (float)$month[$item['key']], $month['label']];
            }
          ?>
          <polyline class="chart-series" points="<?= e(implode(' ', array_map(static fn(array $point): string => $point[0] . ',' . $point[1], $points))) ?>" stroke="<?= e($item['color']) ?>" />
          <?php foreach ($points as [$x, $y, $value, $label]): ?>
            <circle cx="<?= e($x) ?>" cy="<?= e($y) ?>" r="4" fill="<?= e($item['color']) ?>"><title><?= e($item['label']) ?> — <?= e($label) ?>: KES <?= e(number_format($value, 2)) ?></title></circle>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </svg>
    <?php return trim((string)ob_get_clean());
}

function donutChart(array $segments, string $centerValue, string $centerLabel, string $ariaLabel): string
{
    $total = array_sum(array_map(static fn(array $segment): float => max(0, (float)$segment['value']), $segments));
    $radius = 74;
    $circumference = 2 * M_PI * $radius;
    $offset = 0.0;

    ob_start(); ?>
      <svg class="donut-chart" viewBox="0 0 220 220" role="img" aria-label="<?= e($ariaLabel) ?>">
        <g transform="rotate(-90 110 110)">
          <?php if ($total <= 0): ?><circle cx="110" cy="110" r="<?= $radius ?>" fill="none" stroke="#d9e0e8" stroke-width="28" /><?php endif; ?>
          <?php foreach ($segments as $segment): ?>
            <?php
              $value = max(0, (float)$segment['value']);
              $length = $total > 0 ? ($value / $total) * $circumference : 0;
              $dashOffset = -$offset;
              $offset += $length;
            ?>
            <?php if ($length > 0): ?><circle cx="110" cy="110" r="<?= $radius ?>" fill="none" stroke="<?= e($segment['color']) ?>" stroke-width="28" stroke-dasharray="<?= e(number_format($length, 2, '.', '')) ?> <?= e(number_format($circumference - $length, 2, '.', '')) ?>" stroke-dashoffset="<?= e(number_format($dashOffset, 2, '.', '')) ?>"><title><?= e($segment['label']) ?>: <?= e(number_format($value)) ?> (<?= e(number_format(($value / $total) * 100, 1)) ?>%)</title></circle><?php endif; ?>
          <?php endforeach; ?>
        </g>
        <text class="donut-center-value" x="110" y="104" text-anchor="middle"><?= e($centerValue) ?></text>
        <text class="donut-center-label" x="110" y="128" text-anchor="middle"><?= e($centerLabel) ?></text>
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

$memberStatusStats = ['Active' => 0, 'Pending' => 0, 'Suspended' => 0, 'Deleted' => 0];
try {
    foreach ($pdo->query('SELECT Status, COUNT(*) AS Members FROM members GROUP BY Status')->fetchAll() as $statusRow) {
        $status = (string)$statusRow['Status'];
        if (array_key_exists($status, $memberStatusStats)) {
            $memberStatusStats[$status] = (int)$statusRow['Members'];
        }
    }
} catch (Throwable $e) {}

$transactionStats = $pdo->query(
    "SELECT
    COALESCE(SUM(CASE WHEN TransactionType = 'contribution' THEN Amount ELSE 0 END), 0) AS TotalContributing,
    COALESCE(SUM(CASE WHEN TransactionType = 'withdrawal' THEN Amount ELSE 0 END), 0) AS TotalWithdrawals,
        COALESCE(SUM(CASE WHEN TransactionType = 'deposit' THEN Amount ELSE 0 END), 0) AS TotalDeposits,
        COALESCE(SUM(CASE WHEN TransactionType = 'contribution' AND DATE_FORMAT(COALESCE(TranTime, CreatedAt), '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m') THEN Amount ELSE 0 END), 0) AS ThisMonthSavings
     FROM member_transactions"
)->fetch() ?: [];

$depositStats = $pdo->query(
    'SELECT COALESCE(SUM(PaidAmount), 0) AS EntranceFeesReserve FROM deposits'
)->fetch() ?: [];

$loanStats = [
    'TotalLoanRequests'  => 0,
    'PendingLoans'       => 0,
    'ActiveLoans'        => 0,
    'LoanPortfolio'      => 0.0,
    'ThisMonthDisbursed' => 0.0,
];
$loanStatusStats = [
    'ACTIVE' => ['count' => 0, 'balance' => 0.0],
    'IN_ARREARS' => ['count' => 0, 'balance' => 0.0],
    'CLOSED' => ['count' => 0, 'balance' => 0.0],
];

try {
    // 1. Applications stats from loan_application
    $appStats = $pdo->query(
        "SELECT
            COUNT(*) AS TotalLoanRequests,
            SUM(CASE WHEN UPPER(status) = 'PENDING' THEN 1 ELSE 0 END) AS PendingLoans
         FROM loan_application"
    )->fetch() ?: [];
    $loanStats['TotalLoanRequests'] = (int)($appStats['TotalLoanRequests'] ?? 0);
    $loanStats['PendingLoans']      = (int)($appStats['PendingLoans'] ?? 0);

    // 2. Active loan portfolio and disbursement stats from active_loan
    $activeStats = $pdo->query(
        "SELECT
            COUNT(*) AS ActiveLoans,
            COALESCE(SUM(principalBalance), 0) AS LoanPortfolio,
            COALESCE(SUM(CASE WHEN DATE_FORMAT(disbursementDate, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m') THEN originalPrincipal ELSE 0 END), 0) AS ThisMonthDisbursed
         FROM active_loan
         WHERE loanStatus IN ('ACTIVE', 'IN_ARREARS')"
    )->fetch() ?: [];
    $loanStats['ActiveLoans']        = (int)($activeStats['ActiveLoans'] ?? 0);
    $loanStats['LoanPortfolio']      = (float)($activeStats['LoanPortfolio'] ?? 0);
    $loanStats['ThisMonthDisbursed'] = (float)($activeStats['ThisMonthDisbursed'] ?? 0);

    $statusStmt = $pdo->query(
        "SELECT UPPER(loanStatus) AS LoanStatus, COUNT(*) AS LoanCount,
                COALESCE(SUM(principalBalance), 0) AS PrincipalBalance
         FROM active_loan GROUP BY UPPER(loanStatus)"
    );
    foreach ($statusStmt->fetchAll() as $statusRow) {
        $status = (string)$statusRow['LoanStatus'];
        $loanStatusStats[$status] = [
            'count' => (int)$statusRow['LoanCount'],
            'balance' => (float)$statusRow['PrincipalBalance'],
        ];
    }
} catch (Throwable $e) {
    // Keep dashboard loan totals at zero if the loan engine tables are unavailable.
}

$totalMembers = (int)($memberStats['TotalMembers'] ?? 0);
$activeMembers = (int)($memberStats['ActiveMembers'] ?? 0);
$pendingMembers = (int)($memberStats['PendingMembers'] ?? 0);
$totalContributing = (float)($transactionStats['TotalContributing'] ?? 0);
$totalWithdrawals = (float)($transactionStats['TotalWithdrawals'] ?? 0);

// Fetch SaccoWallets and withdrawals totals
$walletStats = [];
try {
    $walletStats = $pdo->query(
        "SELECT
            COALESCE(SUM(Amount), 0) AS TotalContributions,
            COALESCE(SUM(AkibaAmount), 0) AS TotalAkiba,
            COALESCE(SUM(UshirikaAmount), 0) AS TotalUshirika
         FROM SaccoWallets"
    )->fetch() ?: [];
} catch (Throwable $e) {}

$withdrawalTableStats = [];
try {
    $withdrawalTableStats = $pdo->query(
        "SELECT COALESCE(SUM(Amount), 0) AS TotalWithdrawals FROM withdrawals"
    )->fetch() ?: [];
} catch (Throwable $e) {}

$totalAkiba = (float)($walletStats['TotalAkiba'] ?? 0);
$totalUshirika = (float)($walletStats['TotalUshirika'] ?? 0);
$totalWithdrawalsFromTable = (float)($withdrawalTableStats['TotalWithdrawals'] ?? 0);

if ($totalAkiba > 0 || $totalUshirika > 0) {
    $netSavings = max(0.0, $totalAkiba - $totalWithdrawalsFromTable);
    if ($totalWithdrawalsFromTable > 0) {
        $totalWithdrawals = $totalWithdrawalsFromTable;
    }
} else {
    $totalAkiba = round($totalContributing * 0.95, 2);
    $totalUshirika = round($totalContributing * 0.05, 2);
    $netSavings = max(0.0, $totalContributing - $totalWithdrawals);
}

$withdrawalRate = $totalContributing > 0 ? ($totalWithdrawals / $totalContributing) * 100 : null;
$entranceFeesReserve = (float)($depositStats['EntranceFeesReserve'] ?? 0);
$shareCapital = $entranceFeesReserve;
$activeLoans = (int)($loanStats['ActiveLoans'] ?? 0);
$loanPortfolio = (float)($loanStats['LoanPortfolio'] ?? 0);
$pendingLoans = (int)($loanStats['PendingLoans'] ?? 0);
$thisMonthSavings = (float)($transactionStats['ThisMonthSavings'] ?? 0);
$thisMonthDisbursed = (float)($loanStats['ThisMonthDisbursed'] ?? 0);
$coverageRate = $loanPortfolio > 0 ? ($netSavings / $loanPortfolio) * 100 : null;
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
    "SELECT DATE_FORMAT(COALESCE(TranTime, CreatedAt), '%Y-%m') AS MonthKey, COALESCE(SUM(Amount), 0) AS Value
     FROM member_transactions
     WHERE TransactionType = 'withdrawal'
       AND COALESCE(TranTime, CreatedAt) >= DATE_FORMAT(DATE_SUB(CURRENT_DATE(), INTERVAL 11 MONTH), '%Y-%m-01')
     GROUP BY DATE_FORMAT(COALESCE(TranTime, CreatedAt), '%Y-%m')",
    'withdrawals',
    true
);
try {
    $months = hydrateMonthly(
        $pdo,
        $months,
        "SELECT DATE_FORMAT(disbursementDate, '%Y-%m') AS MonthKey, COALESCE(SUM(originalPrincipal), 0) AS Value
         FROM active_loan
         WHERE disbursementDate >= DATE_FORMAT(DATE_SUB(CURRENT_DATE(), INTERVAL 11 MONTH), '%Y-%m-01')
         GROUP BY DATE_FORMAT(disbursementDate, '%Y-%m')",
        'loans',
        true
    );
} catch (Throwable $e) {
    // Leave loan chart values empty if the loan engine table is unavailable.
}

$memberSegments = [
    ['label' => 'Active', 'value' => $memberStatusStats['Active'], 'color' => '#218c74'],
    ['label' => 'Pending', 'value' => $memberStatusStats['Pending'], 'color' => '#f97316'],
    ['label' => 'Suspended', 'value' => $memberStatusStats['Suspended'], 'color' => '#dc3545'],
    ['label' => 'Deleted', 'value' => $memberStatusStats['Deleted'], 'color' => '#98a2b3'],
];
$loanPortfolioSegments = [
    ['label' => 'Active', 'value' => $loanStatusStats['ACTIVE']['balance'], 'count' => $loanStatusStats['ACTIVE']['count'], 'color' => '#1d72d2'],
    ['label' => 'In arrears', 'value' => $loanStatusStats['IN_ARREARS']['balance'], 'count' => $loanStatusStats['IN_ARREARS']['count'], 'color' => '#dc3545'],
];
$savingsSegments = [
    ['label' => 'Akiba net savings', 'value' => $netSavings, 'color' => '#e47001'],
    ['label' => 'Ushirika shares', 'value' => $totalUshirika, 'color' => '#489a12'],
];
$totalLoanRequests = (int)($loanStats['TotalLoanRequests'] ?? 0);
$pendingLoanRate = $totalLoanRequests > 0 ? ($pendingLoans / $totalLoanRequests) * 100 : null;
$memberFundTotal = $netSavings + $totalUshirika;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Dashboard | Mashirikiano SACCO</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="admin.css?v=20261120-7" rel="stylesheet">
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
    .bi-metric.akiba { border-left-color: #e47001; }
    .bi-metric.ushirika { border-left-color: #489a12; }
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
    .analysis-chart { display:block; width:100%; height:auto; overflow:visible; }
    .chart-gridline { stroke:#e5eaf0; stroke-width:1; }
    .chart-axis { stroke:#8290a2; stroke-width:1.5; }
    .chart-tick { fill:#667085; font-size:12px; font-family:inherit; }
    .chart-axis-title { fill:#475467; font-size:12px; font-weight:700; font-family:inherit; }
    .chart-value { fill:#475467; font-size:10px; font-weight:700; font-family:inherit; }
    .chart-series { fill:none; stroke-width:3.5; stroke-linecap:round; stroke-linejoin:round; }
    .donut-layout { display:flex; align-items:center; justify-content:center; gap:28px; min-height:280px; }
    .donut-chart { width:min(230px, 48%); height:auto; flex:0 0 auto; }
    .donut-center-value { fill:#10253a; font-size:22px; font-weight:900; font-family:inherit; }
    .donut-center-label { fill:#667085; font-size:11px; font-weight:700; font-family:inherit; }
    .chart-legend {
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
      color: #667085;
      font-size: .82rem;
      font-weight: 700;
    }
    .chart-legend.stacked { flex-direction:column; gap:12px; min-width:155px; }
    .chart-legend-item { display:flex; align-items:flex-start; gap:8px; }
    .chart-legend-copy { display:flex; flex-direction:column; gap:2px; }
    .chart-legend-value { color:#344054; font-size:.78rem; font-weight:600; }
    .legend-dot {
      width: 12px;
      height: 12px;
      display: inline-block;
      border-radius: 3px;
      margin-right: 6px;
      vertical-align: -1px;
    }
    .analysis-note { color:#667085; font-size:.8rem; margin-top:10px; }
    .decision-card { border-left:4px solid #1d72d2; border-radius:6px; background:#f7f9fc; padding:14px; height:100%; }
    .decision-label { color:#667085; font-size:.72rem; font-weight:800; text-transform:uppercase; }
    .decision-value { color:#10253a; font-size:1.35rem; font-weight:900; margin:.2rem 0; }
    .decision-note { color:#667085; font-size:.78rem; margin:0; }
    @media (max-width: 1200px) {
      .metric-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (max-width: 767px) {
      .metric-grid { grid-template-columns: 1fr; }
      .bi-hero { padding: 18px; }
      .donut-layout { flex-direction:column; gap:12px; }
      .donut-chart { width:min(220px, 80%); }
      .chart-legend.stacked { width:100%; }
    }
  </style>
</head>
<body>
  <?php admin_header('dashboard', 'Executive overview'); ?>

  <main class="container-fluid admin-shell py-4">
    <section class="bi-hero mb-4">
      <h1>SACCO Performance &amp; Decision Analysis</h1>
      <p>Financial, membership, and lending trends as at <?= e(date('d M Y H:i')) ?></p>
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
      <div class="bi-metric akiba">
        <div class="bi-metric-label wallet-akiba">Akiba Wallet (Net Savings)</div>
        <div class="bi-metric-value wallet-akiba"><?= e(moneyShort($netSavings)) ?></div>
        <div class="bi-metric-note">Akiba funds less withdrawals</div>
      </div>
      <div class="bi-metric ushirika">
        <div class="bi-metric-label wallet-ushirika">Ushirika Shares</div>
        <div class="bi-metric-value wallet-ushirika"><?= e(moneyShort($totalUshirika)) ?></div>
        <div class="bi-metric-note">5% allocated member shares</div>
      </div>
      <div class="bi-metric purple">
        <div class="bi-metric-label">Entrance Fees Reserve</div>
        <div class="bi-metric-value"><?= e(moneyShort($entranceFeesReserve)) ?></div>
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
        <div class="bi-metric-value"><?= $coverageRate === null ? 'N/A' : number_format($coverageRate, 1) . '%' ?></div>
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
      <section class="col-xl-6">
        <div class="card chart-card h-100"><div class="card-body">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h2>New Member Registrations by Month</h2><div class="chart-legend"><span><span class="legend-dot" style="background:#27339a;"></span>New registrations</span></div></div>
          <p class="analysis-note">Monthly member accounts created over the last 12 months. Vertical scale: members.</p>
          <?= barChart($months, 'members', '#27339a', 'Members') ?>
        </div></div>
      </section>
      <section class="col-xl-6">
        <div class="card chart-card h-100"><div class="card-body">
          <h2>Current Membership Status</h2>
          <p class="analysis-note">Member count by current account status; percentages are of all members.</p>
          <div class="donut-layout">
            <?= donutChart($memberSegments, number_format($totalMembers), 'members', 'Member status breakdown') ?>
            <div class="chart-legend stacked">
              <?php foreach ($memberSegments as $segment): ?>
                <?php $share = $totalMembers > 0 ? ((float)$segment['value'] / $totalMembers) * 100 : 0; ?>
                <div class="chart-legend-item"><span class="legend-dot" style="background:<?= e($segment['color']) ?>;"></span><span class="chart-legend-copy"><span><?= e($segment['label']) ?></span><span class="chart-legend-value"><?= number_format((int)$segment['value']) ?> members · <?= number_format($share, 1) ?>%</span></span></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div></div>
      </section>
    </div>

    <section class="card chart-card mt-4"><div class="card-body">
      <div class="d-flex flex-wrap justify-content-between gap-3 mb-2">
        <h2>Monthly SACCO Cash Flows</h2>
        <div class="chart-legend">
          <span><span class="legend-dot" style="background:#218c74;"></span>Contributions</span>
          <span><span class="legend-dot" style="background:#dc3545;"></span>Withdrawals</span>
          <span><span class="legend-dot" style="background:#f97316;"></span>Loan disbursements</span>
        </div>
      </div>
      <p class="analysis-note">Monthly transaction totals for the last 12 months. Vertical scale: KES. Hover chart points for exact values.</p>
      <?= lineChart($months, [
          ['key' => 'savings', 'label' => 'Contributions', 'color' => '#218c74'],
          ['key' => 'withdrawals', 'label' => 'Withdrawals', 'color' => '#dc3545'],
          ['key' => 'loans', 'label' => 'Loan disbursements', 'color' => '#f97316'],
      ]) ?>
    </div></section>

    <div class="row g-4 mt-0">
      <section class="col-xl-6">
        <div class="card chart-card h-100"><div class="card-body">
          <h2>Outstanding Loan Principal by Status</h2>
          <p class="analysis-note">Principal balance distribution for currently active and in-arrears loans. Shares use outstanding principal.</p>
          <div class="donut-layout">
            <?= donutChart($loanPortfolioSegments, moneyShort($loanPortfolio), 'KES principal', 'Outstanding principal by loan status') ?>
            <div class="chart-legend stacked">
              <?php foreach ($loanPortfolioSegments as $segment): ?>
                <?php $share = $loanPortfolio > 0 ? ((float)$segment['value'] / $loanPortfolio) * 100 : 0; ?>
                <div class="chart-legend-item"><span class="legend-dot" style="background:<?= e($segment['color']) ?>;"></span><span class="chart-legend-copy"><span><?= e($segment['label']) ?></span><span class="chart-legend-value"><?= number_format((int)$segment['count']) ?> loans · KES <?= number_format((float)$segment['value'], 2) ?> · <?= number_format($share, 1) ?>%</span></span></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div></div>
      </section>
      <section class="col-xl-6">
        <div class="card chart-card h-100"><div class="card-body">
          <h2>Member Savings Composition</h2>
          <p class="analysis-note">Current net Akiba savings and Ushirika shares. Each slice shows its share of total member funds.</p>
          <div class="donut-layout">
            <?= donutChart($savingsSegments, moneyShort($memberFundTotal), 'KES member funds', 'Member funds split between net Akiba savings and Ushirika shares') ?>
            <div class="chart-legend stacked">
              <?php foreach ($savingsSegments as $segment): ?>
                <?php $share = $memberFundTotal > 0 ? ((float)$segment['value'] / $memberFundTotal) * 100 : 0; ?>
                <div class="chart-legend-item"><span class="legend-dot" style="background:<?= e($segment['color']) ?>;"></span><span class="chart-legend-copy"><span><?= e($segment['label']) ?></span><span class="chart-legend-value">KES <?= number_format((float)$segment['value'], 2) ?> · <?= number_format($share, 1) ?>%</span></span></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div></div>
      </section>
    </div>

    <section class="card chart-card mt-4"><div class="card-body">
      <h2 class="mb-3">Decision Indicators</h2>
      <div class="row g-3">
        <div class="col-md-6 col-xl-3"><div class="decision-card"><div class="decision-label">Active member rate</div><div class="decision-value"><?= number_format($activeMemberRate, 1) ?>%</div><p class="decision-note"><?= number_format($activeMembers) ?> active of <?= number_format($totalMembers) ?> total members.</p></div></div>
        <div class="col-md-6 col-xl-3"><div class="decision-card"><div class="decision-label">Withdrawal rate</div><div class="decision-value"><?= $withdrawalRate === null ? 'N/A' : number_format($withdrawalRate, 1) . '%' ?></div><p class="decision-note">Total withdrawals divided by lifetime contributions<?= $totalContributing <= 0 ? '; no contribution base recorded' : '' ?>.</p></div></div>
        <div class="col-md-6 col-xl-3"><div class="decision-card"><div class="decision-label">Savings cover</div><div class="decision-value"><?= $coverageRate === null ? 'N/A' : number_format($coverageRate, 1) . '%' ?></div><p class="decision-note">Net Akiba savings divided by outstanding loan principal<?= $loanPortfolio <= 0 ? '; no current principal balance' : '' ?>.</p></div></div>
        <div class="col-md-6 col-xl-3"><div class="decision-card"><div class="decision-label">Pending loan requests</div><div class="decision-value"><?= number_format($pendingLoans) ?></div><p class="decision-note"><?= $pendingLoanRate === null ? 'N/A' : number_format($pendingLoanRate, 1) . '%' ?> of <?= number_format($totalLoanRequests) ?> recorded loan applications are pending.</p></div></div>
      </div>
      <p class="analysis-note mb-0">These indicators summarize current recorded balances and transaction history; they are descriptive measures, not policy thresholds.</p>
    </div></section>
  </main>
</body>
</html>
