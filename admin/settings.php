<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/admin_layout.php';

Auth::requireAdmin();
Auth::startSession();

$pdo = Database::connection();
$adminRole = Auth::adminRole();
$isAdmin = $adminRole === 'admin';
$message = '';
$messageType = 'info';

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_message_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
}

try {
    $pdo->query('SELECT 1 FROM system_settings LIMIT 1');
} catch (Throwable $error) {
    error_log('Admin settings setup error: ' . $error->getMessage());
    $messageType = 'warning';
    $message = 'Database setup is not complete: ' . $error->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $message === '') {
    try {
        if (!$isAdmin) {
            throw new RuntimeException('Only admin users can update system settings.');
        }

        $depositAmount = trim((string)($_POST['deposit_amount'] ?? ''));
        if ($depositAmount === '' || !is_numeric($depositAmount) || (float)$depositAmount < 0) {
            throw new InvalidArgumentException('Enter a valid deposit amount.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (SettingID, DepositAmount)
             VALUES (1, :deposit_amount)
             ON DUPLICATE KEY UPDATE DepositAmount = VALUES(DepositAmount)'
        );
        $stmt->execute([':deposit_amount' => number_format((float)$depositAmount, 2, '.', '')]);

        $_SESSION['flash_message'] = 'Settings updated successfully.';
        $_SESSION['flash_message_type'] = 'success';
    } catch (Throwable $error) {
        $_SESSION['flash_message'] = $error->getMessage();
        $_SESSION['flash_message_type'] = 'warning';
    }

    header('Location: settings.php');
    exit;
}

$settings = ['DepositAmount' => 0.00, 'UpdatedAt' => null];
try {
    $stmt = $pdo->query('SELECT DepositAmount, UpdatedAt FROM system_settings WHERE SettingID = 1 LIMIT 1');
    $settings = $stmt->fetch() ?: $settings;
} catch (Throwable $error) {
    error_log('Admin settings load error: ' . $error->getMessage());
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
  <title>Settings | Mashirikiano SACCO Admin</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <style>
  </style>
</head>
<body>
  <?php admin_header('settings', 'Global financial settings', false); ?>

  <main class="container-fluid admin-shell py-4">
    <?php if ($message): ?>
      <div class="alert alert-<?= e($messageType) ?>" role="alert"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <div class="card metric h-100">
          <div class="card-body">
            <div class="text-muted small">Current Deposit Amount</div>
            <div class="display-6 fw-bold"><?= number_format((float)$settings['DepositAmount'], 2) ?></div>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card metric h-100">
          <div class="card-body">
            <div class="text-muted small">Last Updated</div>
            <div class="h4 fw-bold mb-0"><?= e($settings['UpdatedAt'] ?: 'Not available') ?></div>
          </div>
        </div>
      </div>
    </div>

    <section class="card panel">
      <div class="card-body">
        <h1 class="h4 fw-bold mb-1">System Settings</h1>
        <p class="text-muted mb-4">This amount is copied to new member deposit records during registration.</p>

        <form method="post" class="row g-3" onsubmit="return confirm('Update the global deposit amount for future registrations?');">
          <div class="col-md-6">
            <label class="form-label" for="deposit_amount">DepositAmount</label>
            <div class="input-group">
              <input
                class="form-control"
                id="deposit_amount"
                name="deposit_amount"
                type="number"
                min="0"
                step="0.01"
                value="<?= e(number_format((float)$settings['DepositAmount'], 2, '.', '')) ?>"
                <?= $isAdmin ? '' : 'disabled' ?>
                required
              >
            </div>
          </div>
          <div class="col-12">
            <?php if ($isAdmin): ?>
              <button class="btn btn-primary" type="submit">Save Settings</button>
            <?php else: ?>
              <button class="btn btn-secondary" type="button" disabled>Admin Only</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </section>
  </main>
</body>
</html>
