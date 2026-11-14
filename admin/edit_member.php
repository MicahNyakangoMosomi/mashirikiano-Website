<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/MemberService.php';
require_once __DIR__ . '/admin_layout.php';

Auth::requireAdmin();
Auth::startSession();

$message = '';
$messageType = 'info';
$adminRole = Auth::adminRole();
$isAdmin = $adminRole === 'admin';

$pdo = Database::connection();

$memberId = $_GET['id'] ?? '';
if (!$memberId) {
    header('Location: members.php');
    exit;
}

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_message_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!empty($_POST['remove_document'])) {
            MemberService::deleteDocument($memberId, (string)$_POST['remove_document']);
            $_SESSION['flash_message'] = 'Member document removed.';
            $_SESSION['flash_message_type'] = 'success';
            header('Location: edit_member.php?id=' . urlencode($memberId));
            exit;
        }
        MemberService::update($memberId, $_POST, [
            'can_change_status' => $isAdmin,
            'can_change_password' => $isAdmin,
        ]);
        $_SESSION['flash_message'] = 'Member updated successfully.';
        $_SESSION['flash_message_type'] = 'success';
    } catch (Throwable $error) {
        $_SESSION['flash_message'] = $error->getMessage();
        $_SESSION['flash_message_type'] = 'warning';
    }

    header('Location: edit_member.php?id=' . urlencode($memberId));
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM members WHERE MemberID = :member_id LIMIT 1');
$stmt->execute([':member_id' => $memberId]);
$member = $stmt->fetch();

if (!$member) {
    echo "Member not found.";
    exit;
}

