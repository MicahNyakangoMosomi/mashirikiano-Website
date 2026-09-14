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
$isAdmin = $adminRole === 'admin';

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

// Handle Approve Action
if (isset($_POST['action']) && $_POST['action'] === 'approve') {
    try {
        $appId = (int)($_POST['application_id'] ?? 0);
        
        $stmt = $pdo->prepare("SELECT la.*, m.FirstName, m.PrimaryNumber FROM loan_applications la JOIN members m ON m.MemberID = la.MemberID WHERE la.LoanApplicationID = :id LIMIT 1");
        $stmt->execute([':id' => $appId]);
        $app = $stmt->fetch();

        if (!$app) {
            throw new Exception("Loan application not found.");
        }
        if ($app['Status'] !== 'Pending') {
            throw new Exception("This application is already processed.");
        }

        // Update status
        $updateStmt = $pdo->prepare("UPDATE loan_applications SET Status = 'Approved' WHERE LoanApplicationID = :id");
        $updateStmt->execute([':id' => $appId]);

        $messageType = 'success';
        $message = "Loan application has been approved successfully.";

        // Send SMS
        $smsMsg = "Dear " . $app['FirstName'] . ", your loan application for a " . $app['LoanType'] . " of " . number_format((float)$app['Amount'], 2) . " has been APPROVED. Thank you.";
        SmsService::sendSms($app['PrimaryNumber'], $smsMsg);

    } catch (Throwable $e) {
        $messageType = 'danger';
        $message = $e->getMessage();
    }
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_message_type'] = $messageType;
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header("Location: loan_applications.php" . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

// Handle Reject Action
if (isset($_POST['action']) && $_POST['action'] === 'reject') {
    try {
        $appId = (int)($_POST['application_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if (empty($reason)) {
            throw new Exception("Please specify a reason for rejecting the loan.");
        }

        $stmt = $pdo->prepare("SELECT la.*, m.FirstName, m.PrimaryNumber FROM loan_applications la JOIN members m ON m.MemberID = la.MemberID WHERE la.LoanApplicationID = :id LIMIT 1");
        $stmt->execute([':id' => $appId]);
        $app = $stmt->fetch();

        if (!$app) {
            throw new Exception("Loan application not found.");
        }
        if ($app['Status'] !== 'Pending') {
            throw new Exception("This application is already processed.");
        }

        // Update status
        $updateStmt = $pdo->prepare("UPDATE loan_applications SET Status = 'Not Approved', RejectionReason = :reason WHERE LoanApplicationID = :id");
        $updateStmt->execute([
            ':id' => $appId,
            ':reason' => $reason
        ]);

        $messageType = 'warning';
        $message = "Loan application was rejected.";

        // Send SMS
        $smsMsg = "Dear " . $app['FirstName'] . ", your loan application for a " . $app['LoanType'] . " of " . number_format((float)$app['Amount'], 2) . " was NOT APPROVED. Reason: " . $reason;
        SmsService::sendSms($app['PrimaryNumber'], $smsMsg);

    } catch (Throwable $e) {
        $messageType = 'danger';
        $message = $e->getMessage();
    }
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_message_type'] = $messageType;
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header("Location: loan_applications.php" . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

// Filters & Pagination Setup
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$whereClause = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $whereClause .= " AND (m.FirstName LIKE :search OR m.LastName LIKE :search OR m.MemberID LIKE :search OR la.LoanType LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($statusFilter !== '' && in_array($statusFilter, ['Pending', 'Approved', 'Not Approved'])) {
    $whereClause .= " AND la.Status = :status";
    $params[':status'] = $statusFilter;
}

// Count total
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM loan_applications la JOIN members m ON m.MemberID = la.MemberID $whereClause");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $limit));
$page = min(max(1, $page), $totalPages);
$offset = ($page - 1) * $limit;

// Fetch applications
$stmt = $pdo->prepare(
    "SELECT la.*, m.FirstName, m.LastName, m.PrimaryNumber
     FROM loan_applications la
     JOIN members m ON m.MemberID = la.MemberID
     $whereClause
     ORDER BY la.CreatedAt DESC
     LIMIT $limit OFFSET $offset"
);
$stmt->execute($params);
$applications = $stmt->fetchAll();

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Loan Applications | Mashirikiano SACCO Admin</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <style>
    .member-id { font-weight: 700; color: #0b3b66; }
    .actions-cell button { border: 0; font-weight: 600; padding: 4px 12px; border-radius: 4px; }
    .remarks-link { color: #0b5ed7; font-weight: 700; text-decoration: none; }
    .remarks-link:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <?php admin_header('loan_applications', 'Loan applications and approvals'); ?>

  <main class="container-fluid admin-shell py-4">
    <?php if ($message): ?>
      <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show" role="alert">
        <?= e($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Loan Stats Summary -->
    <div class="row g-3 mb-4">
      <?php
      $stats = $pdo->query(
          "SELECT 
              COUNT(*) AS TotalApps,
              SUM(CASE WHEN Status = 'Pending' THEN 1 ELSE 0 END) AS PendingApps,
              SUM(CASE WHEN Status = 'Approved' THEN 1 ELSE 0 END) AS ApprovedApps,
              SUM(CASE WHEN Status = 'Not Approved' THEN 1 ELSE 0 END) AS RejectedApps
           FROM loan_applications"
      )->fetch();
      ?>
      <div class="col-md-3">
        <div class="card metric h-100"><div class="card-body"><div class="text-muted small">Total Requests</div><div class="h3 fw-bold mb-0"><?= (int)$stats['TotalApps'] ?></div></div></div>
      </div>
      <div class="col-md-3">
        <div class="card metric h-100 bg-warning-subtle"><div class="card-body"><div class="text-muted small">Pending Approvals</div><div class="h3 fw-bold mb-0 text-warning-emphasis"><?= (int)$stats['PendingApps'] ?></div></div></div>
      </div>
      <div class="col-md-3">
        <div class="card metric h-100 bg-success-subtle"><div class="card-body"><div class="text-muted small">Approved Loans</div><div class="h3 fw-bold mb-0 text-success-emphasis"><?= (int)$stats['ApprovedApps'] ?></div></div></div>
      </div>
      <div class="col-md-3">
        <div class="card metric h-100 bg-danger-subtle"><div class="card-body"><div class="text-muted small">Not Approved</div><div class="h3 fw-bold mb-0 text-danger-emphasis"><?= (int)$stats['RejectedApps'] ?></div></div></div>
      </div>
    </div>

    <!-- Loan Applications Ledger Panel -->
    <section class="card panel mb-4">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
          <h2 class="h5 fw-bold mb-0">Member Loan Applications</h2>
          <form method="get" class="row g-2 flex-grow-1 justify-content-end" style="max-width: 800px;">
            <div class="col-md-5">
              <input class="form-control" name="search" value="<?= e($search) ?>" placeholder="Search by name, ID or loan type">
            </div>
            <div class="col-md-3">
              <select class="form-select" name="status">
                <option value="">All Statuses</option>
                <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                <option value="Approved" <?= $statusFilter === 'Approved' ? 'selected' : '' ?>>Approved</option>
                <option value="Not Approved" <?= $statusFilter === 'Not Approved' ? 'selected' : '' ?>>Not Approved</option>
              </select>
            </div>
            <div class="col-md-2">
              <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
            <div class="col-md-2">
              <a href="loan_applications.php" class="btn btn-outline-secondary w-100">Clear</a>
            </div>
          </form>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <div class="text-muted small">Showing <?= count($applications) ?> of <?= $totalRecords ?> request(s)</div>
        </div>

        <div class="table-responsive">
          <table class="table align-middle table-hover">
            <thead>
              <tr>
                <th>MemberID</th>
                <th>Member Name</th>
                <th>Phone</th>
                <th>Loan Type</th>
                <th>Requested Amount</th>
                <th>Return Date</th>
                <th>Applied Date</th>
                <th>Status</th>
                <th>Rejection Remarks</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($applications as $app): ?>
                <tr>
                  <td class="member-id"><?= e($app['MemberID']) ?></td>
                  <td><?= e($app['FirstName'] . ' ' . $app['LastName']) ?></td>
                  <td><?= e($app['PrimaryNumber']) ?></td>
                  <td class="fw-bold"><?= e($app['LoanType']) ?></td>
                  <td class="fw-bold text-primary"><?= number_format((float)$app['Amount'], 2) ?></td>
                  <td><?= e($app['ReturnDate']) ?></td>
                  <td><?= e($app['CreatedAt']) ?></td>
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
                        data-bs-target="#remarksModal"
                        data-remarks="<?= e($app['RejectionReason']) ?>"
                      >View remarks</a>
                    <?php else: ?>
                      <span class="text-muted small">-</span>
                    <?php endif; ?>
                  </td>
                  <td class="actions-cell text-end">
                    <?php if ($app['Status'] === 'Pending'): ?>
                      <!-- Approve Action Form -->
                      <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to APPROVE this loan application?');">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="application_id" value="<?= (int)$app['LoanApplicationID'] ?>">
                        <button type="submit" class="btn btn-link text-success fw-bold p-0 me-3 text-decoration-none">Approve</button>
                      </form>
                      
                      <!-- Reject Trigger -->
                      <button 
                        type="button" 
                        class="btn btn-link text-danger fw-bold p-0 text-decoration-none" 
                        data-bs-toggle="modal" 
                        data-bs-target="#rejectModal" 
                        data-app-id="<?= (int)$app['LoanApplicationID'] ?>"
                        data-app-member="<?= e($app['FirstName'] . ' ' . $app['LastName']) ?>"
                        data-app-type="<?= e($app['LoanType']) ?>"
                      >
                        Reject
                      </button>
                    <?php else: ?>
                      <span class="text-muted small fw-bold">Processed</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$applications): ?>
                <tr><td colspan="10" class="text-muted text-center py-3">No loan applications found matching the criteria.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
          <nav aria-label="Loan pages" class="mt-4">
            <ul class="pagination pagination-sm justify-content-center mb-0">
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&page=<?= max(1, $page - 1) ?>">Previous</a>
              </li>
              <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                  <a class="page-link" href="?search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&page=<?= $p ?>"><?= $p ?></a>
                </li>
              <?php endfor; ?>
              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&page=<?= min($totalPages, $page + 1) ?>">Next</a>
              </li>
            </ul>
          </nav>
        <?php endif; ?>

      </div>
    </section>
  </main>

  <!-- Rejection Modal -->
  <div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content border-0 shadow-lg">
        <form method="post">
          <input type="hidden" name="action" value="reject">
          <input type="hidden" id="rejectAppId" name="application_id">
          <div class="modal-header bg-danger text-white border-0">
            <h5 class="modal-title fw-bold" id="rejectModalLabel">Reject Loan Application</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4">
            <div class="mb-3">
              <div class="small text-muted mb-1">Applicant</div>
              <div class="fw-bold fs-6 text-dark" id="rejectMemberName"></div>
            </div>
            <div class="mb-3">
              <div class="small text-muted mb-1">Loan Type</div>
              <div class="fw-bold fs-6 text-dark" id="rejectLoanType"></div>
            </div>
            <div class="mb-3">
              <label for="rejectReason" class="form-label fw-bold">Reason for Rejection</label>
              <textarea class="form-control" id="rejectReason" name="reason" rows="3" placeholder="Enter reason for not approving this loan..." required></textarea>
            </div>
            <div class="alert alert-warning small py-2 mb-0">
              <i class="bi bi-exclamation-triangle me-1"></i> Submitting will mark this loan as <strong>Not Approved</strong> and text the reason to the member.
            </div>
          </div>
          <div class="modal-footer border-0 p-3">
            <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger px-4">Confirm Rejection</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="remarksModal" tabindex="-1" aria-labelledby="remarksModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
      <div class="modal-content border-0 shadow-lg">
        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="remarksModalLabel">Application Remarks</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-0 text-muted" id="remarksModalText"></p>
        </div>
      </div>
    </div>
  </div>

  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    const rejectModal = document.getElementById('rejectModal');
    if (rejectModal) {
      rejectModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const appId = button.getAttribute('data-app-id');
        const memberName = button.getAttribute('data-app-member');
        const loanType = button.getAttribute('data-app-type');

        rejectModal.querySelector('#rejectAppId').value = appId;
        rejectModal.querySelector('#rejectMemberName').textContent = memberName;
        rejectModal.querySelector('#rejectLoanType').textContent = loanType;
      });
    }

    const remarksModal = document.getElementById('remarksModal');
    if (remarksModal) {
      remarksModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        remarksModal.querySelector('#remarksModalText').textContent = button.getAttribute('data-remarks') || 'No remarks provided.';
      });
    }
  </script>
</body>
</html>
