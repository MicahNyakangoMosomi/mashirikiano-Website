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
// 1. Handle Form Submission: Create Loan Product
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_loan_product'])) {
    try {
        $loanType        = trim((string)($_POST['loan_type'] ?? ''));
        $loanDescription = trim((string)($_POST['loan_description'] ?? ''));
        $maxAmount       = (float)($_POST['max_amount'] ?? 0);
        $akibaQual       = (float)($_POST['qualification_akiba'] ?? 0.00);
        $ushirikaQual    = (float)($_POST['qualification_ushirika'] ?? 0.00);
        $interestRate    = (float)($_POST['interest_rate'] ?? 0.00);
        $interestType    = trim((string)($_POST['interest_type'] ?? 'REDUCING_BALANCE'));
        $periodMode      = trim((string)($_POST['payment_period_mode'] ?? 'predefined'));
        $durationDays    = (int)($_POST['duration_days'] ?? 30);

        if ($loanType === '')
            throw new InvalidArgumentException("Loan Type / Name is required.");
        if ($maxAmount <= 0)
            throw new InvalidArgumentException("Maximum amount must be greater than zero.");
        if ($akibaQual < 0 || $ushirikaQual < 0)
            throw new InvalidArgumentException("Savings threshold qualifications cannot be negative.");
        if ($interestRate < 0)
            throw new InvalidArgumentException("Interest rate cannot be negative.");
        if (!in_array($interestType, ['REDUCING_BALANCE', 'FLAT_RATE'], true))
            throw new InvalidArgumentException("Invalid interest calculation type selected.");

        $loanInterest = round($interestRate / 100.0, 4);

        if ($periodMode === 'custom') {
            $paymentPeriod = 'CUSTOM';
        } else {
            if ($durationDays <= 0)
                throw new InvalidArgumentException("Duration in days must be greater than zero.");
            $paymentPeriod = $durationDays . ' Days';
        }

        $stmt = $pdo->prepare("
            INSERT INTO `loan`
            (`loanType`, `loanDescription`, `maxAmount`, `loanQualification_AkibaAmount`,
             `loanQualification_UshirikaAmount`, `loanInterest`, `paymentPeriod`, `interestType`)
            VALUES (:type, :desc, :max_amt, :akiba_amt, :ushirika_amt, :interest, :period, :int_type)
        ");
        $stmt->execute([
            ':type'        => $loanType,
            ':desc'        => $loanDescription ?: null,
            ':max_amt'     => $maxAmount,
            ':akiba_amt'   => $akibaQual,
            ':ushirika_amt'=> $ushirikaQual,
            ':interest'    => $loanInterest,
            ':period'      => $paymentPeriod,
            ':int_type'    => $interestType,
        ]);

        $_SESSION['flash_message']      = "Loan product '$loanType' configured successfully!";
        $_SESSION['flash_message_type'] = 'success';
        header("Location: loans_create");
        exit;
    } catch (Throwable $e) {
        $message     = $e->getMessage();
        $messageType = 'danger';
    }
}

