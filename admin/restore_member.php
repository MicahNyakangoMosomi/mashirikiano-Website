<?php
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/MemberService.php';
Auth::requireAdmin();
Auth::startSession();
if (Auth::adminRole() !== 'admin' || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(403); exit('Forbidden'); }
try {
    $penaltyRaw = trim((string)($_POST['penalty_amount'] ?? '0'));
    if (!is_numeric($penaltyRaw) || (float)$penaltyRaw < 0) {
        throw new InvalidArgumentException('Enter a valid penalty amount of zero or more.');
    }
    MemberService::restoreMember(
        (int)($_POST['deleted_member_id'] ?? 0),
        (int)($_SESSION['admin_user_id'] ?? 0),
        (float)$penaltyRaw,
        (string)($_POST['penalty_reason'] ?? '')
    );
    $_SESSION['flash_message'] = 'Member restored as active.';
    $_SESSION['flash_message_type'] = 'success';
} catch (Throwable $error) {
    $_SESSION['flash_message'] = $error->getMessage();
    $_SESSION['flash_message_type'] = 'warning';
}
header('Location: members.php?status=Deleted');
exit;
