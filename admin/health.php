<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/admin_layout.php';

Auth::requireAdmin();

$checks = [];

function admin_health_check(string $name, callable $callback): void
{
    global $checks;

    try {
        $callback();
        $checks[] = ['name' => $name, 'ok' => true, 'message' => 'OK'];
    } catch (Throwable $error) {
        error_log('Admin health check failed: ' . $name . ' - ' . $error->getMessage());
        $checks[] = ['name' => $name, 'ok' => false, 'message' => $error->getMessage()];
    }
}

admin_health_check('Database connection', function () {
    Database::connection()->query('SELECT 1');
});

admin_health_check('members table', function () {
    Database::connection()->query('SELECT MemberID, NationalID, FirstName, LastName, PrimaryNumber, Email, Password, Status, CreatedAt FROM members LIMIT 1');
});

admin_health_check('member_transactions table', function () {
    Database::connection()->query('SELECT TransactionID, TranID, MemberID, AccountNumber, Amount, TransactionType, CreatedAt FROM member_transactions LIMIT 1');
});

admin_health_check('deposits table', function () {
    Database::connection()->query('SELECT DepositID, MemberID, RequiredAmount, PaidAmount, Balance, Status FROM deposits LIMIT 1');
});

admin_health_check('system_settings table', function () {
    Database::connection()->query('SELECT SettingID, DepositAmount, UpdatedAt FROM system_settings LIMIT 1');
});

admin_health_check('admin_users table', function () {
    Database::connection()->query('SELECT AdminUserID, FullName, Email, Password, Role, Status FROM admin_users LIMIT 1');
});

admin_health_check('member_contribution_totals view', function () {
    Database::connection()->query('SELECT MemberID, TotalContributions FROM member_contribution_totals LIMIT 1');
});

$hasFailures = false;
foreach ($checks as $check) {
    $hasFailures = $hasFailures || !$check['ok'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Health Check | Mashirikiano SACCO</title>
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
</head>
<body>
  <?php admin_header('members', 'System health check'); ?>

  <main class="container-fluid admin-shell py-4">
    <section class="card border-0 shadow-sm">
      <div class="card-body p-4">
        <h1 class="h4 fw-bold">Admin Health Check</h1>
        <p class="text-muted">Use this page to see which database part is causing the admin 500 error.</p>

        <?php if ($hasFailures): ?>
          <div class="alert alert-warning">One or more setup checks failed. Run <code>database/financial_workflow_migration.sql</code>.</div>
        <?php else: ?>
          <div class="alert alert-success">All admin database checks passed.</div>
        <?php endif; ?>

        <div class="table-responsive">
          <table class="table align-middle">
            <thead><tr><th>Check</th><th>Status</th><th>Message</th></tr></thead>
            <tbody>
              <?php foreach ($checks as $check): ?>
                <tr>
                  <td><?= htmlspecialchars($check['name'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <span class="badge text-bg-<?= $check['ok'] ? 'success' : 'danger' ?>">
                      <?= $check['ok'] ? 'PASS' : 'FAIL' ?>
                    </span>
                  </td>
                  <td><?= htmlspecialchars($check['message'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <a class="btn btn-primary" href="members.php">Back to Dashboard</a>
      </div>
    </section>
  </main>
</body>
</html>
