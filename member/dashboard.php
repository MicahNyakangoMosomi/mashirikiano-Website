<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/MemberService.php';
require_once __DIR__ . '/../classes/SmsService.php';
require_once __DIR__ . '/../classes/LoanService.php';

$member = Auth::requireMember();
Auth::startSession();
$pdo = Database::connection();
LoanService::ensureTables($pdo);

$message = '';
$messageType = 'info';

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_message_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
}

$rawTab = $_GET['tab'] ?? 'dashboard';
if ($rawTab === 'loan') {
    $rawTab = 'catalog';
} elseif ($rawTab === 'applications') {
    $rawTab = 'progress';
}
$activeTab = in_array($rawTab, ['dashboard', 'catalog', 'progress', 'active', 'ledger', 'record'], true) ? $rawTab : 'dashboard';

// -------------------------------------------------------------
// Handle Action: Submit Loan Application (View 1)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_loan'])) {
    try {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $returnDate = trim((string)($_POST['return_date'] ?? ''));

        if ($loanId <= 0) {
            throw new Exception("Please select a valid loan option from the catalog.");
        }

        $appId = LoanService::submitApplication($pdo, (string)$member['MemberID'], $loanId, $amount, $returnDate);

        $_SESSION['flash_message'] = "Your loan application #$appId has been submitted successfully!";
        $_SESSION['flash_message_type'] = 'success';
        header("Location: dashboard.php?tab=progress");
        exit;
    } catch (Throwable $e) {
        $_SESSION['flash_message'] = $e->getMessage();
        $_SESSION['flash_message_type'] = 'danger';
        header("Location: dashboard.php?tab=catalog");
        exit;
    }
}

// -------------------------------------------------------------
// Handle Action: Refresh Loan State (View 3 Accrual Trigger)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_loan_state'])) {
    try {
        $activeLoanId = (int)($_POST['active_loan_id'] ?? 0);
        $updatedLoan = LoanService::catchUpInterest($pdo, $activeLoanId);

        $_SESSION['flash_message'] = "Loan state refreshed! Rolling accrued interest is up-to-date (Current Accrued: KES " . number_format((float)$updatedLoan['accruedInterest'], 2) . ").";
        $_SESSION['flash_message_type'] = 'success';
    } catch (Throwable $e) {
        $_SESSION['flash_message'] = "Failed to refresh loan state: " . $e->getMessage();
        $_SESSION['flash_message_type'] = 'danger';
    }
    header("Location: dashboard.php?tab=active");
    exit;
}

// Fetch member wallet balances from service
$wallets = LoanService::getMemberWalletBalances($pdo, (string)$member['MemberID']);
$akibaWallet = $wallets['akiba'];
$totalUshirika = $wallets['ushirika'];
$totalContributions = $wallets['totalContributions'];

// Total withdrawals
$wStmt = $pdo->prepare("SELECT COALESCE(SUM(Amount), 0) FROM withdrawals WHERE MemberID = :member_id");
$totalWithdrawals = 0.00;
try {
    $wStmt->execute([':member_id' => $member['MemberID']]);
    $totalWithdrawals = (float)$wStmt->fetchColumn();
} catch (Throwable $e) {}

