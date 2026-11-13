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
        $appId = (int)($_POST['application_id'] ?? 0);
        $result = LoanService::approveApplication($pdo, $appId);

        $_SESSION['flash_message'] = "Application #$appId approved successfully! Active loan activated with M-Pesa Repayment Account: " . $result['repayment_account'];
        $_SESSION['flash_message_type'] = 'success';
        header("Location: loans_manage.php?tab=pending");
        exit;
    } catch (Throwable $e) {
        $message = "Approval failed: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// -------------------------------------------------------------
// Action: Reject Loan Application
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_application') {
    try {
        $appId = (int)($_POST['application_id'] ?? 0);
        $reason = trim((string)($_POST['rejection_reason'] ?? 'Application does not meet lending criteria.'));
        LoanService::rejectApplication($pdo, $appId, $reason);

        $_SESSION['flash_message'] = "Application #$appId has been marked as REJECTED.";
        $_SESSION['flash_message_type'] = 'warning';
        header("Location: loans_manage.php?tab=pending");
        exit;
    } catch (Throwable $e) {
        $message = "Rejection failed: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// -------------------------------------------------------------
// Action: Catch up interest manually for an active loan
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refresh_accrual') {
    try {
        $activeLoanId = (int)($_POST['active_loan_id'] ?? 0);
        $loan = LoanService::catchUpInterest($pdo, $activeLoanId);

        $_SESSION['flash_message'] = "Interest accrual refreshed for loan #$activeLoanId. Accrued Interest: KES " . number_format((float)$loan['accruedInterest'], 2);
        $_SESSION['flash_message_type'] = 'success';
        header("Location: loans_manage.php?tab=" . urlencode($_GET['tab'] ?? 'all'));
        exit;
    } catch (Throwable $e) {
        $message = "Accrual failed: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// -------------------------------------------------------------
// Action: Manual EOD Batch Trigger
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_eod_batch') {
    try {
        $batch = LoanService::runEndOfDayBatch($pdo);
        $_SESSION['flash_message'] = "EOD batch completed! Processed: {$batch['processed']} loans | Flagged in Arrears: {$batch['flagged_in_arrears']} | Shifted Interest: KES " . number_format($batch['shifted_interest'], 2);
        $_SESSION['flash_message_type'] = 'info';
        header("Location: loans_manage.php?tab=" . urlencode($_GET['tab'] ?? 'all'));
        exit;
    } catch (Throwable $e) {
        $message = "EOD batch execution error: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Filter Tabs & Search
$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'all')));
if (!in_array($activeTab, ['all', 'active', 'in_arrears', 'closed', 'pending'], true)) {
    $activeTab = 'all';
}
$search = trim((string)($_GET['search'] ?? ''));

// Fetch pending applications count for tab badge
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM loan_application WHERE status = 'PENDING'")->fetchColumn();

// Fetch Active Loans Data
$loansQuery = "
    SELECT 
        al.*,
        m.FirstName,
        m.LastName,
        m.NationalID,
        la.loanType,
        la.createdAt as appliedAt
    FROM active_loan al
    JOIN members m ON m.MemberID = al.memberID
    JOIN loan_application la ON la.loanApplicationID = al.loanApplicationID
    WHERE 1=1
";
$params = [];

if ($activeTab === 'active') {
    $loansQuery .= " AND al.loanStatus = 'ACTIVE'";
} elseif ($activeTab === 'in_arrears') {
    $loansQuery .= " AND al.loanStatus = 'IN_ARREARS'";
} elseif ($activeTab === 'closed') {
    $loansQuery .= " AND al.loanStatus = 'CLOSED'";
}

if ($search !== '') {
    $loansQuery .= " AND (
        m.FirstName LIKE :s OR
        m.LastName LIKE :s OR
        al.memberID LIKE :s OR
        al.repayment_account LIKE :s OR
        la.loanType LIKE :s
    )";
    $params[':s'] = "%$search%";
}

$loansQuery .= " ORDER BY al.activeLoanID DESC";
$activeLoansStmt = $pdo->prepare($loansQuery);
$activeLoansStmt->execute($params);
$activeLoans = $activeLoansStmt->fetchAll();

