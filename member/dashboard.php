<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/MemberService.php';
require_once __DIR__ . '/../classes/SmsService.php';

$member = Auth::requireMember();
Auth::startSession();
$pdo = Database::connection();
$message = '';
$messageType = 'info';

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_message_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
}

// Auto-create loan_applications table if not exists (self-healing migration)
try {
    $pdo->query("SELECT 1 FROM `loan_applications` LIMIT 1");
} catch (Throwable $e) {
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
      KEY `idx_loan_applications_status` (`Status`),
      CONSTRAINT `fk_loan_applications_member`
        FOREIGN KEY (`MemberID`) REFERENCES `members` (`MemberID`)
        ON UPDATE CASCADE
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
}

$activeTab = $_GET['tab'] ?? 'dashboard';
if (!in_array($activeTab, ['dashboard', 'loan', 'applications', 'record'])) {
    $activeTab = 'dashboard';
}

// Handle loan application form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_loan'])) {
    try {
        $loanType = trim($_POST['loan_type'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $returnDate = trim($_POST['return_date'] ?? '');

        if (empty($loanType)) {
            throw new Exception("Please select a loan type.");
        }
        if ($amount <= 0) {
            throw new Exception("Please enter a valid loan amount greater than 0.");
        }
        if (empty($returnDate)) {
            throw new Exception("Please select a valid return date.");
        }
        
        $today = date('Y-m-d');
        if ($returnDate <= $today) {
            throw new Exception("Return date must be in the future.");
        }

        // Save to DB
        $stmt = $pdo->prepare("INSERT INTO loan_applications (MemberID, LoanType, Amount, ReturnDate, Status) VALUES (:member_id, :loan_type, :amount, :return_date, 'Pending')");
        $stmt->execute([
            ':member_id' => $member['MemberID'],
            ':loan_type' => $loanType,
            ':amount' => $amount,
            ':return_date' => $returnDate
        ]);

        $messageType = 'success';
        $message = "Your loan application for $loanType of KES " . number_format($amount, 2) . " has been submitted successfully!";

        // Send SMS to member
        $smsMsg = "Dear " . $member['FirstName'] . ", your application for " . $loanType . " of KES " . number_format($amount, 2) . " repayable by " . $returnDate . " has been received. Feedback will be given as soon as possible. Thank you.";
        SmsService::sendSms($member['PrimaryNumber'], $smsMsg);
        
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_message_type'] = $messageType;
        header("Location: dashboard.php?tab=applications");
        exit;

    } catch (Throwable $e) {
        $_SESSION['flash_message'] = $e->getMessage();
        $_SESSION['flash_message_type'] = 'danger';
        header("Location: dashboard.php?tab=loan");
        exit;
    }
}

// Fetch totals & dashboard info
$totalStmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN TransactionType = 'contribution' THEN Amount WHEN TransactionType = 'withdrawal' THEN -Amount ELSE 0 END), 0) AS Total FROM member_transactions WHERE MemberID = :member_id");
$totalStmt->execute([':member_id' => $member['MemberID']]);
$total = max(0.00, (float) $totalStmt->fetchColumn());

$recentStmt = $pdo->prepare("SELECT * FROM member_transactions WHERE MemberID = :member_id AND TransactionType IN ('contribution', 'withdrawal') ORDER BY COALESCE(TranTime, CreatedAt) DESC LIMIT 8");
$recentStmt->execute([':member_id' => $member['MemberID']]);
$recentTransactions = $recentStmt->fetchAll();

// Fetch member's loan applications
$loanAppsStmt = $pdo->prepare("SELECT * FROM loan_applications WHERE MemberID = :member_id ORDER BY CreatedAt DESC");
$loanAppsStmt->execute([':member_id' => $member['MemberID']]);
$loanApplications = $loanAppsStmt->fetchAll();
$totalLoanBalance = 0.00;
foreach ($loanApplications as $loanApplication) {
    if ($loanApplication['Status'] === 'Approved') {
        $totalLoanBalance += (float)$loanApplication['Amount'];
    }
}