$kinStmt = $pdo->prepare('SELECT FullName, NationalID, DateOfBirth, Relationship, Phone, Allocation FROM next_of_kin WHERE MemberID = :member_id ORDER BY NextOfKinID');
$kinStmt->execute([':member_id' => $memberId]);
$nextOfKin = $kinStmt->fetchAll(PDO::FETCH_ASSOC);

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
  <title>Edit Member | Mashirikiano SACCO Admin</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <style>
    .required-label::after { content: " *"; color: #dc3545; }
  </style>
</head>
<body>
  <?php admin_header('members', 'Edit member details', false); ?>

  <main class="container-fluid admin-shell py-4">
    <div class="mb-3">
      <a href="members.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Members</a>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?= e($messageType) ?>" role="alert"><?= e($message) ?></div>
    <?php endif; ?>

    <section class="card panel">
      <div class="card-body">
        <h1 class="h4 fw-bold mb-1">Edit <?= e($member['MemberID']) ?></h1>
        <p class="text-muted mb-4">Modify the member's details and save changes to the database.</p>
        <form method="post" enctype="multipart/form-data" class="row g-3" id="editMemberForm" onsubmit="return confirm('Save these member changes to the database?');">
          <input type="hidden" name="remove_document" id="removeDocumentType" value="">
          <fieldset class="col-12 border rounded p-3"><legend class="float-none w-auto px-2 fs-6 fw-semibold">Personal Info</legend><div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">MemberID</label>
            <input class="form-control" value="<?= e($member['MemberID']) ?>" disabled>
          </div>
          <div class="col-md-6">
            <label class="form-label">CreatedAt</label>
            <input class="form-control" value="<?= e($member['CreatedAt']) ?>" disabled>
          </div>
          <div class="col-md-6">
            <label class="form-label required-label">FirstName</label>
            <input class="form-control" name="first_name" value="<?= e($member['FirstName']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label required-label">LastName</label>
            <input class="form-control" name="last_name" value="<?= e($member['LastName']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label required-label">PrimaryNumber</label>
            <input class="form-control" name="phone" value="<?= e($member['PrimaryNumber']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label required-label">NationalID</label>
            <input class="form-control" name="national_id" value="<?= e($member['NationalID']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input class="form-control" name="email" type="email" value="<?= e($member['Email']) ?>">
          </div>
          <?php foreach (['DateOfBirth'=>'Date of Birth','Gender'=>'Gender','Nationality'=>'Nationality','MaritalStatus'=>'Marital Status','PostalAddress'=>'Postal Address','PhysicalAddress'=>'Physical Address'] as $field => $label): ?>
            <div class="col-md-4"><label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label><?php if ($field === 'Gender'): ?><select class="form-select" id="<?= e($field) ?>" name="<?= e($field) ?>"><option value="">Select gender</option><?php foreach (['Male','Female','Other'] as $option): ?><option value="<?= e($option) ?>" <?= ($member[$field] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select><?php elseif ($field === 'MaritalStatus'): ?><select class="form-select" id="<?= e($field) ?>" name="<?= e($field) ?>"><option value="">Select marital status</option><?php foreach (['Single','Married','Divorced','Widowed','Separated'] as $option): ?><option value="<?= e($option) ?>" <?= ($member[$field] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select><?php else: ?><input class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e($member[$field] ?? '') ?>" <?= str_contains($field,'Date') ? 'type="date"' : '' ?>><?php endif; ?></div>
          <?php endforeach; ?>
          </div></fieldset>
          <fieldset class="col-12 border rounded p-3"><legend class="float-none w-auto px-2 fs-6 fw-semibold">Occupation &amp; Income</legend><div class="row g-3">
          <?php foreach (['Profession'=>'Profession','Industry'=>'Industry','EmployerName'=>'Employer','StaffPayrollNumber'=>'Staff/Payroll Number','EmploymentPosition'=>'Employment Position','GrossMonthlyIncome'=>'Gross Monthly Income','BusinessDetails'=>'Business Details','JoiningDate'=>'Joining Date'] as $field => $label): ?>
            <div class="col-md-4"><label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label><input class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= $field === 'JoiningDate' ? e($member[$field] ?: date('Y-m-d')) : e($member[$field] ?? '') ?>" <?= $field === 'JoiningDate' ? 'type="date" readonly' : (str_contains($field,'Date') ? 'type="date"' : ($field === 'GrossMonthlyIncome' ? 'type="number" step="0.01" min="0.01"' : '')) ?>></div>
          <?php endforeach; ?>
          </div></fieldset>
          <fieldset class="col-12 border rounded p-3"><legend class="float-none w-auto px-2 fs-6 fw-semibold">Next of Kin</legend>
            <?php if ($nextOfKin): ?><div class="row g-3 mb-3">
              <?php foreach ($nextOfKin as $index => $kin): ?><div class="col-12 border rounded p-3"><div class="fw-semibold mb-2">Existing next of kin <?= $index + 1 ?></div><div class="row g-3">
                <?php foreach (['FullName'=>'Full Name','NationalID'=>'National ID','DateOfBirth'=>'Date of Birth','Relationship'=>'Relationship','Phone'=>'Phone','Allocation'=>'Allocation (%)'] as $field => $label): ?><div class="col-md-4"><label class="form-label"><?= e($label) ?></label><input class="form-control" value="<?= e($kin[$field] ?? '') ?>" disabled></div><?php endforeach; ?>
              </div></div><?php endforeach; ?>
            </div><?php else: ?><p class="text-muted">No next of kin have been added yet.</p><?php endif; ?>
            <div id="newNextOfKinList" class="row g-3"></div>
            <?php if (count($nextOfKin) < 3): ?><button class="btn btn-outline-primary mt-3" type="button" id="addNextOfKin">Add next of kin</button><?php endif; ?>
            <span class="form-text ms-2">Up to 3 next of kin per member.</span>
          </fieldset>
          <fieldset class="col-12 border rounded p-3"><legend class="float-none w-auto px-2 fs-6 fw-semibold">File Uploads</legend><div class="row g-3">
          <?php foreach ([['national_id_file','NationalIDDocument','id','National ID/Passport/Alien ID PDF','application/pdf'],['kra_file','KRADocument','kra','KRA certificate PDF','application/pdf'],['passport_photo_file','PassportPhoto','photo','Passport photo','image/*'],['membership_form_file','MembershipForm','form','Signed membership form PDF','application/pdf']] as [$field,$column,$type,$label,$accept]): ?>
            <div class="col-md-6"><label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label><?php if (!empty($member[$column])): ?><div class="small mb-1 d-flex gap-3"><button class="btn btn-link btn-sm p-0 document-preview-trigger" type="button" data-document-url="member_document.php?id=<?= urlencode($member['MemberID']) ?>&amp;type=<?= e($type) ?>" data-document-name="<?= e($label) ?>">View current document</button><button class="btn btn-link btn-sm p-0 text-danger remove-document-trigger" type="button" data-document-type="<?= e($type) ?>">Remove file</button></div><?php endif; ?><input class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>" type="file" accept="<?= e($accept) ?>"></div>
          <?php endforeach; ?>
          </div></fieldset>
          
          <?php if ($isAdmin): ?>
            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select class="form-select" name="status">
                <?php foreach (MemberService::STATUSES as $status): ?>
                  <option value="<?= e($status) ?>" <?= $member['Status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">New Password</label>
              <div class="input-group">
                <input class="form-control" id="password" name="password" type="text" autocomplete="new-password" placeholder="Leave blank to keep the current password" readonly>
                <button class="btn btn-outline-primary" type="button" id="generatePassword">Auto Generate Password</button>
                <button class="btn btn-outline-secondary" type="button" id="clearPassword">Clear</button>
              </div>
              <div class="form-text">A password SMS is sent only if this field has a generated password when you save.</div>
            </div>
          <?php else: ?>
            <div class="col-md-6">
              <label class="form-label">Status</label>
              <input class="form-control" value="<?= e($member['Status']) ?>" disabled>
            </div>
          <?php endif; ?>

          <div class="col-12 mt-4">
            <button class="btn btn-primary" type="submit">Save Changes</button>
            <a href="members.php" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </section>
  </main>
  <div class="modal fade" id="documentPreviewModal" tabindex="-1" aria-labelledby="documentPreviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title h5" id="documentPreviewTitle">Member document</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-0" style="height: min(75vh, 800px);">
          <iframe id="documentPreviewFrame" title="Member document preview" style="width: 100%; height: 100%; border: 0;"></iframe>
        </div>
      </div>
    </div>
  </div>
  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    const documentPreviewModal = document.getElementById('documentPreviewModal');
    const documentPreviewFrame = document.getElementById('documentPreviewFrame');
    const documentPreviewTitle = document.getElementById('documentPreviewTitle');

    document.querySelectorAll('.document-preview-trigger').forEach(function (button) {
      button.addEventListener('click', function () {
        documentPreviewFrame.src = button.dataset.documentUrl;
        documentPreviewTitle.textContent = button.dataset.documentName;
        bootstrap.Modal.getOrCreateInstance(documentPreviewModal).show();
      });
    });

    document.querySelectorAll('.remove-document-trigger').forEach(function (button) {
      button.addEventListener('click', function () {
        if (!window.confirm('Remove this file from the server and clear its saved path?')) return;
        document.getElementById('removeDocumentType').value = button.dataset.documentType;
        document.getElementById('editMemberForm').submit();
      });
    });

    documentPreviewModal.addEventListener('hidden.bs.modal', function () {
      documentPreviewFrame.src = 'about:blank';
    });

    const passwordInput = document.getElementById('password');
    const addNextOfKinButton = document.getElementById('addNextOfKin');
    const newNextOfKinList = document.getElementById('newNextOfKinList');
    const existingNextOfKinCount = <?= count($nextOfKin) ?>;
    const nextOfKinFields = [['name','Full Name','text'],['national_id','National ID','text'],['date_of_birth','Date of Birth','date'],['relationship','Relationship','text'],['phone','Phone','tel'],['allocation','Allocation (%)','number']];
    if (addNextOfKinButton && newNextOfKinList) {
      addNextOfKinButton.addEventListener('click', function () {
        const newCount = newNextOfKinList.children.length;
        if (existingNextOfKinCount + newCount >= 3) return;
        const card = document.createElement('div');
        card.className = 'col-12 border rounded p-3';
        card.innerHTML = '<div class="fw-semibold mb-2">New next of kin ' + (existingNextOfKinCount + newCount + 1) + '</div><div class="row g-3">' + nextOfKinFields.map(function (field) {
          const key = field[0], label = field[1], type = field[2];
          return '<div class="col-md-4"><label class="form-label">' + label + '</label><input class="form-control" name="next_of_kin[' + newCount + '][' + key + ']" type="' + type + '" ' + (key === 'allocation' ? 'step="0.01" min="0.01" max="100"' : '') + '></div>';
        }).join('') + '</div>';
        newNextOfKinList.appendChild(card);
        if (existingNextOfKinCount + newNextOfKinList.children.length >= 3) addNextOfKinButton.disabled = true;
      });
    }
    const generatePasswordButton = document.getElementById('generatePassword');
    const clearPasswordButton = document.getElementById('clearPassword');
    const passwordDigits = '0123456789';
    const passwordLetters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    function randomIndex(max) {
      const randomValues = new Uint32Array(1);
      window.crypto.getRandomValues(randomValues);
      return randomValues[0] % max;
    }

    if (passwordInput && generatePasswordButton && clearPasswordButton) {
      generatePasswordButton.addEventListener('click', function () {
        const characters = [];

        for (let index = 0; index < 5; index++) {
          characters.push(passwordDigits[randomIndex(passwordDigits.length)]);
        }

        for (let index = 0; index < 3; index++) {
          characters.push(passwordLetters[randomIndex(passwordLetters.length)]);
        }

        for (let index = characters.length - 1; index > 0; index--) {
          const swapIndex = randomIndex(index + 1);
          [characters[index], characters[swapIndex]] = [characters[swapIndex], characters[index]];
        }

        passwordInput.value = characters.join('');
      });

      clearPasswordButton.addEventListener('click', function () {
        passwordInput.value = '';
      });
    }
  </script>
</body>
</html>