// Active loan balance (Principal + Interest + Penalties)
$actBalStmt = $pdo->prepare("
    SELECT COALESCE(SUM(principalBalance + accruedInterest + billedUnpaidInterest + penaltyBalance), 0)
    FROM active_loan
    WHERE memberID = :mid AND loanStatus IN ('ACTIVE', 'IN_ARREARS')
");
$actBalStmt->execute([':mid' => $member['MemberID']]);
$totalLoanBalance = (float)$actBalStmt->fetchColumn();

// Recent general transactions for dashboard
$recentStmt = $pdo->prepare("SELECT * FROM member_transactions WHERE MemberID = :member_id AND TransactionType IN ('contribution', 'withdrawal', 'loan_repayment') ORDER BY COALESCE(TranTime, CreatedAt) DESC LIMIT 8");
$recentStmt->execute([':member_id' => $member['MemberID']]);
$recentTransactions = $recentStmt->fetchAll();

// View 1 Data: Available Products & Eligibility
$catalogProducts = $pdo->query("SELECT * FROM `loan` ORDER BY `maxAmount` ASC")->fetchAll();
$productEligibility = [];
foreach ($catalogProducts as $cp) {
    $productEligibility[$cp['loanID']] = LoanService::checkEligibility($pdo, (string)$member['MemberID'], (int)$cp['loanID']);
}

// View 2 Data: Submitted Applications & Workflow Stepper
$appsStmt = $pdo->prepare("
    SELECT la.*, l.paymentPeriod, l.loanInterest, l.interestType
    FROM loan_application la
    LEFT JOIN loan l ON l.loanID = la.loanID
    WHERE la.memberID = :mid
    ORDER BY la.loanApplicationID DESC
");
$appsStmt->execute([':mid' => $member['MemberID']]);
$memberApplications = $appsStmt->fetchAll();

// View 3 Data: Active Loans & Live Operational Ledger
$activeLoansStmt = $pdo->prepare("
    SELECT al.*, la.loanType, la.amount as requestedAmount, l.paymentPeriod
    FROM active_loan al
    JOIN loan_application la ON la.loanApplicationID = al.loanApplicationID
    LEFT JOIN loan l ON l.loanID = la.loanID
    WHERE al.memberID = :mid
    ORDER BY FIELD(al.loanStatus, 'IN_ARREARS', 'ACTIVE', 'CLOSED'), al.activeLoanID DESC
");
$activeLoansStmt->execute([':mid' => $member['MemberID']]);
$memberActiveLoans = $activeLoansStmt->fetchAll();

// View 4 Data: Repayment Ledger & Waterfall History
$ledgerStmt = $pdo->prepare("
    SELECT lt.*, al.loanStatus, al.originalPrincipal, la.loanType
    FROM loan_transaction lt
    JOIN active_loan al ON al.activeLoanID = lt.activeLoanID
    JOIN loan_application la ON la.loanApplicationID = al.loanApplicationID
    WHERE al.memberID = :mid
    ORDER BY lt.transactionID DESC
");
$ledgerStmt->execute([':mid' => $member['MemberID']]);
$memberLedger = $ledgerStmt->fetchAll();

// Paginated Transactions (for Record tab)
$recordLimit = 10;
$recordPage = max(1, (int)($_GET['page'] ?? 1));
$recordOffset = ($recordPage - 1) * $recordLimit;
$transactionIdSearch = trim($_GET['transaction_id'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $dateFrom = ''; }
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) { $dateTo = ''; }

$recordWhere = "WHERE MemberID = :member_id AND TransactionType IN ('contribution', 'withdrawal', 'loan_repayment')";
$recordParams = [':member_id' => $member['MemberID']];
if ($transactionIdSearch !== '') {
    $recordWhere .= " AND TranID LIKE :transaction_id";
    $recordParams[':transaction_id'] = '%' . $transactionIdSearch . '%';
}
if ($dateFrom !== '') {
    $recordWhere .= " AND DATE(COALESCE(TranTime, CreatedAt)) >= :date_from";
    $recordParams[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $recordWhere .= " AND DATE(COALESCE(TranTime, CreatedAt)) <= :date_to";
    $recordParams[':date_to'] = $dateTo;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM member_transactions {$recordWhere}");
$countStmt->execute($recordParams);
$totalRecords = (int)$countStmt->fetchColumn();
$totalRecordPages = max(1, (int)ceil($totalRecords / $recordLimit));
$recordPage = min(max(1, $recordPage), $totalRecordPages);
$recordOffset = ($recordPage - 1) * $recordLimit;

$recordsStmt = $pdo->prepare("SELECT * FROM member_transactions {$recordWhere} ORDER BY COALESCE(TranTime, CreatedAt) DESC LIMIT :limit OFFSET :offset");
foreach ($recordParams as $key => $value) {
    $recordsStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$recordsStmt->bindValue(':limit', $recordLimit, PDO::PARAM_INT);
$recordsStmt->bindValue(':offset', $recordOffset, PDO::PARAM_INT);
$recordsStmt->execute();
$paginatedTransactions = $recordsStmt->fetchAll();

$recordQueryParams = ['tab' => 'record'];
if ($transactionIdSearch !== '') { $recordQueryParams['transaction_id'] = $transactionIdSearch; }
if ($dateFrom !== '') { $recordQueryParams['date_from'] = $dateFrom; }
if ($dateTo !== '') { $recordQueryParams['date_to'] = $dateTo; }

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dashboardUrl(array $params): string
{
    return 'dashboard.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Member Portal | Mashirikiano SACCO</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets/css/main.css" rel="stylesheet">
  <style>
    body { background: #eef4f0; color: #1d2b26; }
    .member-shell { max-width: 1200px; }
    .portal-header { background: #087a43; color: #fff; padding: 14px 0 10px; }
    .member-brand { display: flex; align-items: center; gap: 12px; }
    .member-brand img { background: #fff; border-radius: 50%; padding: 4px; }
    .member-top-nav { display: flex; flex-wrap: wrap; justify-content: center; gap: 20px; background: #fff; border-bottom: 1px solid #dce8e1; box-shadow: 0 8px 24px rgba(28, 77, 51, .08); position: sticky; top: 0; z-index: 1030; }
    .member-top-nav a { color: #5e7068; font-weight: 700; text-decoration: none; padding: 14px 4px 12px; border-bottom: 3px solid transparent; font-size: 0.95rem; }
    .member-top-nav a:hover, .member-top-nav a.active { color: #087a43; border-bottom-color: #087a43; }
    .member-hero { background: linear-gradient(135deg, #087a43, #0a5534); color: #fff; border-radius: 8px; padding: 22px; box-shadow: 0 18px 40px rgba(8, 122, 67, .18); }
    .member-profile-summary { display: flex; align-items: center; gap: 18px; }
    .member-profile-photo { width: 86px; height: 86px; border-radius: 50%; object-fit: cover; border: 4px solid rgba(255,255,255,.92); background: #fff; flex: 0 0 86px; }
    .member-profile-name { font-size: clamp(1.5rem, 2vw, 2rem); font-weight: 800; line-height: 1.12; margin: 0 0 6px; }
    .member-profile-id { color: rgba(255,255,255,.82); font-size: 1.08rem; font-weight: 700; margin: 0; }
    .profile-detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 18px; }
    .profile-detail { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18); border-radius: 8px; padding: 12px; min-width: 0; }
    .profile-detail .label { display: block; color: rgba(255,255,255,.72); font-size: .76rem; font-weight: 700; text-transform: uppercase; }
    .profile-detail .value { display: block; color: #fff; font-weight: 700; overflow-wrap: anywhere; }
    .metric { border: 0; border-radius: 8px; box-shadow: 0 10px 30px rgba(20, 55, 38, .08); background: #fff; }
    .metric-icon { width: 38px; height: 38px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: #e7f5ed; color: #087a43; }
    .wallet-akiba { color: #e47001 !important; }
    .wallet-ushirika { color: #489a12 !important; }
    .metric-icon.wallet-akiba { background: #fff0e2; }
    .metric-icon.wallet-ushirika { background: #edf6e7; }
    .metric-icon.danger { background: #fdeaea; color: #c03232; }
    .recent-row { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid #edf2ef; }
    .recent-row:last-child { border-bottom: 0; }
    .recent-icon { width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: #e7f5ed; color: #087a43; flex: 0 0 32px; }
    .recent-icon.danger { background: #fdeaea; color: #c03232; }
    .remarks-link { color: #087a43; font-weight: 700; text-decoration: none; }
    .remarks-link:hover { text-decoration: underline; }
    .loan-card {
      border: 0;
      border-radius: 10px;
      box-shadow: 0 8px 24px rgba(0,0,0,.05);
      background: #fff;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      overflow: hidden;
    }
    .loan-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 14px 32px rgba(8, 122, 67, 0.12);
    }
    .apply-btn {
      background: #087a43;
      color: #fff;
      font-weight: 600;
      border: 0;
      transition: all 0.2s ease;
    }
    .apply-btn:hover {
      background: #f4a621;
      color: #000;
    }

    /* Stepper Styling for View 2 */
    .stepper-container {
      display: flex;
      justify-content: space-between;
      position: relative;
      margin: 18px 0;
    }
    .stepper-step {
      display: flex;
      flex-direction: column;
      align-items: center;
      position: relative;
      flex: 1;
      text-align: center;
    }
    .stepper-step::before {
      content: "";
      position: absolute;
      top: 18px;
      left: -50%;
      width: 100%;
      height: 3px;
      background: #e2e8f0;
      z-index: 1;
    }
    .stepper-step:first-child::before { display: none; }
    .stepper-step.completed::before { background: #28a745; }
    .stepper-step.rejected::before { background: #dc3545; }
    .stepper-badge {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #e2e8f0;
      color: #64748b;
      font-weight: 700;
      position: relative;
      z-index: 2;
      margin-bottom: 6px;
    }
    .stepper-step.completed .stepper-badge { background: #28a745; color: #fff; }
    .stepper-step.active .stepper-badge { background: #f4a621; color: #000; box-shadow: 0 0 0 4px rgba(244,166,33,0.25); }
    .stepper-step.rejected .stepper-badge { background: #dc3545; color: #fff; }
    .stepper-label { font-size: 0.82rem; font-weight: 600; color: #475569; }

    /* M-Pesa Instruction Box */
    .mpesa-instruction-box {
      background: linear-gradient(135deg, #f7fdf9, #ebf8f0);
      border: 1px solid #b7e4c7;
      border-left: 5px solid #28a745;
      border-radius: 10px;
      padding: 18px;
    }
    .account-code-display {
      font-family: monospace;
      font-size: 1.5rem;
      font-weight: 800;
      letter-spacing: 0.1em;
      color: #0b3b66;
      background: #fff;
      padding: 6px 14px;
      border: 2px dashed #087a43;
      border-radius: 8px;
      display: inline-block;
    }

    /* Financial Breakdown Grid */
    .fin-bucket {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 14px;
      text-align: center;
    }
    .fin-bucket .label { font-size: 0.78rem; text-transform: uppercase; color: #64748b; font-weight: 700; }
    .fin-bucket .amount { font-size: 1.35rem; font-weight: 800; color: #0b3b66; margin-top: 4px; }
    .fin-bucket.highlight {
      background: #e7f5ed;
      border-color: #087a43;
    }
    .fin-bucket.highlight .amount { color: #087a43; }

    /* Spinner Animation */
    @keyframes spin { 100% { transform: rotate(360deg); } }
    .spin-anim { animation: spin 1s linear infinite; display: inline-block; }

    .status-pill {
      font-size: 0.76rem;
      font-weight: 700;
      padding: 4px 10px;
      border-radius: 20px;
      text-transform: uppercase;
    }
    .status-pill.ACTIVE { background: #d4edda; color: #155724; }
    .status-pill.IN_ARREARS { background: #f8d7da; color: #721c24; }
    .status-pill.CLOSED { background: #e2e3e5; color: #383d41; }

    @media (max-width: 767px) {
      .member-top-nav { gap: 10px; overflow-x: auto; justify-content: flex-start; padding: 0 10px; flex-wrap: nowrap; }
      .member-top-nav a { white-space: nowrap; font-size: 0.88rem; }
    }
  </style>
</head>
<body>
  <header class="portal-header">
    <div class="container-fluid member-shell d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div class="member-brand">
        <img src="../assets/img/logo.png" alt="Mashirikiano SACCO" width="52">
        <div>
          <div class="fw-bold">Mashirikiano SACCO</div>
          <div class="small opacity-75">Member account</div>
        </div>
      </div>
      <a class="text-white fw-bold text-decoration-none" href="../auth/logout.php">Logout</a>
    </div>
  </header>

  <!-- NAVIGATION TABS -->
  <nav class="member-top-nav" aria-label="Member navigation">
    <a class="<?= $activeTab === 'dashboard' ? 'active' : '' ?>" href="?tab=dashboard">Dashboard</a>
    <a class="<?= $activeTab === 'catalog' ? 'active' : '' ?>" href="?tab=catalog">Available Loans</a>
    <a class="<?= $activeTab === 'progress' ? 'active' : '' ?>" href="?tab=progress">Loan Progress</a>
    <a class="<?= $activeTab === 'active' ? 'active' : '' ?>" href="?tab=active">Active Loans</a>
    <a class="<?= $activeTab === 'ledger' ? 'active' : '' ?>" href="?tab=ledger">Repayment Ledger</a>
    <a class="<?= $activeTab === 'record' ? 'active' : '' ?>" href="?tab=record">Transactions</a>
  </nav>

  <main class="container-fluid member-shell py-4">
    <?php if ($message): ?>
      <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show" role="alert">
        <?= e($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <div class="tab-content" id="dashboardTabsContent">
      
      <!-- ============================================================== -->
      <!-- TAB 1: DASHBOARD OVERVIEW -->
      <!-- ============================================================== -->
      <?php if ($activeTab === 'dashboard'): ?>
        <section class="member-hero mb-4">
          <div class="member-profile-summary">
            <img class="member-profile-photo" src="../assets/img/default-profile.svg" alt="">
            <div>
              <h1 class="member-profile-name"><?= e($member['FirstName'] . ' ' . $member['LastName']) ?></h1>
              <p class="member-profile-id">Member ID: <?= e($member['MemberID']) ?></p>
            </div>
          </div>
          <div class="profile-detail-grid">
            <div class="profile-detail">
              <span class="label">Email</span>
              <span class="value"><?= e($member['Email'] ?: 'Not provided') ?></span>
            </div>
            <div class="profile-detail">
              <span class="label">ID</span>
              <span class="value"><?= e($member['NationalID']) ?></span>
            </div>
          </div>
        </section>

        <div class="row g-3 mb-4">
          <div class="col-md-6 col-lg-4">
            <div class="card metric h-100" style="border-left: 4px solid #e47001;">
              <div class="card-body d-flex align-items-center gap-3">
                <span class="metric-icon wallet-akiba"><i class="bi bi-wallet2"></i></span>
                <div>
                  <div class="small text-uppercase fw-bold wallet-akiba">Current Akiba Amount</div>
                  <div class="h3 fw-bold mb-0 wallet-akiba">KES <?= number_format($akibaWallet, 2) ?></div>
                  <div class="small text-muted" style="font-size:0.75rem;">Available everyday net savings (95%)</div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-6 col-lg-4">
            <div class="card metric h-100" style="border-left: 4px solid #489a12;">
              <div class="card-body d-flex align-items-center gap-3">
                <span class="metric-icon wallet-ushirika"><i class="bi bi-pie-chart"></i></span>
                <div>
                  <div class="small text-uppercase fw-bold wallet-ushirika">Ushirika Amount</div>
                  <div class="h3 fw-bold mb-0 wallet-ushirika">KES <?= number_format($totalUshirika, 2) ?></div>
                  <div class="small text-muted" style="font-size:0.75rem;">Retained cooperative member shares (5%)</div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-6 col-lg-4">
            <div class="card metric h-100" style="border-left: 4px solid #dc3545;">
              <div class="card-body d-flex align-items-center gap-3">
                <span class="metric-icon danger"><i class="bi bi-box-arrow-up-right"></i></span>
                <div>
                  <div class="text-muted small text-uppercase fw-bold">Total Withdrawals</div>
                  <div class="h3 fw-bold mb-0 text-danger">KES <?= number_format($totalWithdrawals, 2) ?></div>
                  <div class="small text-muted" style="font-size:0.75rem;">Total cash released from savings</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-md-6">
            <div class="card metric h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <span class="metric-icon"><i class="bi bi-check2-circle"></i></span>
                <div>
                  <div class="text-muted small text-uppercase fw-bold">Total Contributions</div>
                  <div class="h4 fw-bold mb-0">KES <?= number_format($totalContributions, 2) ?></div>
                  <div class="small text-muted" style="font-size:0.75rem;">Cumulative lifetime savings deposits</div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card metric h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <span class="metric-icon danger"><i class="bi bi-cash-stack"></i></span>
                <div>
                  <div class="text-muted small text-uppercase fw-bold">Active Total Loan Balance</div>
                  <div class="h4 fw-bold mb-0 text-danger">KES <?= number_format($totalLoanBalance, 2) ?></div>
                  <div class="small text-muted" style="font-size:0.75rem;">Outstanding principal & daily accrued interest</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-4">
          <section class="col-12">
            <div class="card metric h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                  <h2 class="h5 fw-bold mb-0">Recent Transactions</h2>
                  <a class="remarks-link" href="?tab=record">View all</a>
                </div>
                <?php foreach ($recentTransactions as $transaction): ?>
                  <?php
                    $isWithdrawal = $transaction['TransactionType'] === 'withdrawal';
                    $isTransfer = ($transaction['TransactionCategory'] ?? '') === 'credit_transfer';
                    $isLoanRepay = $transaction['TransactionType'] === 'loan_repayment';
                  ?>
                  <div class="recent-row">
                    <span class="recent-icon <?= $isWithdrawal ? 'danger' : ($isTransfer ? 'primary' : ($isLoanRepay ? 'warning' : '')) ?>">
                      <i class="bi <?= $isWithdrawal ? 'bi-box-arrow-up-right' : ($isTransfer ? 'bi-arrow-left-right text-primary' : ($isLoanRepay ? 'bi-arrow-clockwise text-warning' : 'bi-arrow-down-left')) ?>"></i>
                    </span>
                    <div class="flex-grow-1">
                      <div class="fw-bold"><?= e($transaction['TranID']) ?></div>
                      <div class="text-muted small"><?= $isWithdrawal ? 'Withdrawal' : ($isTransfer ? 'Credit Transfer' : ($isLoanRepay ? 'Loan Repayment' : 'Contribution')) ?> &middot; <?= e($transaction['TranTime'] ?: $transaction['CreatedAt']) ?> &middot; <?= e($transaction['Description'] ?: $transaction['MSISDN']) ?></div>
                    </div>
                    <div class="fw-bold <?= $isWithdrawal ? 'text-danger' : ($isTransfer ? 'text-primary' : ($isLoanRepay ? 'text-warning' : 'text-success')) ?> text-end">
                      <?= $isWithdrawal ? '-' : ($isTransfer ? '↔' : ($isLoanRepay ? '↺' : '+')) ?> KES <?= number_format((float)$transaction['Amount'], 2) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
                <?php if (!$recentTransactions): ?>
                  <div class="text-muted text-center py-3">No transaction records found.</div>
                <?php endif; ?>
              </div>
            </div>
          </section>
        </div>
      <?php endif; ?>

      <!-- ============================================================== -->
      <!-- VIEW 1: AVAILABLE LOANS CATALOG (/member/loans/catalog) -->
      <!-- ============================================================== -->
      <?php if ($activeTab === 'catalog'): ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
          <div>
            <h1 class="h4 fw-bold mb-1">Available Loan Products Catalog</h1>
            <p class="text-muted small mb-0">Select a credit facility. Eligibility is verified automatically against your Akiba and Ushirika wallet balances.</p>
          </div>
          <div class="d-flex gap-2">
            <a class="btn btn-outline-success btn-sm fw-semibold" href="?tab=progress">
              <i class="bi bi-clock-history me-1"></i> Loan Progress
            </a>
            <a class="btn btn-outline-primary btn-sm fw-semibold" href="?tab=active">
              <i class="bi bi-cash-coin me-1"></i> Active Loans
            </a>
          </div>
        </div>

        <!-- Wallet Qualification Info Strip -->
        <div class="alert alert-light border d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 p-3 rounded-3">
          <div class="d-flex align-items-center gap-3">
            <span class="fs-4 text-success"><i class="bi bi-shield-check"></i></span>
            <div>
              <div class="fw-bold text-dark">Your Current Savings Balances</div>
              <div class="small text-muted">Akiba: <strong>KES <?= number_format($akibaWallet, 2) ?></strong> &middot; Ushirika Shares: <strong>KES <?= number_format($totalUshirika, 2) ?></strong></div>
            </div>
          </div>
          <span class="badge bg-primary px-3 py-2">Real-Time Qualification</span>
        </div>

        <div class="row g-4">
          <?php foreach ($catalogProducts as $prod): ?>
            <?php 
              $pId = (int)$prod['loanID'];
              $elig = $productEligibility[$pId] ?? ['is_eligible' => false, 'reason' => 'Checking...'];
              $isEligible = $elig['is_eligible'];
              $isCustom = (strtoupper(trim($prod['paymentPeriod'])) === 'CUSTOM');
              $durationDays = 30;
              if (preg_match('/(\d+)/', $prod['paymentPeriod'], $matches)) {
                  $durationDays = (int)$matches[1];
              }
              $predefinedReturnDate = date('Y-m-d', strtotime("+$durationDays days"));
              $customMinDate = date('Y-m-d', strtotime('+121 days'));
            ?>
            <div class="col-md-6 col-lg-4">
              <div class="card loan-card h-100 d-flex flex-column">
                <div class="card-body p-4 d-flex flex-column">
                  <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <h5 class="card-title fw-bold text-primary mb-0" style="font-size: 1.15rem;">
                      <?= e($prod['loanType']) ?>
                    </h5>
                    <?php if ($isEligible): ?>
                      <span class="badge bg-success" style="font-size: 0.75rem;">
                        <i class="bi bi-check-circle-fill me-1"></i> Eligible
                      </span>
                    <?php else: ?>
                      <span class="badge bg-danger" style="font-size: 0.75rem;">
                        <i class="bi bi-x-circle-fill me-1"></i> Under-Qualified
                      </span>
                    <?php endif; ?>
                  </div>

                  <p class="card-text text-muted small flex-grow-1 mb-3">
                    <?= e($prod['loanDescription'] ?: 'Affordable SACCO financing tailored to empower members.') ?>
                  </p>

                  <div class="bg-light p-3 rounded-3 mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                      <span class="text-muted">Maximum Limit:</span>
                      <strong class="text-success">KES <?= number_format((float)$prod['maxAmount'], 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between small mb-1">
                      <span class="text-muted">Annual Interest:</span>
                      <strong class="text-dark"><?= number_format((float)$prod['loanInterest'] * 100, 2) ?>% (<?= e($prod['interestType']) ?>)</strong>
                    </div>
                    <div class="d-flex justify-content-between small mb-1">
                      <span class="text-muted">Period Mode:</span>
                      <strong class="text-primary"><?= e($prod['paymentPeriod']) ?></strong>
                    </div>
                    <div class="border-top pt-2 mt-2">
                      <div class="d-flex justify-content-between small text-muted">
                        <span>Min. Akiba: KES <?= number_format((float)$prod['loanQualification_AkibaAmount'], 2) ?></span>
                        <span>Min. Ushirika: KES <?= number_format((float)$prod['loanQualification_UshirikaAmount'], 2) ?></span>
                      </div>
                    </div>
                  </div>

                  <?php if (!$isEligible): ?>
                    <div class="alert alert-warning py-1 px-2 small mb-3 text-center" style="font-size: 0.75rem;">
                      <i class="bi bi-exclamation-triangle me-1"></i> <?= e($elig['reason']) ?>
                    </div>
                  <?php endif; ?>

                  <button 
                    type="button" 
                    class="btn <?= $isEligible ? 'apply-btn' : 'btn-secondary opacity-75' ?> w-100 py-2 rounded-3 fw-bold" 
                    <?= $isEligible ? '' : 'disabled' ?>
                    data-bs-toggle="modal" 
                    data-bs-target="#applyLoanModal"
                    data-loan-id="<?= $pId ?>"
                    data-loan-title="<?= e($prod['loanType']) ?>"
                    data-loan-max="<?= (float)$prod['maxAmount'] ?>"
                    data-loan-period-mode="<?= $isCustom ? 'custom' : 'predefined' ?>"
                    data-predefined-date="<?= e($predefinedReturnDate) ?>"
                    data-custom-min-date="<?= e($customMinDate) ?>"
                    data-loan-interest="<?= number_format((float)$prod['loanInterest'] * 100, 2) ?>% (<?= e($prod['interestType']) ?>)"
                  >
                    <?= $isEligible ? 'Apply Now' : 'Requires More Savings' ?>
                  </button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- APPLICATION MODAL -->
        <div class="modal fade" id="applyLoanModal" tabindex="-1" aria-labelledby="applyLoanModalLabel" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg">
              <form method="post" action="dashboard.php?tab=catalog" id="applyLoanForm">
                <input type="hidden" name="apply_loan" value="1">
                <input type="hidden" name="loan_id" id="modalLoanId" value="">

                <div class="modal-header bg-success text-white border-0">
                  <h5 class="modal-title fw-bold" id="applyLoanModalLabel">
                    <i class="bi bi-file-earmark-text me-1"></i> Member Loan Application
                  </h5>
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                  <div class="mb-3">
                    <label class="form-label fw-bold">Loan Facility</label>
                    <input type="text" class="form-control bg-light fw-bold text-primary" id="modalLoanTitle" readonly>
                    <div class="form-text" id="modalInterestHelp"></div>
                  </div>

                  <div class="mb-3">
                    <label for="modalAmount" class="form-label fw-bold">Requested Amount (KES) <span class="text-danger">*</span></label>
                    <div class="input-group">
                      <span class="input-group-text">KES</span>
                      <input type="number" class="form-control" id="modalAmount" name="amount" min="1" step="0.01" placeholder="e.g. 50000" required>
                    </div>
                    <div class="form-text" id="modalMaxAmountHelp">Maximum borrowing limit: KES 0.00</div>
                  </div>

                  <!-- Return Date Field -->
                  <div class="mb-3" id="predefinedDateWrapper">
                    <label class="form-label fw-bold">Scheduled Return Date (Predefined)</label>
                    <input type="date" class="form-control bg-light" id="modalPredefinedReturnDate" readonly>
                    <div class="form-text">Auto-calculated clearance target according to loan term.</div>
                  </div>

                  <div class="mb-3" id="customDateWrapper" style="display: none;">
                    <label for="modalCustomReturnDate" class="form-label fw-bold">Custom Clearance Target <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="modalCustomReturnDate">
                    <div class="form-text text-primary">Strict policy: Custom duration must be at least 121 days (> 120 days from today).</div>
                  </div>

                  <div class="alert alert-info small py-2 mb-0">
                    <i class="bi bi-bell me-1"></i> A confirmation SMS will be dispatched to your phone <strong><?= e($member['PrimaryNumber']) ?></strong> upon submission and review.
                  </div>
                </div>

                <div class="modal-footer border-0 p-3">
                  <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                  <button type="submit" class="btn apply-btn px-4 fw-bold">Submit Application</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- ============================================================== -->
      <!-- VIEW 2: LOAN PROGRESS & APPLICATIONS (/member/loans/progress) -->
      <!-- ============================================================== -->
      <?php if ($activeTab === 'progress'): ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
          <div>
            <h1 class="h4 fw-bold mb-1">Loan Progress Tracker</h1>
            <p class="text-muted small mb-0">Track real-time status transitions, review decisions, and admin remarks for your submitted loan requests.</p>
          </div>
          <a href="?tab=catalog" class="btn apply-btn fw-bold">
            <i class="bi bi-plus-circle me-1"></i> Apply for a Loan
          </a>
        </div>

        <?php if (!empty($memberApplications)): ?>
          <div class="row g-4">
            <?php foreach ($memberApplications as $app): ?>
              <?php
                $status = strtoupper((string)$app['status']);
                $isPending = ($status === 'PENDING');
                $isApproved = ($status === 'APPROVED');
                $isRejected = ($status === 'REJECTED');
              ?>
              <div class="col-12">
                <div class="card metric p-4">
                  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                      <h5 class="fw-bold text-dark mb-1">
                        <?= e($app['loanType']) ?> 
                        <span class="text-muted small">(App #<?= (int)$app['loanApplicationID'] ?>)</span>
                      </h5>
                      <span class="text-muted small">Submitted on: <?= e($app['createdAt']) ?> &middot; Requested Target: <?= e($app['returndate']) ?></span>
                    </div>
                    <div class="text-end">
                      <div class="h4 fw-bold text-success mb-0">KES <?= number_format((float)$app['amount'], 2) ?></div>
                      <span class="badge <?= $isApproved ? 'bg-success' : ($isRejected ? 'bg-danger' : 'bg-warning text-dark') ?> text-uppercase">
                        <?= e($app['status']) ?>
                      </span>
                    </div>
                  </div>

                  <!-- Visual Status Stepper -->
                  <div class="stepper-container">
                    <!-- Step 1: Submitted -->
                    <div class="stepper-step completed">
                      <div class="stepper-badge"><i class="bi bi-check-lg"></i></div>
                      <div class="stepper-label text-success">Submitted</div>
                      <small class="text-muted" style="font-size:0.7rem;"><?= date('d M Y', strtotime($app['createdAt'])) ?></small>
                    </div>

                    <!-- Step 2: Under Review -->
                    <div class="stepper-step <?= ($isPending ? 'active' : ($isApproved ? 'completed' : 'rejected')) ?>">
                      <div class="stepper-badge">
                        <?php if ($isPending): ?>
                          <i class="bi bi-hourglass-split"></i>
                        <?php elseif ($isApproved): ?>
                          <i class="bi bi-check-lg"></i>
                        <?php else: ?>
                          <i class="bi bi-x-lg"></i>
                        <?php endif; ?>
                      </div>
                      <div class="stepper-label <?= $isPending ? 'text-warning fw-bold' : ($isApproved ? 'text-success' : 'text-danger') ?>">Under Review</div>
                      <small class="text-muted" style="font-size:0.7rem;"><?= $isPending ? 'In Progress' : 'Completed' ?></small>
                    </div>

                    <!-- Step 3: Approved / Rejected -->
                    <div class="stepper-step <?= $isApproved ? 'completed' : ($isRejected ? 'rejected' : '') ?>">
                      <div class="stepper-badge">
                        <?php if ($isApproved): ?>
                          <i class="bi bi-check-lg"></i>
                        <?php elseif ($isRejected): ?>
                          <i class="bi bi-x-lg"></i>
                        <?php else: ?>
                          3
                        <?php endif; ?>
                      </div>
                      <div class="stepper-label <?= $isApproved ? 'text-success fw-bold' : ($isRejected ? 'text-danger fw-bold' : 'text-muted') ?>">
                        <?= $isRejected ? 'Rejected' : 'Approved' ?>
                      </div>
                      <small class="text-muted" style="font-size:0.7rem;">
                        <?= $isApproved ? 'Disbursed' : ($isRejected ? 'Declined' : 'Pending Review') ?>
                      </small>
                    </div>
                  </div>

                  <!-- Rejection Feedback Alert -->
                  <?php if ($isRejected): ?>
                    <div class="alert alert-danger mb-0 mt-2 p-3">
                      <div class="fw-bold mb-1"><i class="bi bi-exclamation-octagon me-1"></i> Application Not Approved</div>
                      <p class="mb-0 small"><strong>Reason from Admin:</strong> <?= e($app['rejection'] ?: 'Application does not meet required savings or risk thresholds.') ?></p>
                    </div>
                  <?php endif; ?>

                  <!-- Approval Feedback Banner -->
                  <?php if ($isApproved): ?>
                    <div class="alert alert-success d-flex flex-wrap align-items-center justify-content-between gap-2 mb-0 mt-2 p-3">
                      <div>
                        <div class="fw-bold"><i class="bi bi-check-circle me-1"></i> Approved & Active in Ledger</div>
                        <div class="small">M-Pesa Repayment Account Code: <strong class="fs-6 text-primary"><?= e($app['repayment_account']) ?></strong></div>
                      </div>
                      <a href="?tab=active" class="btn btn-sm btn-success fw-bold">
                        <i class="bi bi-eye me-1"></i> View Live Loan Dashboard
                      </a>
                    </div>
                  <?php endif; ?>

                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="card metric p-5 text-center">
            <i class="bi bi-file-earmark-text fs-1 text-muted mb-3"></i>
            <h5 class="fw-bold text-dark">No Loan Applications Found</h5>
            <p class="text-muted">You have not submitted any loan applications yet. Explore our loan products catalog to apply.</p>
            <div>
              <a href="?tab=catalog" class="btn apply-btn px-4 fw-bold">Explore Loan Catalog</a>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <!-- ============================================================== -->
      <!-- VIEW 3: APPROVED & ACTIVE LOANS (/member/loans/active) -->
      <!-- ============================================================== -->
      <?php if ($activeTab === 'active'): ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
          <div>
            <h1 class="h4 fw-bold mb-1">Approved & Active Loans</h1>
            <p class="text-muted small mb-0">Live operational ledger, real-time daily interest calculations, and M-Pesa repayment routing.</p>
          </div>
          <div class="d-flex gap-2">
            <a href="?tab=catalog" class="btn btn-outline-success btn-sm fw-semibold">
              <i class="bi bi-plus-circle me-1"></i> Apply Another Loan
            </a>
            <a href="?tab=ledger" class="btn btn-outline-primary btn-sm fw-semibold">
              <i class="bi bi-journal-text me-1"></i> Repayment Ledger
            </a>
          </div>
        </div>

        <?php if (!empty($memberActiveLoans)): ?>
          <div class="row g-4">
            <?php foreach ($memberActiveLoans as $act): ?>
              <?php
                $pBal = (float)$act['principalBalance'];
                $accInt = (float)$act['accruedInterest'];
                $billInt = (float)$act['billedUnpaidInterest'];
                $penBal = (float)$act['penaltyBalance'];
                $totalPayoff = round($pBal + $accInt + $billInt + $penBal, 2);
                $isArrears = ($act['loanStatus'] === 'IN_ARREARS');
              ?>
              <div class="col-12">
                <div class="card metric p-4">
                  <!-- Header Bar -->
                  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 pb-3 mb-3 border-bottom">
                    <div class="d-flex align-items-center gap-3">
                      <h4 class="fw-bold text-dark mb-0"><?= e($act['loanType']) ?></h4>
                      <span class="status-pill <?= e($act['loanStatus']) ?>">
                        <?= e($act['loanStatus']) ?>
                      </span>
                    </div>

                    <!-- Prominent "Refresh Loan State" button with spinner -->
                    <form method="post" action="dashboard.php?tab=active" id="refreshForm_<?= $act['activeLoanID'] ?>" class="d-inline">
                      <input type="hidden" name="refresh_loan_state" value="1">
                      <input type="hidden" name="active_loan_id" value="<?= (int)$act['activeLoanID'] ?>">
                      <button type="submit" class="btn btn-primary fw-bold shadow-sm" onclick="this.querySelector('i').classList.add('spin-anim');">
                        <i class="bi bi-arrow-clockwise me-1"></i> Refresh Loan State
                      </button>
                    </form>
                  </div>

                  <!-- M-Pesa Payment Instruction Box -->
                  <div class="mpesa-instruction-box mb-4">
                    <div class="row g-3 align-items-center">
                      <div class="col-lg-7">
                        <div class="fw-bold text-success fs-6 mb-1">
                          <i class="bi bi-phone me-1"></i> M-Pesa C2B Paybill Repayment Instructions
                        </div>
                        <p class="small text-muted mb-2">
                          1. Open Safaricom M-Pesa &rarr; Lipa na M-Pesa &rarr; <strong>Paybill</strong><br>
                          2. Business Number: <strong><?= e(env('MPESA_SHORTCODE', '4085444')) ?></strong><br>
                          3. Account Number: Enter your unique 5-character routing code below:
                        </p>
                      </div>
                      <div class="col-lg-5 text-lg-end">
                        <div class="d-inline-flex flex-column align-items-lg-end align-items-start gap-2">
                          <div class="d-flex align-items-center gap-2">
                            <span class="account-code-display"><?= e($act['repayment_account']) ?></span>
                            <button type="button" class="btn btn-outline-success fw-bold" onclick="copyRepayCode('<?= e($act['repayment_account']) ?>')">
                              <i class="bi bi-clipboard me-1"></i> Copy
                            </button>
                          </div>
                          <small class="text-muted">Unique loan account code</small>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Transparent Financial Breakdown Cards -->
                  <div class="row g-3 mb-4">
                    <!-- Current Principal Balance -->
                    <div class="col-6 col-lg-3">
                      <div class="fin-bucket">
                        <div class="label">Current Principal</div>
                        <div class="amount">KES <?= number_format($pBal, 2) ?></div>
                        <small class="text-muted">Orig: <?= number_format((float)$act['originalPrincipal'], 2) ?></small>
                      </div>
                    </div>

                    <!-- Current Accrued Interest (Updates live when Refresh is clicked) -->
                    <div class="col-6 col-lg-3">
                      <div class="fin-bucket">
                        <div class="label">Accrued Interest (Daily)</div>
                        <div class="amount text-warning">KES <?= number_format($accInt, 2) ?></div>
                        <small class="text-muted">Actual/365 rolling interest</small>
                      </div>
                    </div>

                    <!-- Overdue Billed Interest -->
                    <div class="col-6 col-lg-3">
                      <div class="fin-bucket">
                        <div class="label">Overdue Billed Interest</div>
                        <div class="amount text-danger">KES <?= number_format($billInt, 2) ?></div>
                        <small class="text-muted">Missed monthly milestones</small>
                      </div>
                    </div>

                    <!-- Penalties -->
                    <div class="col-6 col-lg-3">
                      <div class="fin-bucket">
                        <div class="label">Penalties</div>
                        <div class="amount text-danger">KES <?= number_format($penBal, 2) ?></div>
                        <small class="text-muted">Late clearance default fees</small>
                      </div>
                    </div>

                    <!-- Total Payoff Quote Today (Full Width Banner) -->
                    <div class="col-12">
                      <div class="fin-bucket highlight p-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div class="text-start">
                          <div class="label text-success">Total Payoff Quote Today</div>
                          <div class="small text-muted">Sum of remaining Principal, Daily Accrued Interest, Billed Interest, and Late Penalties</div>
                        </div>
                        <div class="text-end">
                          <div class="amount fs-2 text-success">KES <?= number_format($totalPayoff, 2) ?></div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Timeline Metadata Footer -->
                  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-3 border-top text-muted small">
                    <div>
                      <span>Disbursed On: <strong><?= e($act['disbursementDate']) ?></strong></span> &middot; 
                      <span>Due Date: <strong class="<?= $isArrears ? 'text-danger' : '' ?>"><?= e($act['returnDate']) ?></strong></span>
                    </div>
                    <div>
                      <span>Last Interest Calculated: <strong><?= e($act['lastInterestCalcDate']) ?></strong></span> &middot;
                      <a href="?tab=ledger" class="remarks-link ms-2">
                        <i class="bi bi-journal-text me-1"></i> View Repayment Ledger
                      </a>
                    </div>
                  </div>

                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="card metric p-5 text-center">
            <i class="bi bi-check2-circle fs-1 text-success mb-3"></i>
            <h5 class="fw-bold text-dark">No Active Loans</h5>
            <p class="text-muted">You do not have any active borrowing facilities at the moment. Need credit? Explore our catalog.</p>
            <div>
              <a href="?tab=catalog" class="btn apply-btn px-4 fw-bold">Apply for a Loan</a>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <!-- ============================================================== -->
      <!-- VIEW 4: REPAYMENT LEDGER (/member/loans/ledger) -->
      <!-- ============================================================== -->
      <?php if ($activeTab === 'ledger'): ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
          <div>
            <h1 class="h4 fw-bold mb-1">Repayment Ledger & Audit Log</h1>
            <p class="text-muted small mb-0">Immutable transaction history recording disbursements, daily interest accruals, repayments, and running balances.</p>
          </div>
          <a href="?tab=active" class="btn btn-outline-primary btn-sm fw-semibold">
            <i class="bi bi-arrow-left me-1"></i> Active Loans
          </a>
        </div>

        <div class="card metric">
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Date</th>
                    <th>Loan Facility</th>
                    <th>Event Type</th>
                    <th>M-Pesa Receipt</th>
                    <th class="text-end">Total Amount</th>
                    <th>Waterfall Split (Penalty | Billed | Accrued | Principal)</th>
                    <th class="text-end">Principal Snapshot</th>
                    <th class="text-end">Accrued Snapshot</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($memberLedger as $tx): ?>
                    <tr>
                      <td class="small fw-semibold"><?= e($tx['transactionDate']) ?></td>
                      <td>
                        <div class="fw-semibold text-dark"><?= e($tx['loanType']) ?></div>
                        <span class="badge bg-light text-dark border" style="font-family: monospace; font-size: 0.72rem;">
                          <?= e($tx['repayment_account']) ?>
                        </span>
                      </td>
                      <td>
                        <span class="badge bg-<?= $tx['transactionType'] === 'REPAYMENT' ? 'success' : ($tx['transactionType'] === 'DISBURSEMENT' ? 'primary' : 'warning text-dark') ?>">
                          <?= e($tx['transactionType']) ?>
                        </span>
                      </td>
                      <td class="small"><?= e($tx['mpesaReceiptNumber'] ?: '-') ?></td>
                      <td class="text-end fw-bold">
                        KES <?= number_format((float)$tx['totalAmount'], 2) ?>
                      </td>
                      <td>
                        <span class="badge bg-danger" style="font-size:0.7rem;" title="Allocated to late penalties">Pen: <?= number_format((float)$tx['allocatedPenalty'], 2) ?></span>
                        <span class="badge bg-warning text-dark" style="font-size:0.7rem;" title="Allocated to overdue billed interest">Bil: <?= number_format((float)$tx['allocatedBilledInterest'], 2) ?></span>
                        <span class="badge bg-info text-dark" style="font-size:0.7rem;" title="Allocated to current accrued interest">Acc: <?= number_format((float)$tx['allocatedAccruedInterest'], 2) ?></span>
                        <span class="badge bg-success" style="font-size:0.7rem;" title="Allocated to loan principal">Prin: <?= number_format((float)$tx['allocatedPrincipal'], 2) ?></span>
                      </td>
                      <td class="text-end fw-semibold text-primary">
                        KES <?= number_format((float)$tx['principalAfter'], 2) ?>
                      </td>
                      <td class="text-end fw-semibold text-danger">
                        KES <?= number_format((float)$tx['accruedInterestAfter'], 2) ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (empty($memberLedger)): ?>
                    <tr>
                      <td colspan="8" class="text-center text-muted py-5">
                        <i class="bi bi-journal-x fs-1 d-block text-secondary mb-2"></i>
                        No loan transaction events recorded yet.
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- ============================================================== -->
      <!-- TAB 6: GENERAL MEMBER TRANSACTIONS RECORD -->
      <!-- ============================================================== -->
      <?php if ($activeTab === 'record'): ?>
        <section class="card metric">
          <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
              <h3 class="h5 fw-bold mb-0">Member Transaction Records</h3>
              <div class="text-muted small">Showing <?= count($paginatedTransactions) ?> of <?= $totalRecords ?> record(s)</div>
            </div>

            <form method="get" class="row g-2 mb-3">
              <input type="hidden" name="tab" value="record">
              <div class="col-md-4">
                <input class="form-control" name="transaction_id" value="<?= e($transactionIdSearch) ?>" placeholder="Search by transaction ID">
              </div>
              <div class="col-md-3">
                <input class="form-control" name="date_from" type="date" value="<?= e($dateFrom) ?>" aria-label="Date from">
              </div>
              <div class="col-md-3">
                <input class="form-control" name="date_to" type="date" value="<?= e($dateTo) ?>" aria-label="Date to">
              </div>
              <div class="col-md-1">
                <button class="btn apply-btn w-100" type="submit">Search</button>
              </div>
              <div class="col-md-1">
                <a class="btn btn-outline-secondary w-100" href="?tab=record">Clear</a>
              </div>
            </form>
            
            <div class="table-responsive">
              <table class="table align-middle table-hover">
                <thead>
                  <tr>
                    <th>TransactionID</th>
                    <th>Date / Time</th>
                    <th>Type</th>
                    <th class="text-end">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($paginatedTransactions as $row): ?>
                    <tr>
                      <td class="fw-bold text-primary"><?= e($row['TranID']) ?></td>
                      <td><?= e($row['TranTime'] ?: $row['CreatedAt']) ?></td>
                      <?php
                        $isWithdrawal = $row['TransactionType'] === 'withdrawal';
                        $isTransfer = ($row['TransactionCategory'] ?? '') === 'credit_transfer';
                        $isLoanRepay = $row['TransactionType'] === 'loan_repayment';
                      ?>
                      <td class="<?= $isWithdrawal ? 'text-danger' : ($isTransfer ? 'text-primary' : ($isLoanRepay ? 'text-warning' : 'text-success')) ?> fw-semibold">
                        <i class="bi <?= $isWithdrawal ? 'bi-box-arrow-up-right' : ($isTransfer ? 'bi-arrow-left-right text-primary' : ($isLoanRepay ? 'bi-arrow-clockwise text-warning' : 'bi-arrow-down-left')) ?> me-1" aria-hidden="true"></i>
                        <?= $isWithdrawal ? 'Withdrawal' : ($isTransfer ? 'Credit Transfer' : ($isLoanRepay ? 'Loan Repayment' : 'Contribution')) ?>
                      </td>
                      <td class="text-end fw-bold <?= $isWithdrawal ? 'text-danger' : ($isTransfer ? 'text-primary' : ($isLoanRepay ? 'text-warning' : 'text-success')) ?>"><?= $isWithdrawal ? '-' : ($isTransfer ? '↔' : ($isLoanRepay ? '↺' : '+')) ?> KES <?= number_format((float)$row['Amount'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$paginatedTransactions): ?>
                    <tr><td colspan="4" class="text-muted text-center py-3">No transaction records found.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <!-- Record Pagination -->
            <?php if ($totalRecordPages > 1): ?>
              <nav aria-label="Transaction pages" class="mt-4">
                <ul class="pagination pagination-sm justify-content-center mb-0">
                  <li class="page-item <?= $recordPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e(dashboardUrl(array_merge($recordQueryParams, ['page' => max(1, $recordPage - 1)]))) ?>">Previous</a>
                  </li>
                  <?php for ($p = 1; $p <= $totalRecordPages; $p++): ?>
                    <li class="page-item <?= $p === $recordPage ? 'active' : '' ?>">
                      <a class="page-link" href="<?= e(dashboardUrl(array_merge($recordQueryParams, ['page' => $p]))) ?>"><?= $p ?></a>
                    </li>
                  <?php endfor; ?>
                  <li class="page-item <?= $recordPage >= $totalRecordPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e(dashboardUrl(array_merge($recordQueryParams, ['page' => min($totalRecordPages, $recordPage + 1)]))) ?>">Next</a>
                  </li>
                </ul>
              </nav>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

    </div>
  </main>

  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    // Copy 5-character repayment account helper
    function copyRepayCode(code) {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(code).then(() => {
          alert('Copied repayment account code: ' + code);
        }).catch(() => {
          prompt('Copy repayment account code:', code);
        });
      } else {
        prompt('Copy repayment account code:', code);
      }
    }

    // Modal Dynamic Content Population
    const applyLoanModal = document.getElementById('applyLoanModal');
    if (applyLoanModal) {
      applyLoanModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        if (!button) return;

        const loanId = button.getAttribute('data-loan-id');
        const loanTitle = button.getAttribute('data-loan-title');
        const loanMax = parseFloat(button.getAttribute('data-loan-max') || '0');
        const periodMode = button.getAttribute('data-loan-period-mode');
        const predefinedDate = button.getAttribute('data-predefined-date');
        const customMinDate = button.getAttribute('data-custom-min-date');
        const interestInfo = button.getAttribute('data-loan-interest');

        document.getElementById('modalLoanId').value = loanId;
        document.getElementById('modalLoanTitle').value = loanTitle;
        document.getElementById('modalInterestHelp').textContent = 'Pricing: ' + interestInfo;

        const amountInput = document.getElementById('modalAmount');
        amountInput.max = loanMax;
        document.getElementById('modalMaxAmountHelp').textContent = 'Maximum borrowing limit: KES ' + Number(loanMax).toLocaleString(undefined, {minimumFractionDigits: 2});

        const preWrapper = document.getElementById('predefinedDateWrapper');
        const custWrapper = document.getElementById('customDateWrapper');
        const preInput = document.getElementById('modalPredefinedReturnDate');
        const custInput = document.getElementById('modalCustomReturnDate');

        if (periodMode === 'custom') {
          preWrapper.style.display = 'none';
          preInput.removeAttribute('name');
          preInput.removeAttribute('required');

          custWrapper.style.display = 'block';
          custInput.setAttribute('name', 'return_date');
          custInput.setAttribute('required', 'required');
          custInput.min = customMinDate;
          custInput.value = customMinDate;
        } else {
          custWrapper.style.display = 'none';
          custInput.removeAttribute('name');
          custInput.removeAttribute('required');

          preWrapper.style.display = 'block';
          preInput.setAttribute('name', 'return_date');
          preInput.setAttribute('required', 'required');
          preInput.value = predefinedDate;
        }
      });
    }
  </script>
</body>
</html>