// -------------------------------------------------------------
// 2. Handle Form Submission: Update Loan Product
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_loan_product'])) {
    try {
        $loanId          = (int)($_POST['loan_id'] ?? 0);
        $loanType        = trim((string)($_POST['loan_type'] ?? ''));
        $loanDescription = trim((string)($_POST['loan_description'] ?? ''));
        $maxAmount       = (float)($_POST['max_amount'] ?? 0);
        $akibaQual       = (float)($_POST['qualification_akiba'] ?? 0.00);
        $ushirikaQual    = (float)($_POST['qualification_ushirika'] ?? 0.00);
        $interestRate    = (float)($_POST['interest_rate'] ?? 0.00);
        $interestType    = trim((string)($_POST['interest_type'] ?? 'REDUCING_BALANCE'));
        $periodMode      = trim((string)($_POST['payment_period_mode'] ?? 'predefined'));
        $durationDays    = (int)($_POST['duration_days'] ?? 30);

        if ($loanId <= 0)
            throw new InvalidArgumentException("Invalid loan product ID.");
        if ($loanType === '')
            throw new InvalidArgumentException("Loan Type / Name is required.");
        if ($maxAmount <= 0)
            throw new InvalidArgumentException("Maximum amount must be greater than zero.");
        if ($akibaQual < 0 || $ushirikaQual < 0)
            throw new InvalidArgumentException("Savings threshold qualifications cannot be negative.");
        if ($interestRate < 0)
            throw new InvalidArgumentException("Interest rate cannot be negative.");
        if (!in_array($interestType, ['REDUCING_BALANCE', 'FLAT_RATE'], true))
            throw new InvalidArgumentException("Invalid interest calculation type selected.");

        $loanInterest = round($interestRate / 100.0, 4);

        if ($periodMode === 'custom') {
            $paymentPeriod = 'CUSTOM';
        } else {
            if ($durationDays <= 0)
                throw new InvalidArgumentException("Duration in days must be greater than zero.");
            $paymentPeriod = $durationDays . ' Days';
        }

        $stmt = $pdo->prepare("
            UPDATE `loan`
            SET `loanType` = :type,
                `loanDescription` = :desc,
                `maxAmount` = :max_amt,
                `loanQualification_AkibaAmount` = :akiba_amt,
                `loanQualification_UshirikaAmount` = :ushirika_amt,
                `loanInterest` = :interest,
                `paymentPeriod` = :period,
                `interestType` = :int_type,
                `updatedAt` = NOW()
            WHERE `loanID` = :id
        ");
        $stmt->execute([
            ':type'        => $loanType,
            ':desc'        => $loanDescription ?: null,
            ':max_amt'     => $maxAmount,
            ':akiba_amt'   => $akibaQual,
            ':ushirika_amt'=> $ushirikaQual,
            ':interest'    => $loanInterest,
            ':period'      => $paymentPeriod,
            ':int_type'    => $interestType,
            ':id'          => $loanId,
        ]);

        $_SESSION['flash_message']      = "Loan product '$loanType' (ID #$loanId) updated successfully!";
        $_SESSION['flash_message_type'] = 'success';
        header("Location: loans_create");
        exit;
    } catch (Throwable $e) {
        $message     = $e->getMessage();
        $messageType = 'danger';
    }
}

// -------------------------------------------------------------
// 3. Handle Form Submission: Delete Loan Product
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_loan_product'])) {
    try {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        if ($loanId <= 0)
            throw new InvalidArgumentException("Invalid loan product ID.");

        // Check if there are applications or active loans referencing this loanID
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM loan_application WHERE loanID = :id");
        $checkStmt->execute([':id' => $loanId]);
        $linkedApps = (int)$checkStmt->fetchColumn();

        if ($linkedApps > 0) {
            throw new RuntimeException("Cannot delete loan product #$loanId because it is referenced by {$linkedApps} member application(s). You can edit its terms instead.");
        }

        $delStmt = $pdo->prepare("DELETE FROM `loan` WHERE `loanID` = :id");
        $delStmt->execute([':id' => $loanId]);

        $_SESSION['flash_message']      = "Loan product #$loanId removed successfully from the database!";
        $_SESSION['flash_message_type'] = 'success';
        header("Location: loans_create");
        exit;
    } catch (Throwable $e) {
        $message     = $e->getMessage();
        $messageType = 'danger';
    }
}

