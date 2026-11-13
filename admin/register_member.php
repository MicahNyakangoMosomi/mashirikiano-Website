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
    .required-label::after { content: " *"; color: #dc3545; }
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
          <fieldset class="col-12 border rounded p-3"><legend class="float-none w-auto px-2 fs-6 fw-semibold">Personal Info</legend><div class="row g-3">
          <div class="col-md-3">
            <label class="form-label required-label" for="first_name">FirstName</label>
            <input class="form-control" id="first_name" name="first_name" required>
          </div>
          <div class="col-md-3">
            <label class="form-label required-label" for="last_name">LastName</label>
            <input class="form-control" id="last_name" name="last_name" required>
          </div>
          <div class="col-md-3">
            <label class="form-label required-label" for="phone">PrimaryNumber</label>
            <input class="form-control" id="phone" name="phone" required>
          </div>
          <div class="col-md-3">
            <label class="form-label required-label" for="national_id">NationalID</label>
            <input class="form-control" id="national_id" name="national_id" required>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="email">Email <span class="text-muted">(optional)</span></label>
            <input class="form-control" id="email" name="email" type="email">
          </div>
          <?php foreach (['DateOfBirth'=>'Date of Birth','Gender'=>'Gender','Nationality'=>'Nationality','MaritalStatus'=>'Marital Status','PostalAddress'=>'Postal Address','PhysicalAddress'=>'Physical Address'] as $field => $label): ?>
            <div class="col-md-4"><label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label><?php if ($field === 'Gender'): ?><select class="form-select" id="<?= e($field) ?>" name="<?= e($field) ?>"><option value="">Select gender</option><option value="Male">Male</option><option value="Female">Female</option><option value="Other">Other</option></select><?php elseif ($field === 'MaritalStatus'): ?><select class="form-select" id="<?= e($field) ?>" name="<?= e($field) ?>"><option value="">Select marital status</option><option value="Single">Single</option><option value="Married">Married</option><option value="Divorced">Divorced</option><option value="Widowed">Widowed</option><option value="Separated">Separated</option></select><?php else: ?><input class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>" <?= str_contains($field,'Date') ? 'type="date"' : '' ?>><?php endif; ?></div>
          <?php endforeach; ?>
          </div></fieldset>
          <fieldset class="col-12 border rounded p-3"><legend class="float-none w-auto px-2 fs-6 fw-semibold">Occupation &amp; Income</legend><div class="row g-3">
          <?php foreach (['Profession'=>'Profession','Industry'=>'Industry','EmployerName'=>'Employer','StaffPayrollNumber'=>'Staff/Payroll Number','EmploymentPosition'=>'Employment Position','GrossMonthlyIncome'=>'Gross Monthly Income','BusinessDetails'=>'Business Details','JoiningDate'=>'Joining Date'] as $field => $label): ?>
            <div class="col-md-4"><label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label><input class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= $field === 'JoiningDate' ? e(date('Y-m-d')) : '' ?>" <?= $field === 'JoiningDate' ? 'type="date" readonly' : (in_array($field,['GrossMonthlyIncome'],true) ? 'type="number" step="0.01"' : '') ?>></div>
          <?php endforeach; ?>
          </div></fieldset>
          <fieldset class="col-12 border rounded p-3"><legend class="float-none w-auto px-2 fs-6 fw-semibold">Next of Kin</legend><div class="row g-3">
          <?php foreach (['NomineeName'=>'Full Name','NomineeNationalID'=>'National ID','NomineeDateOfBirth'=>'Date of Birth','NomineeRelationship'=>'Relationship','NomineePhone'=>'Phone','NomineeAllocation'=>'Allocation (%)'] as $field => $label): ?>
            <div class="col-md-4"><label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label><input class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>" <?= str_contains($field,'Date') ? 'type="date"' : (str_contains($field,'Allocation') ? 'type="number" step="0.01"' : '') ?>></div>
          <?php endforeach; ?>
          </div></fieldset>
          <fieldset class="col-12 border rounded p-3"><legend class="float-none w-auto px-2 fs-6 fw-semibold">File Uploads</legend><div class="row g-3">
          <?php foreach (['national_id_file'=>'National ID/Passport/Alien ID PDF','kra_file'=>'KRA certificate PDF','passport_photo_file'=>'Passport photo image','membership_form_file'=>'Signed membership form PDF'] as $field => $label): ?>
            <div class="col-md-6"><label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label><input class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>" type="file" accept="<?= $field === 'passport_photo_file' ? 'image/jpeg,image/png,image/webp' : 'application/pdf' ?>"></div>
          <?php endforeach; ?>
          </div></fieldset>
          <div class="col-md-6">
            <label class="form-label required-label" for="deposit_paid">Deposit Paid?</label>
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
