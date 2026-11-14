<?php
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/MemberService.php';
Auth::requireAdmin();
if (Auth::adminRole() !== 'admin' || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(403); exit('Forbidden'); }
Auth::startSession();
try {
    MemberService::deleteMember((string)($_POST['member_id'] ?? ''), (int)($_SESSION['admin_user_id'] ?? 0));
    $_SESSION['flash_message'] = 'Member archived and deleted successfully.';
    $_SESSION['flash_message_type'] = 'success';
} catch (Throwable $error) {
    $_SESSION['flash_message'] = $error->getMessage();
    $_SESSION['flash_message_type'] = 'warning';
}
header('Location: members.php');
exit;