// Fetch existing configured products directly from database
$products = $pdo->query("SELECT * FROM `loan` ORDER BY `loanID` DESC")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Configure Loan Products | Mashirikiano SACCO Admin</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <style>
    body { background-color: #f4f6f9; font-family: system-ui, -apple-system, sans-serif; }
    .card-metric { border: none; border-radius: 10px; transition: transform 0.2s; }
    .card-metric:hover { transform: translateY(-2px); }
    .table-custom th { background-color: #f8f9fa; font-weight: 600; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .table-custom td { vertical-align: middle; }
    .badge-soft-primary { background: #e0ecff; color: #0d6efd; font-weight: 600; }
    .badge-soft-success { background: #d1e7dd; color: #0f5132; font-weight: 600; }
  </style>
</head>
<body>

  <?php admin_header('loans_create', 'Configure Loan Products'); ?>

  <main class="container-fluid admin-shell py-4">

    <!-- Page Title Row -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h3 fw-bold text-dark mb-1">Loan Products Configuration</h1>
        <p class="text-muted mb-0">Manage lending facilities present in the database, define borrowing limits, savings criteria, and interest models.</p>
      </div>
      <div class="d-flex gap-2">
        <a href="loans_manage" class="btn btn-outline-primary fw-semibold">
          Monitor Loans
        </a>
        <a href="loans_portfolio" class="btn btn-outline-success fw-semibold">
          Loan Portfolio
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

    <div class="row g-4">
      <!-- CREATE FORM -->
      <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white py-3 border-bottom">
            <h2 class="h5 fw-bold text-dark mb-0">
              New Loan Product
            </h2>
          </div>
          <div class="card-body p-4">
            <form method="post" action="loans_create" id="loanProductForm">
              <input type="hidden" name="create_loan_product" value="1">

              <div class="mb-3">
                <label for="loan_type" class="form-label fw-semibold">Loan Type / Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="loan_type" name="loan_type"
                       placeholder="e.g., Emergency Loan, Development Loan" required>
                <div class="form-text">Official facility catalog name visible to members.</div>
              </div>

              <div class="mb-3">
                <label for="loan_description" class="form-label fw-semibold">Description</label>
                <textarea class="form-control" id="loan_description" name="loan_description" rows="3"
                          placeholder="Terms, purpose, and eligibility summary..."></textarea>
              </div>

              <div class="mb-3">
                <label for="max_amount" class="form-label fw-semibold">Maximum Amount (KES) <span class="text-danger">*</span></label>
                <div class="input-group">
                  <span class="input-group-text">KES</span>
                  <input type="number" step="0.01" min="1" class="form-control" id="max_amount"
                         name="max_amount" placeholder="e.g. 500000.00" required>
                </div>
                <div class="form-text">Upper borrowing limit allowed for this category.</div>
              </div>

              <div class="row g-2 mb-3">
                <div class="col-sm-6">
                  <label for="qualification_akiba" class="form-label fw-semibold small">Min. Akiba Balance</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">KES</span>
                    <input type="number" step="0.01" min="0" class="form-control" id="qualification_akiba"
                           name="qualification_akiba" value="0.00" required>
                  </div>
                  <div class="form-text" style="font-size:0.75rem;">Required in member's Akiba wallet.</div>
                </div>
                <div class="col-sm-6">
                  <label for="qualification_ushirika" class="form-label fw-semibold small">Min. Ushirika Balance</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">KES</span>
                    <input type="number" step="0.01" min="0" class="form-control" id="qualification_ushirika"
                           name="qualification_ushirika" value="0.00" required>
                  </div>
                  <div class="form-text" style="font-size:0.75rem;">Required in member's Ushirika shares.</div>
                </div>
              </div>

              <div class="mb-3">
                <label for="interest_rate" class="form-label fw-semibold">Annual Interest Rate (%) <span class="text-danger">*</span></label>
                <div class="input-group">
                  <input type="number" step="0.01" min="0" class="form-control" id="interest_rate"
                         name="interest_rate" placeholder="e.g., 12 for 12%" required>
                  <span class="input-group-text">% per annum</span>
                </div>
                <div class="form-text">Annual rate applied for daily accrual.</div>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold d-block">Interest Calculation Type <span class="text-danger">*</span></label>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="interest_type" id="type_reducing"
                         value="REDUCING_BALANCE" checked>
                  <label class="form-check-label fw-semibold" for="type_reducing">Reducing Balance</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="interest_type" id="type_flat"
                         value="FLAT_RATE">
                  <label class="form-check-label fw-semibold" for="type_flat">Flat Rate</label>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold d-block">Payment Period Mode <span class="text-danger">*</span></label>
                <div class="btn-group w-100" role="group">
                  <input type="radio" class="btn-check" name="payment_period_mode" id="mode_predefined"
                         value="predefined" checked autocomplete="off" onchange="togglePeriodFields()">
                  <label class="btn btn-outline-primary" for="mode_predefined">Predefined Duration</label>

                  <input type="radio" class="btn-check" name="payment_period_mode" id="mode_custom"
                         value="custom" autocomplete="off" onchange="togglePeriodFields()">
                  <label class="btn btn-outline-primary" for="mode_custom">Custom (&gt; 120 Days)</label>
                </div>
              </div>

              <div class="mb-4" id="duration_wrapper">
                <label for="duration_days" class="form-label fw-semibold">Duration (Days)</label>
                <div class="input-group">
                  <input type="number" min="1" step="1" class="form-control" id="duration_days"
                         name="duration_days" value="30" placeholder="e.g., 30, 90, 365">
                  <span class="input-group-text">Days</span>
                </div>
                <div class="form-text">Predefined clearance term for borrowers.</div>
              </div>

              <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">
                Save Loan Product
              </button>
            </form>
          </div>
        </div>
      </div>

      <!-- EXISTING CATALOG TABLE (FROM DATABASE) -->
      <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between border-bottom">
            <h2 class="h5 fw-bold text-dark mb-0">
              Loans Present in Database
            </h2>
            <span class="badge bg-primary px-3 py-2"><?= count($products) ?> Product<?= count($products) === 1 ? '' : 's' ?></span>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover table-custom align-middle mb-0">
                <thead>
                  <tr>
                    <th class="ps-3">ID</th>
                    <th>Product / Terms</th>
                    <th>Max Limit</th>
                    <th>Min Savings</th>
                    <th>Rate &amp; Mode</th>
                    <th class="text-end pe-3">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($products as $p): ?>
                    <?php 
                      $pId = (int)$p['loanID'];
                      $isCustomPeriod = ($p['paymentPeriod'] === 'CUSTOM');
                      $pDays = 30;
                      if (preg_match('/(\d+)/', $p['paymentPeriod'], $m)) {
                          $pDays = (int)$m[1];
                      }
                      $pRatePct = (float)$p['loanInterest'] * 100;
                    ?>
                    <tr>
                      <td class="ps-3 text-muted small fw-bold">#<?= $pId ?></td>
                      <td>
                        <div class="fw-bold text-dark"><?= admin_e($p['loanType']) ?></div>
                        <small class="text-muted text-truncate d-block" style="max-width:200px;">
                          <?= admin_e($p['loanDescription'] ?: 'No description provided') ?>
                        </small>
                        <span class="badge <?= $isCustomPeriod ? 'bg-info text-dark' : 'bg-light text-secondary border' ?> mt-1" style="font-size:0.7rem;">
                          <?= admin_e($p['paymentPeriod']) ?>
                        </span>
                      </td>
                      <td class="fw-bold text-success text-nowrap">
                        KES <?= number_format((float)$p['maxAmount'], 2) ?>
                      </td>
                      <td>
                        <div class="small text-nowrap">
                          <span class="wallet-akiba fw-semibold">Akiba:</span>
                          KES <?= number_format((float)$p['loanQualification_AkibaAmount'], 2) ?>
                        </div>
                        <div class="small text-nowrap">
                          <span class="wallet-ushirika fw-semibold">Ushirika:</span>
                          KES <?= number_format((float)$p['loanQualification_UshirikaAmount'], 2) ?>
                        </div>
                      </td>
                      <td>
                        <div class="fw-semibold text-primary">
                          <?= number_format($pRatePct, 2) ?>% p.a.
                        </div>
                        <span class="badge bg-secondary" style="font-size:0.7rem;">
                          <?= admin_e($p['interestType']) ?>
                        </span>
                      </td>
                      <td class="text-end pe-3 text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-primary me-1 fw-semibold" 
                                data-bs-toggle="modal" data-bs-target="#editLoanModal<?= $pId ?>" 
                                title="Edit Product">
                          Edit
                        </button>
                        <form method="post" action="loans_create" class="d-inline"
                              onsubmit="return confirm('Are you sure you want to permanently delete \'<?= admin_e(addslashes($p['loanType'])) ?>\' (#<?= $pId ?>) from the database?');">
                          <input type="hidden" name="delete_loan_product" value="1">
                          <input type="hidden" name="loan_id" value="<?= $pId ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger fw-semibold" title="Delete Product">
                            Delete
                          </button>
                        </form>
                      </td>
                    </tr>

                    <!-- EDIT MODAL FOR PRODUCT <?= $pId ?> -->
                    <div class="modal fade" id="editLoanModal<?= $pId ?>" tabindex="-1" aria-labelledby="editLoanModalLabel<?= $pId ?>" aria-hidden="true">
                      <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg text-start">
                          <form method="post" action="loans_create">
                            <input type="hidden" name="update_loan_product" value="1">
                            <input type="hidden" name="loan_id" value="<?= $pId ?>">

                            <div class="modal-header bg-primary text-white">
                              <h5 class="modal-title fw-bold" id="editLoanModalLabel<?= $pId ?>">
                                Edit Loan Product #<?= $pId ?>
                              </h5>
                              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>

                            <div class="modal-body p-4">
                              <div class="mb-3">
                                <label class="form-label fw-semibold">Loan Type / Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="loan_type" value="<?= admin_e($p['loanType']) ?>" required>
                              </div>

                              <div class="mb-3">
                                <label class="form-label fw-semibold">Description</label>
                                <textarea class="form-control" name="loan_description" rows="2"><?= admin_e($p['loanDescription'] ?? '') ?></textarea>
                              </div>

                              <div class="mb-3">
                                <label class="form-label fw-semibold">Maximum Amount (KES) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                  <span class="input-group-text">KES</span>
                                  <input type="number" step="0.01" min="1" class="form-control" name="max_amount" value="<?= (float)$p['maxAmount'] ?>" required>
                                </div>
                              </div>

                              <div class="row g-2 mb-3">
                                <div class="col-sm-6">
                                  <label class="form-label fw-semibold small">Min. Akiba (KES)</label>
                                  <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="qualification_akiba" value="<?= (float)$p['loanQualification_AkibaAmount'] ?>" required>
                                </div>
                                <div class="col-sm-6">
                                  <label class="form-label fw-semibold small">Min. Ushirika (KES)</label>
                                  <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="qualification_ushirika" value="<?= (float)$p['loanQualification_UshirikaAmount'] ?>" required>
                                </div>
                              </div>

                              <div class="mb-3">
                                <label class="form-label fw-semibold">Annual Interest Rate (%) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                  <input type="number" step="0.01" min="0" class="form-control" name="interest_rate" value="<?= round($pRatePct, 2) ?>" required>
                                  <span class="input-group-text">% p.a.</span>
                                </div>
                              </div>

                              <div class="mb-3">
                                <label class="form-label fw-semibold d-block">Interest Calculation Model</label>
                                <div class="form-check form-check-inline">
                                  <input class="form-check-input" type="radio" name="interest_type" id="edit_type_red_<?= $pId ?>" value="REDUCING_BALANCE" <?= $p['interestType'] === 'REDUCING_BALANCE' ? 'checked' : '' ?>>
                                  <label class="form-check-label" for="edit_type_red_<?= $pId ?>">Reducing Balance</label>
                                </div>
                                <div class="form-check form-check-inline">
                                  <input class="form-check-input" type="radio" name="interest_type" id="edit_type_flat_<?= $pId ?>" value="FLAT_RATE" <?= $p['interestType'] === 'FLAT_RATE' ? 'checked' : '' ?>>
                                  <label class="form-check-label" for="edit_type_flat_<?= $pId ?>">Flat Rate</label>
                                </div>
                              </div>

                              <div class="mb-3">
                                <label class="form-label fw-semibold d-block">Payment Period Mode</label>
                                <div class="btn-group w-100" role="group">
                                  <input type="radio" class="btn-check" name="payment_period_mode" id="edit_mode_pre_<?= $pId ?>" value="predefined" <?= !$isCustomPeriod ? 'checked' : '' ?> onchange="toggleEditPeriodFields(<?= $pId ?>)">
                                  <label class="btn btn-outline-primary btn-sm" for="edit_mode_pre_<?= $pId ?>">Predefined Duration</label>

                                  <input type="radio" class="btn-check" name="payment_period_mode" id="edit_mode_cust_<?= $pId ?>" value="custom" <?= $isCustomPeriod ? 'checked' : '' ?> onchange="toggleEditPeriodFields(<?= $pId ?>)">
                                  <label class="btn btn-outline-primary btn-sm" for="edit_mode_cust_<?= $pId ?>">Custom (&gt; 120 Days)</label>
                                </div>
                              </div>

                              <div class="mb-3" id="edit_duration_wrapper_<?= $pId ?>" style="<?= $isCustomPeriod ? 'display:none;' : '' ?>">
                                <label class="form-label fw-semibold">Duration (Days)</label>
                                <div class="input-group">
                                  <input type="number" min="1" step="1" class="form-control" id="edit_duration_days_<?= $pId ?>" name="duration_days" value="<?= $pDays ?>">
                                  <span class="input-group-text">Days</span>
                                </div>
                              </div>
                            </div>

                            <div class="modal-footer bg-light">
                              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                              <button type="submit" class="btn btn-primary fw-bold">Save Changes</button>
                            </div>
                          </form>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>

                  <?php if (empty($products)): ?>
                    <tr>
                      <td colspan="6" class="text-center py-5 text-muted">
                        <h6 class="fw-bold text-dark">No loan products found in database</h6>
                        <p class="small text-muted mb-0">Use the form on the left to configure a new loan facility.</p>
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div><!-- /row -->

  </main>

  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
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

    function toggleEditPeriodFields(id) {
      const custRadio = document.getElementById('edit_mode_cust_' + id);
      const wrapper = document.getElementById('edit_duration_wrapper_' + id);
      const input = document.getElementById('edit_duration_days_' + id);
      if (custRadio && custRadio.checked) {
        wrapper.style.display = 'none';
        if (input) input.removeAttribute('required');
      } else {
        wrapper.style.display = 'block';
        if (input) input.setAttribute('required', 'required');
      }
    }

    document.addEventListener('DOMContentLoaded', togglePeriodFields);
  </script>
</body>
</html>