// Fetch Pending Applications Data
$pendingQuery = "
    SELECT 
        la.*,
        m.FirstName,
        m.LastName,
        m.PrimaryNumber,
        m.NationalID,
        l.maxAmount as productMax,
        l.paymentPeriod as productPeriod,
        l.loanInterest as productRate,
        l.interestType as productInterestType
    FROM loan_application la
    JOIN members m ON m.MemberID = la.memberID
    JOIN loan l ON l.loanID = la.loanID
    WHERE la.status = 'PENDING'
";
$pendingParams = [];
if ($search !== '') {
    $pendingQuery .= " AND (
        m.FirstName LIKE :ps OR
        m.LastName LIKE :ps OR
        la.memberID LIKE :ps OR
        la.loanType LIKE :ps
    )";
    $pendingParams[':ps'] = "%$search%";
}
$pendingQuery .= " ORDER BY la.loanApplicationID DESC";
$pendingStmt = $pdo->prepare($pendingQuery);
$pendingStmt->execute($pendingParams);
$pendingApplications = $pendingStmt->fetchAll();

// Fetch Transactions for Ledger Modal if requested via AJAX or page query
$selectedLoanLedger = [];
$viewLoanId = (int)($_GET['view_ledger'] ?? 0);
if ($viewLoanId > 0) {
    $txStmt = $pdo->prepare("
        SELECT * FROM loan_transaction 
        WHERE activeLoanID = :id 
        ORDER BY transactionID DESC
    ");
    $txStmt->execute([':id' => $viewLoanId]);
    $selectedLoanLedger = $txStmt->fetchAll();
}

admin_header('loans_manage', 'Monitor Member Loans');
?>
<link rel="stylesheet" href="admin.css">
<style>
  .nav-pills-custom .nav-link {
    color: #4b6279;
    font-weight: 600;
    border-radius: 8px;
    padding: 8px 16px;
    background: #fff;
    border: 1px solid #dfe5ea;
  }
  .nav-pills-custom .nav-link.active {
    background: #0b3b66;
    color: #fff;
    border-color: #0b3b66;
  }
  .account-badge {
    font-family: monospace;
    font-size: 0.95rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    background: #eef4fb;
    color: #0b3b66;
    border: 1px solid #c8d8ea;
    padding: 3px 8px;
    border-radius: 6px;
  }
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
  .ledger-waterfall-badge {
    font-size: 0.72rem;
    padding: 2px 6px;
    border-radius: 4px;
    margin-right: 3px;
    display: inline-block;
  }
</style>

<main class="container-fluid admin-shell py-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h1 class="h3 fw-bold text-dark mb-1">Monitor Member Loans</h1>
      <p class="text-muted mb-0">Live operational ledger, real-time balances, approval queue, and audit trail.</p>
    </div>
    <div class="d-flex gap-2">
      <form method="post" action="loans_manage.php" onsubmit="return confirm('Run daily interest accrual catch-up and check for arrears on all active loans?');">
        <input type="hidden" name="action" value="run_eod_batch">
        <button type="submit" class="btn btn-outline-secondary fw-semibold">
          <i class="bi bi-clock-history me-1"></i> Run EOD Batch
        </button>
      </form>
      <a href="loans_create.php" class="btn btn-primary fw-semibold">
        <i class="bi bi-plus-circle me-1"></i> New Product
      </a>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?= admin_e($messageType) ?> alert-dismissible fade show" role="alert">
      <?= admin_e($message) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <!-- FILTER TABS & SEARCH -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <ul class="nav nav-pills nav-pills-custom gap-2">
            <li class="nav-item">
              <a class="nav-link <?= $activeTab === 'all' ? 'active' : '' ?>" href="loans_manage.php?tab=all<?= $search ? '&search=' . urlencode($search) : '' ?>">
                All Loans
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?= $activeTab === 'active' ? 'active' : '' ?>" href="loans_manage.php?tab=active<?= $search ? '&search=' . urlencode($search) : '' ?>">
                Active
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?= $activeTab === 'in_arrears' ? 'active' : '' ?>" href="loans_manage.php?tab=in_arrears<?= $search ? '&search=' . urlencode($search) : '' ?>">
                In Arrears
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?= $activeTab === 'closed' ? 'active' : '' ?>" href="loans_manage.php?tab=closed<?= $search ? '&search=' . urlencode($search) : '' ?>">
                Closed
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?= $activeTab === 'pending' ? 'active' : '' ?> position-relative" href="loans_manage.php?tab=pending<?= $search ? '&search=' . urlencode($search) : '' ?>">
                Pending Approval
                <?php if ($pendingCount > 0): ?>
                  <span class="badge bg-danger ms-1"><?= $pendingCount ?></span>
                <?php endif; ?>
              </a>
            </li>
          </ul>
        </div>
        <div class="col-lg-4">
          <form method="get" action="loans_manage.php" class="input-group">
            <input type="hidden" name="tab" value="<?= admin_e($activeTab) ?>">
            <input type="text" name="search" class="form-control" placeholder="Search by name, ID, or 5-char code" value="<?= admin_e($search) ?>">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
            <?php if ($search !== ''): ?>
              <a href="loans_manage.php?tab=<?= admin_e($activeTab) ?>" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- CONTENT VIEW 1: ACTIVE / MONITOR LEAN TABLE -->
  <?php if ($activeTab !== 'pending'): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Member Name & ID</th>
                <th>Loan Type & Repayment Account</th>
                <th>Original vs Current Principal</th>
                <th>Total Interest Owed</th>
                <th>Disbursed</th>
                <th>Due Date</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($activeLoans as $row): ?>
                <?php 
                  $interestOwed = (float)$row['accruedInterest'] + (float)$row['billedUnpaidInterest'];
                  $totalPayoff = (float)$row['principalBalance'] + $interestOwed + (float)$row['penaltyBalance'];
                ?>
                <tr>
                  <td>
                    <div class="fw-bold text-dark"><?= admin_e($row['FirstName'] . ' ' . $row['LastName']) ?></div>
                    <small class="text-muted">ID: <?= admin_e($row['memberID']) ?> &middot; Nat: <?= admin_e($row['NationalID']) ?></small>
                  </td>
                  <td>
                    <div class="fw-semibold text-primary"><?= admin_e($row['loanType']) ?></div>
                    <span class="account-badge mt-1 d-inline-block">
                      <i class="bi bi-phone me-1"></i><?= admin_e($row['repayment_account']) ?>
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
                  <td class="small text-muted">
                    <?= admin_e($row['disbursementDate']) ?>
                  </td>
                  <td>
                    <span class="small fw-semibold <?= ($row['loanStatus'] === 'IN_ARREARS') ? 'text-danger fw-bold' : 'text-dark' ?>">
                      <?= admin_e($row['returnDate']) ?>
                    </span>
                  </td>
                  <td>
                    <span class="status-pill <?= admin_e($row['loanStatus']) ?>">
                      <?= admin_e($row['loanStatus']) ?>
                    </span>
                  </td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <form method="post" action="loans_manage.php?tab=<?= admin_e($activeTab) ?>" class="d-inline">
                        <input type="hidden" name="action" value="refresh_accrual">
                        <input type="hidden" name="active_loan_id" value="<?= (int)$row['activeLoanID'] ?>">
                        <button type="submit" class="btn btn-outline-info" title="Recalculate daily interest accrual">
                          <i class="bi bi-arrow-repeat"></i>
                        </button>
                      </form>
                      <a href="loans_manage.php?tab=<?= admin_e($activeTab) ?>&view_ledger=<?= (int)$row['activeLoanID'] ?>" class="btn btn-outline-primary fw-semibold">
                        <i class="bi bi-journal-text me-1"></i> View Ledger
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($activeLoans)): ?>
                <tr>
                  <td colspan="8" class="text-center text-muted py-5">
                    <i class="bi bi-folder-x fs-1 d-block text-secondary mb-2"></i>
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
      <div class="card-header bg-white py-3 border-0">
        <h2 class="h5 fw-bold text-dark mb-0">
          <i class="bi bi-hourglass-split me-1 text-warning"></i> Loan Applications Approval Queue
        </h2>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>App ID</th>
                <th>Member Details</th>
                <th>Loan Product</th>
                <th>Requested Amount</th>
                <th>Clearance Target</th>
                <th>Applied Date</th>
                <th class="text-end">Review Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pendingApplications as $app): ?>
                <tr>
                  <td class="fw-bold text-muted">#<?= (int)$app['loanApplicationID'] ?></td>
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
                  <td class="fw-bold text-success">
                    KES <?= number_format((float)$app['amount'], 2) ?>
                  </td>
                  <td>
                    <span class="badge bg-light text-dark border">
                      <?= admin_e($app['returndate']) ?>
                    </span>
                  </td>
                  <td class="small text-muted">
                    <?= admin_e($app['createdAt']) ?>
                  </td>
                  <td class="text-end">
                    <div class="d-inline-flex gap-2">
                      <!-- Approve Button & Modal -->
                      <form method="post" action="loans_manage.php" onsubmit="return confirm('Approve this loan application? This will generate a unique 5-character M-Pesa routing account, disburse the funds into active ledger, and notify the member via SMS.');">
                        <input type="hidden" name="action" value="approve_application">
                        <input type="hidden" name="application_id" value="<?= (int)$app['loanApplicationID'] ?>">
                        <button type="submit" class="btn btn-sm btn-success fw-bold">
                          <i class="bi bi-check-lg me-1"></i> Approve
                        </button>
                      </form>

                      <!-- Reject Button trigger modal -->
                      <button type="button" class="btn btn-sm btn-outline-danger fw-bold" data-bs-toggle="modal" data-bs-target="#rejectModal<?= (int)$app['loanApplicationID'] ?>">
                        <i class="bi bi-x-lg me-1"></i> Reject
                      </button>
                    </div>

                    <!-- Reject Modal -->
                    <div class="modal fade" id="rejectModal<?= (int)$app['loanApplicationID'] ?>" tabindex="-1" aria-hidden="true">
                      <div class="modal-dialog">
                        <div class="modal-content text-start border-0 shadow">
                          <form method="post" action="loans_manage.php">
                            <input type="hidden" name="action" value="reject_application">
                            <input type="hidden" name="application_id" value="<?= (int)$app['loanApplicationID'] ?>">
                            <div class="modal-header bg-danger text-white">
                              <h5 class="modal-title fw-bold">Reject Loan Application #<?= (int)$app['loanApplicationID'] ?></h5>
                              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-4">
                              <p class="mb-3">Please specify the official reason for denial. This reason will be logged and sent to the applicant via SMS.</p>
                              <div class="mb-3">
                                <label class="form-label fw-bold">Reason for Rejection <span class="text-danger">*</span></label>
                                <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="e.g. Savings threshold insufficient, ongoing facility past due, incomplete documentation..."></textarea>
                              </div>
                            </div>
                            <div class="modal-footer">
                              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                              <button type="submit" class="btn btn-danger fw-bold">Confirm Rejection</button>
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
                  <td colspan="7" class="text-center text-muted py-5">
                    <i class="bi bi-check2-circle fs-1 d-block text-success mb-2"></i>
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
</main>

