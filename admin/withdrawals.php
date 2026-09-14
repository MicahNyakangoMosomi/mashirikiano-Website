<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/MemberService.php';
require_once __DIR__ . '/../classes/SmsService.php';
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

$adminRole = Auth::adminRole();
$isAdmin = $adminRole === 'admin';
$adminUserId = $_SESSION['admin_user_id'] ?? null;

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

// Handle Withdrawal Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_withdrawal'])) {
    try {
        $memberId = trim($_POST['member_id'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $withdrawalDate = trim($_POST['withdrawal_date'] ?? '');

        if (empty($memberId)) {
            throw new Exception("Please select a valid active member.");
        }
        if ($amount <= 0) {
            throw new Exception("Withdrawal amount must be greater than zero.");
        }

        $formattedDate = !empty($withdrawalDate) ? date('Y-m-d H:i:s', strtotime($withdrawalDate)) : date('Y-m-d H:i:s');
        $tranId = 'WD-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 10));
        $descText = !empty($description) ? $description : 'Member Savings Withdrawal';

        $pdo->beginTransaction();

        // Fetch Member & Verify Active Status
        $stmt = $pdo->prepare("SELECT MemberID, NationalID, FirstName, LastName, PrimaryNumber, Status FROM members WHERE MemberID = :mid LIMIT 1");
        $stmt->execute([':mid' => $memberId]);
        $member = $stmt->fetch();

        if (!$member) {
            throw new Exception("Member record not found.");
        }
        if ($member['Status'] !== 'Active') {
            throw new Exception("Only active members are eligible to make withdrawals.");
        }

        // Calculate Current Member Savings (Contributions minus Withdrawals)
        $contribStmt = $pdo->prepare("SELECT COALESCE(SUM(Amount), 0) FROM member_transactions WHERE MemberID = :mid AND TransactionType = 'contribution'");
        $contribStmt->execute([':mid' => $memberId]);
        $totalContributions = (float)$contribStmt->fetchColumn();

        $withdStmt = $pdo->prepare("SELECT COALESCE(SUM(Amount), 0) FROM member_transactions WHERE MemberID = :mid AND TransactionType = 'withdrawal'");
        $withdStmt->execute([':mid' => $memberId]);
        $totalWithdrawals = (float)$withdStmt->fetchColumn();

        $netSavings = max(0.00, $totalContributions - $totalWithdrawals);

        if ($amount > $netSavings) {
            throw new Exception("Requested withdrawal of " . number_format($amount, 2) . " exceeds member's available net savings of " . number_format($netSavings, 2) . ".");
        }

        // 1. Insert into withdrawals table
        $wStmt = $pdo->prepare("INSERT INTO withdrawals (MemberID, Amount, WithdrawalDate, Description, AdminUserID) VALUES (:member_id, :amount, :w_date, :description, :admin_id)");
        $wStmt->execute([
            ':member_id' => $memberId,
            ':amount' => $amount,
            ':w_date' => $formattedDate,
            ':description' => $descText,
            ':admin_id' => $adminUserId
        ]);
        $withdrawalId = $pdo->lastInsertId();

        // 2. Insert into member_transactions ledger
        $ledgerStmt = $pdo->prepare("INSERT INTO member_transactions
            (TranID, MemberID, NationalID, FirstName, LastName, MSISDN, Amount, TransactionType, TransactionCategory, Reference, Description, TranTime)
            VALUES
            (:tran_id, :member_id, :national_id, :first_name, :last_name, :msisdn, :amount, 'withdrawal', 'member_withdrawal', :reference, :description, :tran_time)");
        $ledgerStmt->execute([
            ':tran_id' => $tranId,
            ':member_id' => $memberId,
            ':national_id' => $member['NationalID'],
            ':first_name' => $member['FirstName'],
            ':last_name' => $member['LastName'],
            ':msisdn' => $member['PrimaryNumber'],
            ':amount' => $amount,
            ':reference' => 'WD-' . $withdrawalId,
            ':description' => $descText,
            ':tran_time' => $formattedDate
        ]);

        $pdo->commit();

        $newBalance = max(0.00, $netSavings - $amount);

        // 3. Send SMS notification to member with withdrawal details, date, updated savings, and random marketing message
        $adPromos = [
            "Grow your savings! Deposit monthly to qualify for SACCO loans of up to 3x your savings balance. Visit mashirikianosacco.co.ke",
            "Need quick emergency funds? Apply for our instant Emergency Loan of up to 300,000 repayable in 12 months!",
            "Invest in your child's education! Open a Mashirikiano Junior Savings Account today for high interest growth.",
            "Drive your dream vehicle! Access up to 3,000,000 Vehicle & Asset Financing at 1% per month reducing balance.",
            "Planning to build or purchase land? Apply for our Development & Kujenga Loan. Call 0758500557 for details.",
            "Pay school fees stress-free with our Elimu Education Loan priced at 1% per month on reducing balance.",
            "Earn top returns on your extra funds with a Mashirikiano High-Yield Fixed Deposit Account today!"
        ];
        $randomAd = $adPromos[array_rand($adPromos)];

        $fullName = trim($member['FirstName'] . ' ' . $member['LastName']);
        $displayDate = date('d-M-Y H:i', strtotime($formattedDate));
        $smsMsg = "Dear {$fullName}, a withdrawal of " . number_format($amount, 2) . " was processed from your Mashirikiano SACCO account on {$displayDate}. Your new current savings balance is " . number_format($newBalance, 2) . ".\n\n[SACCO Offer]: {$randomAd}";
        SmsService::sendSms($member['PrimaryNumber'], $smsMsg);

        $_SESSION['flash_message'] = "Withdrawal of " . number_format($amount, 2) . " for " . e($fullName) . " ({$memberId}) processed successfully!";
        $_SESSION['flash_message_type'] = "success";

        header("Location: withdrawals.php");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_message'] = $e->getMessage();
        $_SESSION['flash_message_type'] = 'danger';
        header("Location: withdrawals.php");
        exit;
    }
}

// Filtering & Pagination for Active Members
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;

$whereClause = "WHERE m.Status = 'Active'";
$params = [];

if ($search !== '') {
    $whereClause .= " AND (m.MemberID LIKE :search OR m.FirstName LIKE :search OR m.LastName LIKE :search OR m.NationalID LIKE :search OR CONCAT(m.FirstName, ' ', m.LastName) LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM members m {$whereClause}");
$countStmt->execute($params);
$totalActiveMembers = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalActiveMembers / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$membersStmt = $pdo->prepare("
    SELECT m.*,
        COALESCE(c.TotalContributions, 0) AS TotalContributions,
        COALESCE(w.TotalWithdrawals, 0) AS TotalWithdrawals,
        (COALESCE(c.TotalContributions, 0) - COALESCE(w.TotalWithdrawals, 0)) AS NetSavings
    FROM members m
    LEFT JOIN (
        SELECT MemberID, SUM(Amount) AS TotalContributions
        FROM member_transactions
        WHERE TransactionType = 'contribution' AND MemberID IS NOT NULL
        GROUP BY MemberID
    ) c ON c.MemberID = m.MemberID
    LEFT JOIN (
        SELECT MemberID, SUM(Amount) AS TotalWithdrawals
        FROM member_transactions
        WHERE TransactionType = 'withdrawal' AND MemberID IS NOT NULL
        GROUP BY MemberID
    ) w ON w.MemberID = m.MemberID
    {$whereClause}
    ORDER BY m.FirstName ASC, m.LastName ASC
    LIMIT {$limit} OFFSET {$offset}
");
$membersStmt->execute($params);
$activeMembers = $membersStmt->fetchAll();

// Summary Financial Statistics
$summaryStats = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM members WHERE Status = 'Active') AS ActiveCount,
        (SELECT COALESCE(SUM(Amount), 0) FROM member_transactions WHERE TransactionType = 'contribution') AS TotalContributions,
        (SELECT COALESCE(SUM(Amount), 0) FROM member_transactions WHERE TransactionType = 'withdrawal') AS TotalWithdrawals
")->fetch();

$activeCount = (int)($summaryStats['ActiveCount'] ?? 0);
$allContributions = (float)($summaryStats['TotalContributions'] ?? 0);
$allWithdrawals = (float)($summaryStats['TotalWithdrawals'] ?? 0);
$totalNetSavings = max(0.00, $allContributions - $allWithdrawals);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Withdrawals Management | Mashirikiano SACCO Admin</title>
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
    .modal-header-withdraw { background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%); color: white; }
  </style>
</head>
<body>

  <?php admin_header('withdraw', 'Member Cash Withdrawal Processing'); ?>

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
              <div class="text-muted small text-uppercase fw-bold">Active Members</div>
              <div class="h3 fw-bold mb-0 text-primary"><?= number_format($activeCount) ?></div>
            </div>
            
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-metric shadow-sm bg-white p-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small text-uppercase fw-bold">Total Contributions</div>
              <div class="h3 fw-bold mb-0 text-success"><?= number_format($allContributions, 2) ?></div>
            </div>
            
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-metric shadow-sm bg-white p-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small text-uppercase fw-bold">Total Withdrawals</div>
              <div class="h3 fw-bold mb-0 text-danger"><?= number_format($allWithdrawals, 2) ?></div>
            </div>
            
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-metric shadow-sm bg-white p-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small text-uppercase fw-bold">Net Savings Pool</div>
              <div class="h3 fw-bold mb-0 text-info"><?= number_format($totalNetSavings, 2) ?></div>
            </div>
            
          </div>
        </div>
      </div>
    </div>

    <!-- Active Members Section (Click to Withdraw) -->
    <div class="card shadow-sm border-0 mb-5">
      <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between gap-3 border-bottom">
        <div>
          <h2 class="h5 mb-0 fw-bold text-dark"><i class="bi bi-person-check text-primary me-2"></i>Active SACCO Members (Select to Withdraw)</h2>
          <div class="text-muted small">Click the "Withdraw" button on any active member to trigger a cash withdrawal pop-out.</div>
        </div>
        <form class="d-flex gap-2" method="GET" action="withdrawals.php">
          <div class="input-group input-group-sm">
            <input type="text" name="search" class="form-control" placeholder="Search by name, ID or phone..." value="<?= e($search) ?>">
            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i> Search</button>
            <?php if ($search !== ''): ?>
              <a href="withdrawals.php" class="btn btn-outline-secondary" title="Clear Search"><i class="bi bi-x-circle"></i></a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover table-custom align-middle mb-0">
            <thead>
              <tr>
                <th class="ps-3">Member ID</th>
                <th>Full Name</th>
                <th>National ID</th>
                <th>Phone Number</th>
                <th class="text-end">Total Contributions</th>
                <th class="text-end">Total Withdrawn</th>
                <th class="text-end">Current Net Savings</th>
                <th class="text-center pe-3">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($activeMembers)): ?>
                <tr>
                  <td colspan="8" class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                    No active members found matching criteria.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($activeMembers as $m): ?>
                  <?php
                    $mId = e($m['MemberID']);
                    $mName = e(trim($m['FirstName'] . ' ' . $m['LastName']));
                    $mNatId = e($m['NationalID']);
                    $mPhone = e($m['PrimaryNumber']);
                    $contrib = (float)$m['TotalContributions'];
                    $withd = (float)$m['TotalWithdrawals'];
                    $net = max(0.00, (float)$m['NetSavings']);
                  ?>
                  <tr>
                    <td class="ps-3 fw-semibold text-primary"><?= $mId ?></td>
                    <td class="fw-bold"><?= $mName ?></td>
                    <td><?= $mNatId ?></td>
                    <td><?= $mPhone ?></td>
                    <td class="text-end text-success font-monospace"><?= number_format($contrib, 2) ?></td>
                    <td class="text-end text-danger font-monospace"><?= number_format($withd, 2) ?></td>
                    <td class="text-end fw-bold text-dark font-monospace bg-light"><?= number_format($net, 2) ?></td>
                    <td class="text-center pe-3">
                      <button type="button"
                              class="btn btn-sm btn-danger px-3 shadow-sm btn-withdraw-trigger"
                              data-bs-toggle="modal"
                              data-bs-target="#withdrawModal"
                              data-member-id="<?= $mId ?>"
                              data-member-name="<?= $mName ?>"
                              data-national-id="<?= $mNatId ?>"
                              data-phone="<?= $mPhone ?>"
                              data-savings="<?= number_format($net, 2, '.', '') ?>">
                        <i class="bi bi-arrow-up-right-circle me-1"></i> Withdraw
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Active Members Pagination -->
      <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white py-2 d-flex justify-content-between align-items-center">
          <div class="small text-muted">Showing page <?= $page ?> of <?= $totalPages ?> (Total: <?= $totalActiveMembers ?> members)</div>
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


    <!-- Link Banner to Withdrawal Logs -->
    <div class="card shadow-sm border-0 bg-white p-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
          <h3 class="h6 fw-bold mb-1 text-dark"><i class="bi bi-clock-history text-danger me-2"></i>Looking for past withdrawal records & PDF reports?</h3>
          <p class="text-muted small mb-0">View the complete audit trail, search past transactions, and download official PDF reports in Withdrawal Logs.</p>
        </div>
        <a href="withdrawal_logs.php" class="btn btn-outline-danger fw-semibold px-4 shadow-sm">
          <i class="bi bi-journal-text me-1"></i> Open Withdrawal Logs & PDF Export
        </a>
      </div>
    </div>

  </main>


  <!-- WITHDRAWAL POP-OUT MODAL -->
  <div class="modal fade" id="withdrawModal" tabindex="-1" aria-labelledby="withdrawModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg">
        <div class="modal-header modal-header-withdraw">
          <h5 class="modal-title fw-bold" id="withdrawModalLabel">
            <i class="bi bi-cash-stack me-2"></i>Process Member Withdrawal
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="withdrawals.php" id="withdrawalForm">
          <input type="hidden" name="process_withdrawal" value="1">
          <input type="hidden" name="member_id" id="modalMemberId">

          <div class="modal-body p-4">
            <!-- Member Info Banner -->
            <div class="alert alert-light border shadow-sm rounded-3 mb-4">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small">Member Name:</span>
                <span class="fw-bold text-dark" id="modalMemberName">---</span>
              </div>
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small">Member ID:</span>
                <span class="fw-semibold text-primary" id="modalMemberIdDisplay">---</span>
              </div>
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small">National ID:</span>
                <span class="fw-semibold text-secondary" id="modalNationalId">---</span>
              </div>
              <hr class="my-2">
              <div class="d-flex align-items-center justify-content-between">
                <span class="fw-semibold text-dark">Available Savings:</span>
                <span class="fs-5 fw-bold text-success font-monospace" id="modalSavingsDisplay">0.00</span>
              </div>
            </div>

            <!-- Withdrawal Amount -->
            <div class="mb-3">
              <label for="modalAmount" class="form-label fw-bold">Withdrawal Amount <span class="text-danger">*</span></label>
              <div class="input-group input-group-lg">
                <input type="number"
                       step="0.01"
                       min="1"
                       name="amount"
                       id="modalAmount"
                       class="form-control fw-bold"
                       placeholder="0.00"
                       required>
              </div>
              <div class="form-text text-danger d-none" id="amountError">Amount exceeds available savings balance!</div>
            </div>

            <!-- Withdrawal Date -->
            <div class="mb-3">
              <label for="modalDate" class="form-label fw-semibold">Withdrawal Date & Time</label>
              <input type="datetime-local"
                     name="withdrawal_date"
                     id="modalDate"
                     class="form-control"
                     value="<?= date('Y-m-d\TH:i') ?>">
              <div class="form-text">Defaults to current date and time if unchanged.</div>
            </div>

            <!-- Description / Reason -->
            <div class="mb-3">
              <label for="modalDescription" class="form-label fw-semibold">Reason / Description</label>
              <textarea name="description"
                        id="modalDescription"
                        rows="2"
                        class="form-control"
                        placeholder="e.g. Member savings withdrawal request"></textarea>
            </div>

            <div class="alert alert-warning py-2 small mb-0">
              <i class="bi bi-info-circle me-1"></i> An SMS notification will automatically be dispatched to the member upon confirmation.
            </div>
          </div>

          <div class="modal-footer bg-light">
            <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger px-4 fw-bold" id="submitWithdrawBtn">
              <i class="bi bi-check-circle me-1"></i> Confirm Withdrawal
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const withdrawTriggers = document.querySelectorAll('.btn-withdraw-trigger');
      const modalMemberId = document.getElementById('modalMemberId');
      const modalMemberIdDisplay = document.getElementById('modalMemberIdDisplay');
      const modalMemberName = document.getElementById('modalMemberName');
      const modalNationalId = document.getElementById('modalNationalId');
      const modalSavingsDisplay = document.getElementById('modalSavingsDisplay');
      const modalAmount = document.getElementById('modalAmount');
      const amountError = document.getElementById('amountError');
      const submitWithdrawBtn = document.getElementById('submitWithdrawBtn');

      let currentSavings = 0;

      withdrawTriggers.forEach(button => {
        button.addEventListener('click', function () {
          const mId = this.getAttribute('data-member-id');
          const mName = this.getAttribute('data-member-name');
          const mNatId = this.getAttribute('data-national-id');
          const savings = parseFloat(this.getAttribute('data-savings')) || 0;

          currentSavings = savings;

          modalMemberId.value = mId;
          modalMemberIdDisplay.textContent = mId;
          modalMemberName.textContent = mName;
          modalNationalId.textContent = mNatId;
          modalSavingsDisplay.textContent = savings.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

          modalAmount.value = '';
          modalAmount.max = savings;
          amountError.classList.add('d-none');
          submitWithdrawBtn.disabled = false;
        });
      });

      // Real-time validation for withdrawal amount
      modalAmount.addEventListener('input', function () {
        const val = parseFloat(this.value) || 0;
        if (val <= 0 || val > currentSavings) {
          amountError.classList.remove('d-none');
          if (val > currentSavings) {
            amountError.textContent = 'Amount exceeds available savings balance!';
          } else {
            amountError.textContent = 'Please enter a valid amount greater than 0.';
          }
          submitWithdrawBtn.disabled = true;
        } else {
          amountError.classList.add('d-none');
          submitWithdrawBtn.disabled = false;
        }
      });
    });
  </script>
</body>
</html>
