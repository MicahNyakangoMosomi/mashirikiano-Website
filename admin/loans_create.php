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

// Handle Form Submission: Create Loan Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_loan_product'])) {
    try {
        $loanType = trim((string)($_POST['loan_type'] ?? ''));
        $loanDescription = trim((string)($_POST['loan_description'] ?? ''));
        $maxAmount = (float)($_POST['max_amount'] ?? 0);
        $akibaQualification = (float)($_POST['qualification_akiba'] ?? 0.00);
        $ushirikaQualification = (float)($_POST['qualification_ushirika'] ?? 0.00);
        $interestRateInput = (float)($_POST['interest_rate'] ?? 0.00); // e.g. 12 for 12%
        $interestType = trim((string)($_POST['interest_type'] ?? 'REDUCING_BALANCE'));
        $periodMode = trim((string)($_POST['payment_period_mode'] ?? 'predefined'));
        $durationDays = (int)($_POST['duration_days'] ?? 30);

        if ($loanType === '') {
            throw new InvalidArgumentException("Loan Type / Name is required.");
        }
        if ($maxAmount <= 0) {
            throw new InvalidArgumentException("Maximum amount must be greater than zero.");
        }
        if ($akibaQualification < 0 || $ushirikaQualification < 0) {
            throw new InvalidArgumentException("Savings threshold qualifications cannot be negative.");
        }
        if ($interestRateInput < 0) {
            throw new InvalidArgumentException("Interest rate cannot be negative.");
        }
        if (!in_array($interestType, ['REDUCING_BALANCE', 'FLAT_RATE'], true)) {
            throw new InvalidArgumentException("Invalid interest calculation type selected.");
        }

        // Annual rate divided by 100 for decimal representation (e.g. 12% -> 0.1200)
        $loanInterest = round($interestRateInput / 100.0, 4);

        if ($periodMode === 'custom') {
            $paymentPeriod = 'CUSTOM';
        } else {
            if ($durationDays <= 0) {
                throw new InvalidArgumentException("Duration in days must be greater than zero for predefined period.");
            }
            $paymentPeriod = $durationDays . ' Days';
        }

        $stmt = $pdo->prepare("
            INSERT INTO `loan` 
            (`loanType`, `loanDescription`, `maxAmount`, `loanQualification_AkibaAmount`, `loanQualification_UshirikaAmount`, `loanInterest`, `paymentPeriod`, `interestType`)
            VALUES 
            (:type, :desc, :max_amt, :akiba_amt, :ushirika_amt, :interest, :period, :int_type)
        ");
        $stmt->execute([
            ':type' => $loanType,
            ':desc' => $loanDescription ?: null,
            ':max_amt' => $maxAmount,
            ':akiba_amt' => $akibaQualification,
            ':ushirika_amt' => $ushirikaQualification,
            ':interest' => $loanInterest,
            ':period' => $paymentPeriod,
            ':int_type' => $interestType,
        ]);

        $_SESSION['flash_message'] = "Loan product '$loanType' configured successfully!";
        $_SESSION['flash_message_type'] = 'success';
        header("Location: loans_create.php");
        exit;
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

// Fetch existing configured products
$products = $pdo->query("SELECT * FROM `loan` ORDER BY `loanID` DESC")->fetchAll();

admin_header('loans_create', 'Configure Loan Products');
?>
<link rel="stylesheet" href="admin.css">
<style>
  .card-product {
    border-radius: 10px;
    border: 1px solid #e1e7ec;
    box-shadow: 0 4px 12px rgba(11, 59, 102, 0.05);
    background: #fff;
  }
  .form-section-title {
    font-size: 1.15rem;
    font-weight: 700;
    color: #0b3b66;
    margin-bottom: 1.25rem;
    border-bottom: 2px solid #eef2f5;
    padding-bottom: 0.5rem;
  }
</style>

<main class="container-fluid admin-shell py-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h1 class="h3 fw-bold text-dark mb-1">Create Loan Product</h1>
      <p class="text-muted mb-0">Configure lending parameters, borrowing limits, wallet qualification criteria, and accrual models.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="loans_manage.php" class="btn btn-outline-primary fw-semibold">
        <i class="bi bi-list-check me-1"></i> Monitor Loans
      </a>
      <a href="loans_portfolio.php" class="btn btn-outline-success fw-semibold">
        <i class="bi bi-graph-up me-1"></i> Loan Portfolio
      </a>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?= admin_e($messageType) ?> alert-dismissible fade show" role="alert">
      <?= admin_e($message) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- CREATE FORM -->
    <div class="col-lg-5">
      <div class="card card-product p-4">
        <div class="form-section-title">
          <i class="bi bi-plus-circle me-1 text-primary"></i> New Loan Configuration Form
        </div>

        <form method="post" action="loans_create.php" id="loanProductForm">
          <input type="hidden" name="create_loan_product" value="1">

          <div class="mb-3">
            <label for="loan_type" class="form-label fw-bold">Loan Type / Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="loan_type" name="loan_type" placeholder="e.g., Emergency Loan, Development Loan" required>
            <div class="form-text">Official catalog name visible to members.</div>
          </div>

          <div class="mb-3">
            <label for="loan_description" class="form-label fw-bold">Loan Description</label>
            <textarea class="form-control" id="loan_description" name="loan_description" rows="3" placeholder="Terms, purpose, and eligibility summary..."></textarea>
          </div>

          <div class="mb-3">
            <label for="max_amount" class="form-label fw-bold">Maximum Amount (KES) <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text">KES</span>
              <input type="number" step="0.01" min="1" class="form-control" id="max_amount" name="max_amount" placeholder="e.g. 500000.00" required>
            </div>
            <div class="form-text">Upper borrowing limit allowed for this category.</div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-sm-6">
              <label for="qualification_akiba" class="form-label fw-bold small">Min. Akiba Balance</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">KES</span>
                <input type="number" step="0.01" min="0" class="form-control" id="qualification_akiba" name="qualification_akiba" value="0.00" required>
              </div>
              <div class="form-text" style="font-size:0.75rem;">Required in member's Akiba wallet.</div>
            </div>
            <div class="col-sm-6">
              <label for="qualification_ushirika" class="form-label fw-bold small">Min. Ushirika Balance</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">KES</span>
                <input type="number" step="0.01" min="0" class="form-control" id="qualification_ushirika" name="qualification_ushirika" value="0.00" required>
              </div>
              <div class="form-text" style="font-size:0.75rem;">Required in member's Ushirika shares.</div>
            </div>
          </div>

          <div class="mb-3">
            <label for="interest_rate" class="form-label fw-bold">Annual Interest Rate (%) <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="number" step="0.01" min="0" class="form-control" id="interest_rate" name="interest_rate" placeholder="e.g., 12 for 12%" required>
              <span class="input-group-text">% per annum</span>
            </div>
            <div class="form-text">Backend converts percentage to decimal (e.g. 12% &rarr; 0.1200).</div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold d-block">Interest Calculation Type <span class="text-danger">*</span></label>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="interest_type" id="type_reducing" value="REDUCING_BALANCE" checked>
              <label class="form-check-label fw-semibold" for="type_reducing">Reducing Balance</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="interest_type" id="type_flat" value="FLAT_RATE">
              <label class="form-check-label fw-semibold" for="type_flat">Flat Rate</label>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold d-block">Payment Period Mode <span class="text-danger">*</span></label>
            <div class="btn-group w-100" role="group">
              <input type="radio" class="btn-check" name="payment_period_mode" id="mode_predefined" value="predefined" checked autocomplete="off" onchange="togglePeriodFields()">
              <label class="btn btn-outline-primary" for="mode_predefined">Predefined Duration</label>

              <input type="radio" class="btn-check" name="payment_period_mode" id="mode_custom" value="custom" autocomplete="off" onchange="togglePeriodFields()">
              <label class="btn btn-outline-primary" for="mode_custom">Custom (> 120 Days)</label>
            </div>
          </div>

          <div class="mb-4" id="duration_wrapper">
            <label for="duration_days" class="form-label fw-bold">Duration (Days)</label>
            <div class="input-group">
              <input type="number" min="1" step="1" class="form-control" id="duration_days" name="duration_days" value="30" placeholder="e.g., 30, 90, 365">
              <span class="input-group-text">Days</span>
            </div>
            <div class="form-text">Predefined clearance period for members.</div>
          </div>

          <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">
            <i class="bi bi-check2-circle me-1"></i> Save Loan Product
          </button>
        </form>
      </div>
    </div>

    <!-- EXISTING CATALOG TABLE -->
    <div class="col-lg-7">
      <div class="card card-product p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="form-section-title mb-0 border-0 p-0">
            <i class="bi bi-collection me-1 text-primary"></i> Active Loan Products Catalog
          </div>
          <span class="badge bg-primary px-3 py-2"><?= count($products) ?> Products</span>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Product / Category</th>
                <th>Max Limit</th>
                <th>Min Savings (Akiba / Ushirika)</th>
                <th>Rate & Mode</th>
                <th>Period</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($products as $p): ?>
                <tr>
                  <td class="text-muted small">#<?= (int)$p['loanID'] ?></td>
                  <td>
                    <div class="fw-bold text-dark"><?= admin_e($p['loanType']) ?></div>
                    <small class="text-muted text-truncate d-block" style="max-width: 220px;"><?= admin_e($p['loanDescription'] ?: 'No description') ?></small>
                  </td>
                  <td class="fw-bold text-success">
                    KES <?= number_format((float)$p['maxAmount'], 2) ?>
                  </td>
                  <td>
                    <div class="small">
                      <span class="text-warning fw-semibold">Akiba:</span> KES <?= number_format((float)$p['loanQualification_AkibaAmount'], 2) ?>
                    </div>
                    <div class="small">
                      <span class="text-success fw-semibold">Ushirika:</span> KES <?= number_format((float)$p['loanQualification_UshirikaAmount'], 2) ?>
                    </div>
                  </td>
                  <td>
                    <div class="fw-semibold text-primary">
                      <?= number_format((float)$p['loanInterest'] * 100, 2) ?>% p.a.
                    </div>
                    <span class="badge bg-secondary" style="font-size: 0.72rem;">
                      <?= admin_e($p['interestType']) ?>
                    </span>
                  </td>
                  <td>
                    <span class="badge <?= $p['paymentPeriod'] === 'CUSTOM' ? 'bg-info text-dark' : 'bg-light text-dark border' ?>">
                      <?= admin_e($p['paymentPeriod']) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($products)): ?>
                <tr>
                  <td colspan="6" class="text-center text-muted py-4">No loan products configured yet.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
function togglePeriodFields() {
  const isCustom = document.getElementById('mode_custom').checked;
  const durationWrapper = document.getElementById('duration_wrapper');
  const durationInput = document.getElementById('duration_days');

  if (isCustom) {
    durationWrapper.style.display = 'none';
    durationInput.removeAttribute('required');
  } else {
    durationWrapper.style.display = 'block';
    durationInput.setAttribute('required', 'required');
  }
}
document.addEventListener('DOMContentLoaded', togglePeriodFields);
</script>

