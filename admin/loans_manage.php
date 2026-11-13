<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/LoanService.php';
require_once __DIR__ . '/admin_layout.php';

Auth::requireAdmin();
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

// -------------------------------------------------------------
// Action: Approve Loan Application
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_application') {
    try {
        $appId  = (int)($_POST['application_id'] ?? 0);
        $result = LoanService::approveApplication($pdo, $appId);
        $_SESSION['flash_message']      = "Application #$appId approved! Active loan activated. M-Pesa Repayment Account: " . $result['repayment_account'];
        $_SESSION['flash_message_type'] = 'success';
        header("Location: loans_manage?tab=pending");
        exit;
    } catch (Throwable $e) {
        $message     = "Approval failed: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// -------------------------------------------------------------
// Action: Reject Loan Application
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_application') {
    try {
        $appId  = (int)($_POST['application_id'] ?? 0);
        $reason = trim((string)($_POST['rejection_reason'] ?? 'Application does not meet lending criteria.'));
        LoanService::rejectApplication($pdo, $appId, $reason);
        $_SESSION['flash_message']      = "Application #$appId has been marked as REJECTED.";
        $_SESSION['flash_message_type'] = 'warning';
        header("Location: loans_manage?tab=pending");
        exit;
    } catch (Throwable $e) {
        $message     = "Rejection failed: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// -------------------------------------------------------------
// Action: Catch up interest
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refresh_accrual') {
    try {
        $activeLoanId = (int)($_POST['active_loan_id'] ?? 0);
        $loan = LoanService::catchUpInterest($pdo, $activeLoanId);
        $_SESSION['flash_message']      = "Interest accrual refreshed for loan #$activeLoanId. Accrued Interest: KES " . number_format((float)$loan['accruedInterest'], 2);
        $_SESSION['flash_message_type'] = 'success';
        header("Location: loans_manage?tab=" . urlencode($_GET['tab'] ?? 'all'));
        exit;
    } catch (Throwable $e) {
        $message     = "Accrual failed: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// -------------------------------------------------------------
// Action: Manual EOD Batch Trigger
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_eod_batch') {
    try {
        $batch = LoanService::runEndOfDayBatch($pdo);
        $_SESSION['flash_message']      = "EOD batch completed! Processed: {$batch['processed']} loans | Flagged in Arrears: {$batch['flagged_in_arrears']} | Shifted Interest: KES " . number_format($batch['shifted_interest'], 2);
        $_SESSION['flash_message_type'] = 'info';
        header("Location: loans_manage?tab=" . urlencode($_GET['tab'] ?? 'all'));
        exit;
    } catch (Throwable $e) {
        $message     = "EOD batch error: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Filter Tabs & Search
$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'all')));
if (!in_array($activeTab, ['all', 'active', 'in_arrears', 'closed', 'pending'], true)) {
    $activeTab = 'all';
}
$search = trim((string)($_GET['search'] ?? ''));

// Pending badge count
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM loan_application WHERE status = 'PENDING'")->fetchColumn();

// Active Loans Query
$loansQuery = "
    SELECT al.*, m.FirstName, m.LastName, m.NationalID, la.loanType, la.createdAt AS appliedAt
    FROM active_loan al
    JOIN members m ON m.MemberID = al.memberID
    JOIN loan_application la ON la.loanApplicationID = al.loanApplicationID
    WHERE 1=1
";
$params = [];
if ($activeTab === 'active')      $loansQuery .= " AND al.loanStatus = 'ACTIVE'";
elseif ($activeTab === 'in_arrears') $loansQuery .= " AND al.loanStatus = 'IN_ARREARS'";
elseif ($activeTab === 'closed')  $loansQuery .= " AND al.loanStatus = 'CLOSED'";
if ($search !== '') {
    $loansQuery .= " AND (m.FirstName LIKE :s OR m.LastName LIKE :s OR al.memberID LIKE :s OR al.repayment_account LIKE :s OR la.loanType LIKE :s)";
    $params[':s'] = "%$search%";
}
$loansQuery .= " ORDER BY al.activeLoanID DESC";
$activeLoansStmt = $pdo->prepare($loansQuery);
$activeLoansStmt->execute($params);
$activeLoans = $activeLoansStmt->fetchAll();

// Pending Applications Query
$pendingQuery = "
    SELECT la.*, m.FirstName, m.LastName, m.PrimaryNumber, m.NationalID,
           l.maxAmount AS productMax, l.paymentPeriod AS productPeriod,
           l.loanInterest AS productRate, l.interestType AS productInterestType
    FROM loan_application la
    JOIN members m ON m.MemberID = la.memberID
    JOIN loan l ON l.loanID = la.loanID
    WHERE la.status = 'PENDING'
";
$pendingParams = [];
if ($search !== '') {
    $pendingQuery .= " AND (m.FirstName LIKE :ps OR m.LastName LIKE :ps OR la.memberID LIKE :ps OR la.loanType LIKE :ps)";
    $pendingParams[':ps'] = "%$search%";
}
$pendingQuery .= " ORDER BY la.loanApplicationID DESC";
$pendingStmt = $pdo->prepare($pendingQuery);
$pendingStmt->execute($pendingParams);
$pendingApplications = $pendingStmt->fetchAll();

// Ledger view if requested
$selectedLoanLedger = [];
$viewLoanId = (int)($_GET['view_ledger'] ?? 0);
if ($viewLoanId > 0) {
    $txStmt = $pdo->prepare("SELECT * FROM loan_transaction WHERE activeLoanID = :id ORDER BY transactionID DESC");
    $txStmt->execute([':id' => $viewLoanId]);
    $selectedLoanLedger = $txStmt->fetchAll();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Monitor Member Loans | Mashirikiano SACCO Admin</title>
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
    .account-badge { font-family: monospace; font-size: 0.9rem; font-weight: 700; letter-spacing: 0.05em; background: #eef4fb; color: #0b3b66; border: 1px solid #c8d8ea; padding: 2px 8px; border-radius: 6px; }
    .ledger-waterfall-badge { font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; margin-right: 3px; display: inline-block; }
  </style>
</head>
<body>

  <?php admin_header('loans_manage', 'Monitor Member Loans'); ?>

  <main class="container-fluid admin-shell py-4">

    <!-- Page Title Row -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h3 fw-bold text-dark mb-1">Monitor Member Loans</h1>
        <p class="text-muted mb-0">Live operational ledger, real-time balances, approval queue, and audit trail.</p>
      </div>
      <div class="d-flex gap-2">
        <form method="post" action="loans_manage" onsubmit="return confirm('Run daily interest accrual catch-up and check for arrears on all active loans?');">
          <input type="hidden" name="action" value="run_eod_batch">
          <button type="submit" class="btn btn-outline-secondary fw-semibold">
            Run EOD Batch
          </button>
        </form>
        <a href="loans_create" class="btn btn-primary fw-semibold">
          New Product
        </a>
      </div>
    </div>

    <!-- Flash Message -->
    <?php if ($message): ?>
      <div class="alert alert-<?= admin_e($messageType) ?> alert-dismissible fade show shadow-sm" role="alert">
        <?= admin_e($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- FILTER TABS & SEARCH -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body p-3">
        <div class="row g-3 align-items-center">
          <div class="col-lg-8">
            <ul class="nav nav-pills gap-2">
              <?php
              $tabs = [
                'all'        => 'All Loans',
                'active'     => 'Active',
                'in_arrears' => 'In Arrears',
                'closed'     => 'Closed',
                'pending'    => 'Pending Approval',
              ];
              foreach ($tabs as $tabKey => $tabLabel):
                $isActive = ($activeTab === $tabKey);
                $href = "loans_manage?tab={$tabKey}" . ($search ? '&search=' . urlencode($search) : '');
              ?>
                <li class="nav-item">
                  <a class="nav-link <?= $isActive ? 'active' : '' ?> position-relative
                     <?= !$isActive ? 'bg-white border' : '' ?>"
                     style="<?= !$isActive ? 'color:#4b6279;' : '' ?> border-radius:8px; font-weight:600; padding:8px 16px;"
                     href="<?= admin_e($href) ?>">
                    <?= admin_e($tabLabel) ?>
                    <?php if ($tabKey === 'pending' && $pendingCount > 0): ?>
                      <span class="badge bg-danger ms-1"><?= $pendingCount ?></span>
                    <?php endif; ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="col-lg-4">
            <form method="get" action="loans_manage" class="d-flex gap-2">
              <input type="hidden" name="tab" value="<?= admin_e($activeTab) ?>">
              <div class="input-group input-group-sm">
                <input type="text" name="search" class="form-control"
                       placeholder="Search by name, ID, or 5-char code"
                       value="<?= admin_e($search) ?>">
                <button class="btn btn-outline-primary" type="submit">Search</button>
                <?php if ($search !== ''): ?>
                  <a href="loans_manage?tab=<?= admin_e($activeTab) ?>" class="btn btn-outline-secondary" title="Clear">
                    Clear
                  </a>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- CONTENT VIEW 1: ACTIVE / MONITOR LOAN TABLE -->
    <?php if ($activeTab !== 'pending'): ?>
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom">
          <h2 class="h5 fw-bold text-dark mb-0">
            <?php
              $tabHeaders = ['all' => 'All Loans', 'active' => 'Active Loans', 'in_arrears' => 'In Arrears', 'closed' => 'Closed Loans'];
              echo admin_e($tabHeaders[$activeTab] ?? 'Loans');
            ?>
          </h2>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-custom align-middle mb-0">
              <thead>
                <tr>
                  <th class="ps-3">Member</th>
                  <th>Loan Type &amp; Repayment Account</th>
                  <th>Principal Balance</th>
                  <th>Interest Owed</th>
                  <th>Disbursed</th>
                  <th>Due Date</th>
                  <th>Status</th>
                  <th class="text-end pe-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($activeLoans as $row): ?>
                  <?php
                    $interestOwed = (float)$row['accruedInterest'] + (float)$row['billedUnpaidInterest'];
                    $statusClass = match($row['loanStatus']) {
                      'ACTIVE'     => 'success',
                      'IN_ARREARS' => 'danger',
                      'CLOSED'     => 'secondary',
                      default      => 'secondary',
                    };
                  ?>
                  <tr>
                    <td class="ps-3">
                      <div class="fw-bold text-dark"><?= admin_e($row['FirstName'] . ' ' . $row['LastName']) ?></div>
                      <small class="text-muted">ID: <?= admin_e($row['memberID']) ?> &middot; Nat: <?= admin_e($row['NationalID']) ?></small>
                    </td>
                    <td>
                      <div class="fw-semibold text-primary"><?= admin_e($row['loanType']) ?></div>
                      <span class="account-badge mt-1 d-inline-block">
                        <?= admin_e($row['repayment_account']) ?>
                      </span>
                    </td>
                    <td>
                      <div class="fw-bold text-dark">KES <?= number_format((float)$row['principalBalance'], 2) ?></div>
                      <small class="text-muted">Orig: KES <?= number_format((float)$row['originalPrincipal'], 2) ?></small>
                    </td>
                    <td>
                      <div class="fw-semibold text-danger">KES <?= number_format($interestOwed, 2) ?></div>
                      <small class="text-muted" style="font-size:0.75rem;">
                        Accrued: <?= number_format((float)$row['accruedInterest'], 2) ?> | Billed: <?= number_format((float)$row['billedUnpaidInterest'], 2) ?>
                      </small>
                    </td>
                    <td class="small text-muted"><?= admin_e($row['disbursementDate']) ?></td>
                    <td>
                      <span class="small fw-semibold <?= $row['loanStatus'] === 'IN_ARREARS' ? 'text-danger fw-bold' : 'text-dark' ?>">
                        <?= admin_e($row['returnDate']) ?>
                      </span>
                    </td>
                    <td>
                      <span class="badge bg-<?= $statusClass ?> rounded-pill px-3">
                        <?= admin_e($row['loanStatus']) ?>
                      </span>
                    </td>
                    <td class="text-end pe-3">
                      <div class="btn-group btn-group-sm">
                        <form method="post" action="loans_manage?tab=<?= admin_e($activeTab) ?>" class="d-inline">
                          <input type="hidden" name="action" value="refresh_accrual">
                          <input type="hidden" name="active_loan_id" value="<?= (int)$row['activeLoanID'] ?>">
                          <button type="submit" class="btn btn-outline-info" title="Recalculate daily interest accrual">
                            Refresh
                          </button>
                        </form>
                        <a href="loans_manage?tab=<?= admin_e($activeTab) ?>&view_ledger=<?= (int)$row['activeLoanID'] ?>"
                           class="btn btn-outline-primary fw-semibold">
                          Ledger
                        </a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($activeLoans)): ?>
                  <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                      No member loans found matching this filter criteria.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    <?php else: ?>
      <!-- CONTENT VIEW 2: APPROVAL QUEUE (PENDING APPLICATIONS) -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom">
          <h2 class="h5 fw-bold text-dark mb-0">
            Loan Applications Approval Queue
          </h2>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-custom align-middle mb-0">
              <thead>
                <tr>
                  <th class="ps-3">App ID</th>
                  <th>Member Details</th>
                  <th>Loan Product</th>
                  <th>Requested Amount</th>
                  <th>Clearance Target</th>
                  <th>Applied Date</th>
                  <th class="text-end pe-3">Review Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pendingApplications as $app): ?>
                  <tr>
                    <td class="ps-3 fw-bold text-muted">#<?= (int)$app['loanApplicationID'] ?></td>
                    <td>
                      <div class="fw-bold"><?= admin_e($app['FirstName'] . ' ' . $app['LastName']) ?></div>
                      <small class="text-muted">Member ID: <?= admin_e($app['memberID']) ?> &middot; Phone: <?= admin_e($app['PrimaryNumber']) ?></small>
                    </td>
                    <td>
                      <div class="fw-semibold text-primary"><?= admin_e($app['loanType']) ?></div>
                      <small class="text-muted">
                        Rate: <?= number_format((float)$app['productRate'] * 100, 2) ?>% &middot; <?= admin_e($app['productInterestType']) ?>
                      </small>
                    </td>
                    <td class="fw-bold text-success">KES <?= number_format((float)$app['amount'], 2) ?></td>
                    <td>
                      <span class="badge bg-light text-dark border"><?= admin_e($app['returndate']) ?></span>
                    </td>
                    <td class="small text-muted"><?= admin_e($app['createdAt']) ?></td>
                    <td class="text-end pe-3">
                      <div class="d-inline-flex gap-2">
                        <!-- Approve -->
                        <form method="post" action="loans_manage"
                              onsubmit="return confirm('Approve this application? A unique 5-character M-Pesa routing account will be generated and the member notified via SMS.');">
                          <input type="hidden" name="action" value="approve_application">
                          <input type="hidden" name="application_id" value="<?= (int)$app['loanApplicationID'] ?>">
                          <button type="submit" class="btn btn-sm btn-success fw-bold">
                            Approve
                          </button>
                        </form>

                        <!-- Reject Button -->
                        <button type="button" class="btn btn-sm btn-outline-danger fw-bold"
                                data-bs-toggle="modal"
                                data-bs-target="#rejectModal<?= (int)$app['loanApplicationID'] ?>">
                          Reject
                        </button>
                      </div>

                      <!-- Reject Modal -->
                      <div class="modal fade" id="rejectModal<?= (int)$app['loanApplicationID'] ?>"
                           tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                          <div class="modal-content border-0 shadow-lg text-start">
                            <form method="post" action="loans_manage">
                              <input type="hidden" name="action" value="reject_application">
                              <input type="hidden" name="application_id" value="<?= (int)$app['loanApplicationID'] ?>">
                              <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title fw-bold">
                                  Reject Application #<?= (int)$app['loanApplicationID'] ?>
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                              </div>
                              <div class="modal-body p-4">
                                <p class="mb-3 text-muted small">
                                  Specify the official reason for denial. This will be logged and sent to the member via SMS.
                                </p>
                                <div class="mb-3">
                                  <label class="form-label fw-semibold">Reason for Rejection <span class="text-danger">*</span></label>
                                  <textarea name="rejection_reason" class="form-control" rows="3" required
                                    placeholder="e.g. Savings threshold insufficient, ongoing facility past due, incomplete documentation..."></textarea>
                                </div>
                                <div class="alert alert-warning py-2 small mb-0">
                                  An SMS notification will be dispatched to the member upon confirmation.
                                </div>
                              </div>
                              <div class="modal-footer bg-light">
                                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-danger fw-bold px-4">
                                  Confirm Rejection
                                </button>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($pendingApplications)): ?>
                  <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                      All caught up! No pending loan applications waiting for review.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Link Banner to Portfolio -->
    <div class="card border-0 shadow-sm bg-white p-4 mt-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
          <h3 class="h6 fw-bold mb-1 text-dark">Need the full executive analytics view?</h3>
          <p class="text-muted small mb-0">View KPI summary cards, liquidity trend charts, PAR rate, and product performance analytics in Loan Portfolio.</p>
        </div>
        <a href="loans_portfolio" class="btn btn-outline-success fw-semibold px-4 shadow-sm">
          Open Loan Portfolio
        </a>
      </div>
    </div>

  </main>

  <!-- VIEW LEDGER MODAL -->
  <?php if ($viewLoanId > 0): ?>
    <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" aria-modal="true" role="dialog">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title fw-bold">
              Loan Audit Trail &amp; Repayment Ledger (Active Loan #<?= $viewLoanId ?>)
            </h5>
            <a href="loans_manage?tab=<?= admin_e($activeTab) ?>" class="btn-close btn-close-white"></a>
          </div>
          <div class="modal-body p-4">
            <div class="table-responsive">
              <table class="table table-sm table-striped align-middle">
                <thead class="table-dark">
                  <tr>
                    <th>Tx ID</th>
                    <th>Date</th>
                    <th>Event Type</th>
                    <th>M-Pesa Receipt</th>
                    <th class="text-end">Total Amount</th>
                    <th>Waterfall Split (Penalty | Billed | Accrued | Principal)</th>
                    <th class="text-end">Principal Snapshot</th>
                    <th class="text-end">Accrued Snapshot</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($selectedLoanLedger as $tx): ?>
                    <tr>
                      <td>#<?= (int)$tx['transactionID'] ?></td>
                      <td><?= admin_e($tx['transactionDate']) ?></td>
                      <td>
                        <span class="badge bg-<?= $tx['transactionType'] === 'REPAYMENT' ? 'success' : ($tx['transactionType'] === 'DISBURSEMENT' ? 'primary' : 'warning text-dark') ?>">
                          <?= admin_e($tx['transactionType']) ?>
                        </span>
                      </td>
                      <td><?= admin_e($tx['mpesaReceiptNumber'] ?: '-') ?></td>
                      <td class="text-end fw-bold">KES <?= number_format((float)$tx['totalAmount'], 2) ?></td>
                      <td>
                        <span class="ledger-waterfall-badge bg-danger text-white" title="Penalty">Pen: <?= number_format((float)$tx['allocatedPenalty'], 2) ?></span>
                        <span class="ledger-waterfall-badge bg-warning text-dark" title="Billed Interest">Bil: <?= number_format((float)$tx['allocatedBilledInterest'], 2) ?></span>
                        <span class="ledger-waterfall-badge bg-info text-dark" title="Accrued Interest">Acc: <?= number_format((float)$tx['allocatedAccruedInterest'], 2) ?></span>
                        <span class="ledger-waterfall-badge bg-success text-white" title="Principal">Prin: <?= number_format((float)$tx['allocatedPrincipal'], 2) ?></span>
                      </td>
                      <td class="text-end fw-semibold text-primary">KES <?= number_format((float)$tx['principalAfter'], 2) ?></td>
                      <td class="text-end fw-semibold text-danger">KES <?= number_format((float)$tx['accruedInterestAfter'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (empty($selectedLoanLedger)): ?>
                    <tr>
                      <td colspan="8" class="text-center text-muted py-4">No audit transactions found for this loan record.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div class="modal-footer">
            <a href="loans_manage?tab=<?= admin_e($activeTab) ?>" class="btn btn-secondary">Close Ledger</a>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