<!-- VIEW LEDGER MODAL -->
<?php if ($viewLoanId > 0): ?>
  <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content border-0 shadow-lg">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title fw-bold">
            <i class="bi bi-journal-check me-2"></i> Loan Audit Trail & Repayment Ledger (Active Loan #<?= $viewLoanId ?>)
          </h5>
          <a href="loans_manage.php?tab=<?= admin_e($activeTab) ?>" class="btn-close btn-close-white"></a>
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
                    <td>
                      <?= admin_e($tx['mpesaReceiptNumber'] ?: '-') ?>
                    </td>
                    <td class="text-end fw-bold">
                      KES <?= number_format((float)$tx['totalAmount'], 2) ?>
                    </td>
                    <td>
                      <span class="ledger-waterfall-badge bg-danger text-white" title="Allocated Penalty">Pen: <?= number_format((float)$tx['allocatedPenalty'], 2) ?></span>
                      <span class="ledger-waterfall-badge bg-warning text-dark" title="Allocated Billed Interest">Bil: <?= number_format((float)$tx['allocatedBilledInterest'], 2) ?></span>
                      <span class="ledger-waterfall-badge bg-info text-dark" title="Allocated Accrued Interest">Acc: <?= number_format((float)$tx['allocatedAccruedInterest'], 2) ?></span>
                      <span class="ledger-waterfall-badge bg-success text-white" title="Allocated Principal">Prin: <?= number_format((float)$tx['allocatedPrincipal'], 2) ?></span>
                    </td>
                    <td class="text-end fw-semibold text-primary">
                      KES <?= number_format((float)$tx['principalAfter'], 2) ?>
                    </td>
                    <td class="text-end fw-semibold text-danger">
                      KES <?= number_format((float)$tx['accruedInterestAfter'], 2) ?>
                    </td>
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
          <a href="loans_manage.php?tab=<?= admin_e($activeTab) ?>" class="btn btn-secondary">Close Ledger</a>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