// Paginated Transactions (for Record tab)
$recordLimit = 10;
$recordPage = max(1, (int)($_GET['page'] ?? 1));
$recordOffset = ($recordPage - 1) * $recordLimit;
$transactionIdSearch = trim($_GET['transaction_id'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$recordWhere = "WHERE MemberID = :member_id AND TransactionType IN ('contribution', 'withdrawal')";
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
if ($transactionIdSearch !== '') {
    $recordQueryParams['transaction_id'] = $transactionIdSearch;
}
if ($dateFrom !== '') {
    $recordQueryParams['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $recordQueryParams['date_to'] = $dateTo;
}

$loans = [
    [
        'title' => 'EMERGENCY LOAN',
        'img' => '../assets/img/emergency _loans.jfif',
        'desc' => 'Members may access up to two emergency facilities at a time, with support of up to KES 300,000 repayable within 12 months.'
    ],
    [
        'title' => 'ELIMU LOAN',
        'img' => '../assets/img/junior.jfif',
        'desc' => 'Education financing of up to KES 1,000,000, priced at 1% per month on a reducing balance.'
    ],
    [
        'title' => 'CAR LOAN',
        'img' => '../assets/img/financial_image.jfif',
        'desc' => 'Vehicle financing of up to KES 3,000,000, charged at 1% per month and repayable over a maximum of 48 months.'
    ],
    [
        'title' => 'KUJENGA LOAN',
        'img' => '../assets/img/development_loan.jfif',
        'desc' => 'Construction and building support of up to KES 4,000,000, or an amount guided by the collateral valuation, at 1.042% per month.'
    ],
    [
        'title' => 'PRINCIPAL LOAN',
        'img' => '../assets/img/Investment.jfif',
        'desc' => 'A main credit facility with a limit of up to KES 3,000,000 or three times member deposits, repayable within 48 months.'
    ],
    [
        'title' => 'KARIBU LOAN',
        'img' => '../assets/img/karibu_loan.jfif',
        'desc' => 'A member-friendly facility for building your financial journey through flexible terms and competitive SACCO lending support.'
    ],
    [
        'title' => 'BIASHARA LOAN',
        'img' => '../assets/img/member_centred.jfif',
        'desc' => 'Business financing designed to support enterprise needs with affordable access and a practical repayment plan.'
    ],
    [
        'title' => 'DEVELOPMENT LOAN',
        'img' => '../assets/img/development_loan.jfif',
        'desc' => 'Long-term development financing for major projects, with a maximum loan limit of KES 10,000,000.'
    ],
    [
        'title' => 'CHAPA LOAN',
        'img' => '../assets/img/salary_in_advance.jfif',
        'desc' => 'A flexible credit option that can provide access to up to 70% of a member\'s free savings.'
    ],
    [
        'title' => 'NUNUA SIMU LOAN',
        'img' => '../assets/img/about.jpg',
        'desc' => 'A convenient mobile loan option of up to KES 20,000, available without guarantorship once registered.'
    ]
];

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
  <title>Dashboard | Mashirikiano SACCO</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets/css/main.css" rel="stylesheet">
  <style>
    body { background: #eef4f0; color: #1d2b26; }
    .member-shell { max-width: 1180px; }
    .portal-header { background: #087a43; color: #fff; padding: 14px 0 10px; }
    .member-brand { display: flex; align-items: center; gap: 12px; }
    .member-brand img { background: #fff; border-radius: 50%; padding: 4px; }
    .member-top-nav { display: flex; flex-wrap: wrap; justify-content: center; gap: 22px; background: #fff; border-bottom: 1px solid #dce8e1; box-shadow: 0 8px 24px rgba(28, 77, 51, .08); position: sticky; top: 0; z-index: 1030; }
    .member-top-nav a { color: #5e7068; font-weight: 700; text-decoration: none; padding: 14px 0 12px; border-bottom: 3px solid transparent; }
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
    .metric { border: 0; border-radius: 8px; box-shadow: 0 10px 30px rgba(20, 55, 38, .08); }
    .metric-icon { width: 38px; height: 38px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: #e7f5ed; color: #087a43; }
    .metric-icon.danger { background: #fdeaea; color: #c03232; }
    .recent-row { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid #edf2ef; }
    .recent-row:last-child { border-bottom: 0; }
    .recent-icon { width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: #e7f5ed; color: #087a43; flex: 0 0 32px; }
    .remarks-link { color: #087a43; font-weight: 700; text-decoration: none; }
    .remarks-link:hover { text-decoration: underline; }
    .loan-card {
      border: 0;
      border-radius: 8px;
      box-shadow: 0 10px 30px rgba(0,0,0,.05);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      overflow: hidden;
    }
    .loan-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 15px 35px rgba(11, 59, 102, 0.15);
    }
    .loan-card-img {
      height: 180px;
      object-fit: cover;
      width: 100%;
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
    .table thead th { color: #617083; font-size: .78rem; text-transform: uppercase; letter-spacing: .02em; }
    @media (max-width: 767px) {
      .member-top-nav { gap: 14px; overflow-x: auto; justify-content: flex-start; padding: 0 14px; flex-wrap: nowrap; }
      .member-top-nav a { white-space: nowrap; }
      .member-hero { padding: 18px; }
      .table { min-width: 760px; }
    }
    @media (max-width: 480px) {
      .member-profile-summary { align-items: flex-start; gap: 12px; }
      .member-profile-photo { width: 68px; height: 68px; flex-basis: 68px; }
      .member-profile-name { font-size: 1.25rem; }
      .member-profile-id { font-size: .95rem; }
      .profile-detail-grid { grid-template-columns: 1fr; }
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

  <nav class="member-top-nav" aria-label="Member navigation">
    <a class="<?= $activeTab === 'dashboard' ? 'active' : '' ?>" href="?tab=dashboard">Dashboard</a>
    <a class="<?= $activeTab === 'loan' ? 'active' : '' ?>" href="?tab=loan">Loans</a>
    <a class="<?= $activeTab === 'applications' ? 'active' : '' ?>" href="?tab=applications">My Applications</a>
    <a class="<?= $activeTab === 'record' ? 'active' : '' ?>" href="?tab=record">Transactions</a>
  </nav>

  <main class="container-fluid member-shell py-4">
    <?php if ($message): ?>
      <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show" role="alert">
        <?= e($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Tab Content -->
    <div class="tab-content" id="dashboardTabsContent">
      
      <!-- DASHBOARD TAB -->
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
          <div class="col-md-6">
            <div class="card metric h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <span class="metric-icon"><i class="bi bi-check2-circle"></i></span>
                <div>
                  <div class="text-muted small">Total Contributions</div>
                  <div class="h4 fw-bold mb-0">KES <?= number_format($total, 2) ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card metric h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <span class="metric-icon danger"><i class="bi bi-cash-stack"></i></span>
                <div>
                  <div class="text-muted small">Total Loan Balance</div>
                  <div class="h4 fw-bold mb-0">KES <?= number_format($totalLoanBalance, 2) ?></div>
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
                  <div class="recent-row">
                    <span class="recent-icon"><i class="bi bi-arrow-down-left"></i></span>
                    <div class="flex-grow-1">
                      <div class="fw-bold"><?= e($transaction['TranID']) ?></div>
                      <div class="text-muted small"><?= e($transaction['TranTime'] ?: $transaction['CreatedAt']) ?> &middot; <?= e($transaction['MSISDN']) ?></div>
                    </div>
                    <div class="fw-bold text-success text-end">+ KES <?= number_format((float)$transaction['Amount'], 2) ?></div>
                  </div>
                <?php endforeach; ?>
                <?php if (!$recentTransactions): ?>
                  <div class="text-muted text-center py-3">No contributions found.</div>
                <?php endif; ?>
              </div>
            </div>
          </section>
        </div>
      <?php endif; ?>

      <!-- LOAN TAB -->
      <?php if ($activeTab === 'loan'): ?>
        <!-- Loan Services Catalog -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
          <h1 class="h4 fw-bold mb-0">Available Loan Services</h1>
          <a class="remarks-link" href="?tab=applications">My Applications</a>
        </div>
        <div class="row g-4">
          <?php foreach ($loans as $index => $loan): ?>
            <div class="col-md-6 col-lg-4">
              <div class="card loan-card h-100">
                <img src="<?= e($loan['img']) ?>" class="card-img-top loan-card-img" alt="<?= e($loan['title']) ?>">
                <div class="card-body d-flex flex-column">
                  <h5 class="card-title fw-bold text-primary mb-2" style="font-size: 1.1rem;"><?= e($loan['title']) ?></h5>
                  <p class="card-text text-muted small flex-grow-1"><?= e($loan['desc']) ?></p>
                  <button 
                    type="button" 
                    class="btn apply-btn w-100 py-2 mt-3 rounded-3" 
                    data-bs-toggle="modal" 
                    data-bs-target="#applyLoanModal" 
                    data-loan-title="<?= e($loan['title']) ?>"
                  >
                    Apply Now
                  </button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Apply Loan Modal -->
        <div class="modal fade" id="applyLoanModal" tabindex="-1" aria-labelledby="applyLoanModalLabel" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg">
              <form method="post" action="?tab=loan">
                <input type="hidden" name="apply_loan" value="1">
                <div class="modal-header bg-primary text-white border-0">
                  <h5 class="modal-title fw-bold" id="applyLoanModalLabel">Loan Application</h5>
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                  <div class="mb-3">
                    <label for="modalLoanType" class="form-label fw-bold">Loan Service</label>
                    <input type="text" class="form-control bg-light fw-bold text-primary" id="modalLoanType" name="loan_type" readonly>
                  </div>
                  <div class="mb-3">
                    <label for="modalAmount" class="form-label fw-bold">Requested Amount (KES)</label>
                    <input type="number" class="form-control" id="modalAmount" name="amount" min="1" step="0.01" placeholder="e.g. 50000" required>
                  </div>
                  <div class="mb-3">
                    <label for="modalReturnDate" class="form-label fw-bold">Expected Return Date</label>
                    <input type="date" class="form-control" id="modalReturnDate" name="return_date" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                  </div>
                  <div class="alert alert-info small py-2 mb-0">
                    A confirmation SMS will be sent to your registered phone: <strong><?= e($member['PrimaryNumber']) ?></strong>.
                  </div>
                </div>
                <div class="modal-footer border-0 p-3">
                  <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                  <button type="submit" class="btn apply-btn px-4">Submit Application</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- APPLICATIONS TAB -->
      <?php if ($activeTab === 'applications'): ?>
        <section class="card metric">
          <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
              <div>
                <h1 class="h4 fw-bold mb-1">My Applications</h1>
                <div class="text-muted small">All submitted loan requests and feedback from the SACCO team.</div>
              </div>
              <a href="?tab=loan" class="btn apply-btn">Apply for a Loan</a>
            </div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead>
                  <tr>
                    <th>Loan Type</th>
                    <th>Applied Date</th>
                    <th>Amount</th>
                    <th>Return Date</th>
                    <th>Status</th>
                    <th>Remarks</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($loanApplications as $app): ?>
                    <tr>
                      <td class="fw-bold"><?= e($app['LoanType']) ?></td>
                      <td><?= e($app['CreatedAt']) ?></td>
                      <td class="fw-bold">KES <?= number_format((float)$app['Amount'], 2) ?></td>
                      <td><?= e($app['ReturnDate']) ?></td>
                      <td>
                        <?php if ($app['Status'] === 'Approved'): ?>
                          <strong class="text-success">Approved</strong>
                        <?php elseif ($app['Status'] === 'Not Approved'): ?>
                          <strong class="text-danger">Not Approved</strong>
                        <?php else: ?>
                          <strong class="text-warning">Pending</strong>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (trim((string)$app['RejectionReason']) !== ''): ?>
                          <a
                            href="#"
                            class="remarks-link"
                            data-bs-toggle="modal"
                            data-bs-target="#applicationRemarksModal"
                            data-remarks="<?= e($app['RejectionReason']) ?>"
                          >View remarks</a>
                        <?php else: ?>
                          <span class="text-muted small">-</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$loanApplications): ?>
                    <tr><td colspan="6" class="text-muted text-center py-3">You have not applied for any loans yet.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      <?php endif; ?>

      <!-- RECORD TAB -->
      <?php if ($activeTab === 'record'): ?>
        <section class="card metric">
          <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
              <h3 class="h5 fw-bold mb-0">Total Contribution Ledger</h3>
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
                    <th class="text-end">Contribution Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($paginatedTransactions as $row): ?>
                    <tr>
                      <td class="fw-bold text-primary"><?= e($row['TranID']) ?></td>
                      <td><?= e($row['TranTime'] ?: $row['CreatedAt']) ?></td>
                      <td class="text-end fw-bold">KES <?= number_format((float)$row['Amount'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$paginatedTransactions): ?>
                    <tr><td colspan="3" class="text-muted text-center py-3">No contributions found.</td></tr>
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

  <div class="modal fade" id="applicationRemarksModal" tabindex="-1" aria-labelledby="applicationRemarksModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
      <div class="modal-content border-0 shadow-lg">
        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="applicationRemarksModalLabel">Application Remarks</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-0 text-muted" id="applicationRemarksText"></p>
        </div>
      </div>
    </div>
  </div>

  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    // Dynamically inject clicked loan details into modal
    const applyLoanModal = document.getElementById('applyLoanModal');
    if (applyLoanModal) {
      applyLoanModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const loanTitle = button.getAttribute('data-loan-title');
        const modalInputLoanType = applyLoanModal.querySelector('#modalLoanType');
        modalInputLoanType.value = loanTitle;
      });
    }

    const applicationRemarksModal = document.getElementById('applicationRemarksModal');
    if (applicationRemarksModal) {
      applicationRemarksModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        applicationRemarksModal.querySelector('#applicationRemarksText').textContent = button.getAttribute('data-remarks') || 'No remarks provided.';
      });
    }
  </script>
</body>
</html>
