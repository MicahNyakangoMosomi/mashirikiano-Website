<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/MemberService.php';
require_once __DIR__ . '/admin_layout.php';

Auth::requireAdmin();
Auth::startSession();

$message = '';
$messageType = 'info';

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_message_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
}

try {
    $pdo = Database::connection();
    $pdo->query('SELECT 1 FROM members LIMIT 1');
    $pdo->query('SELECT 1 FROM deposits LIMIT 1');
    $pdo->query('SELECT 1 FROM member_transactions LIMIT 1');
    $pdo->query('SELECT 1 FROM system_settings LIMIT 1');
} catch (Throwable $error) {
    error_log('Register member setup error: ' . $error->getMessage());
    $messageType = 'warning';
    $message = 'Database setup is not complete: ' . $error->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $message === '') {
    try {
        $createdMember = MemberService::create($_POST);
        $_SESSION['flash_message'] = 'Member created successfully. MemberID: ' . $createdMember['MemberID'];
        $_SESSION['flash_message_type'] = 'success';
    } catch (Throwable $error) {
        $_SESSION['flash_message'] = $error->getMessage();
        $_SESSION['flash_message_type'] = 'warning';
    }

    header('Location: register_member.php');
    exit;
}

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
  <title>Register Member | Mashirikiano SACCO Admin</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <style>
  </style>
</head>
<body>
  <?php admin_header('register_member', 'Internal member registration', false); ?>

  <main class="container-fluid admin-shell py-4">
    <?php if ($message): ?>
      <div class="alert alert-<?= e($messageType) ?>" role="alert"><?= e($message) ?></div>
    <?php endif; ?>

    <section class="card panel">
      <div class="card-body">
        <h1 class="h4 fw-bold mb-1">Register Member</h1>
        <p class="text-muted mb-4">Create SACCO members through the internal staff/admin workflow. Passwords are generated automatically.</p>
        <form method="post" enctype="multipart/form-data" class="row g-3" onsubmit="return confirm('Register this member and save the record to the database?');">
          <div class="col-md-3">
            <label class="form-label" for="first_name">FirstName</label>
            <input class="form-control" id="first_name" name="first_name" required>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="last_name">LastName</label>
            <input class="form-control" id="last_name" name="last_name" required>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="phone">PrimaryNumber</label>
            <input class="form-control" id="phone" name="phone" required>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="national_id">NationalID</label>
            <input class="form-control" id="national_id" name="national_id" required>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="email">Email <span class="text-muted">(optional)</span></label>
            <input class="form-control" id="email" name="email" type="email">
          </div>
          <?php foreach (['DateOfBirth'=>'Date of Birth','Gender'=>'Gender','Nationality'=>'Nationality','MaritalStatus'=>'Marital Status','PostalAddress'=>'Postal Address','PhysicalAddress'=>'Physical Address','Profession'=>'Profession','Industry'=>'Industry','EmployerName'=>'Employer','StaffPayrollNumber'=>'Staff/Payroll Number','EmploymentPosition'=>'Employment Position','GrossMonthlyIncome'=>'Gross Monthly Income','BusinessDetails'=>'Business Details','MonthlyContribution'=>'Monthly Contribution','RemittanceMode'=>'Remittance Mode','InitialShareCapital'=>'Initial Share Capital','RegistrationFee'=>'Registration Fee','JoiningDate'=>'Joining Date','Branch'=>'Branch','NomineeName'=>'Nominee Name','NomineeNationalID'=>'Nominee National ID','NomineeDateOfBirth'=>'Nominee Date of Birth','NomineeRelationship'=>'Nominee Relationship','NomineePhone'=>'Nominee Phone','NomineeAllocation'=>'Nominee Allocation (%)'] as $field => $label): ?>
            <div class="col-md-4"><label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label><input class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>" <?= str_contains($field,'Date') ? 'type="date"' : (in_array($field,['GrossMonthlyIncome','MonthlyContribution','InitialShareCapital','RegistrationFee','NomineeAllocation'],true) ? 'type="number" step="0.01"' : '') ?>></div>
          <?php endforeach; ?>
          <?php foreach (['national_id_file'=>'National ID/Passport/Alien ID PDF','kra_file'=>'KRA certificate PDF','passport_photo_file'=>'Passport photo image','membership_form_file'=>'Signed membership form PDF'] as $field => $label): ?>
            <div class="col-md-6"><label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label><input class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>" type="file" accept="<?= $field === 'passport_photo_file' ? 'image/jpeg,image/png,image/webp' : 'application/pdf' ?>"></div>
          <?php endforeach; ?>
          <div class="col-md-6">
            <label class="form-label" for="deposit_paid">Deposit Paid?</label>
            <select class="form-select" id="deposit_paid" name="deposit_paid" required>
              <option value="yes">Yes - activate and send credentials</option>
              <option value="no">No - keep member pending</option>
            </select>
          </div>
          <div class="col-12">
            <button class="btn btn-primary" type="submit">Register Member</button>
          </div>
        </form>
      </div>
    </section>
  </main>
</body>
</html>
