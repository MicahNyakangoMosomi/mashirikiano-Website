<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Database.php';

Auth::requireAdmin();

$documents = [
    'id' => ['NationalIDDocument', 'nationalID'],
    'kra' => ['KRADocument', 'KRA'],
    'photo' => ['PassportPhoto', 'passportPhoto'],
    'form' => ['MembershipForm', 'membership_form'],
];
$type = (string)($_GET['type'] ?? '');
$memberId = trim((string)($_GET['id'] ?? ''));

if (!isset($documents[$type]) || $memberId === '') {
    http_response_code(400);
    exit('Invalid document request.');
}

[$column, $folder] = $documents[$type];
$stmt = Database::connection()->prepare("SELECT {$column} FROM members WHERE MemberID = :member_id LIMIT 1");
$stmt->execute([':member_id' => $memberId]);
$relativePath = (string)($stmt->fetchColumn() ?: '');
$expectedPrefix = 'member_documents/' . $folder . '/';

if (!str_starts_with($relativePath, $expectedPrefix)) {
    http_response_code(404);
    exit('Document is not recorded for this member.');
}

$storageRoot = realpath(dirname(__DIR__) . '/member_documents');
$filePath = realpath(dirname(__DIR__) . '/' . $relativePath);
if ($storageRoot === false || $filePath === false || !str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
    http_response_code(404);
    exit('Document file is missing from member_documents on the server.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
$allowedMimes = $type === 'photo' ? ['image/jpeg', 'image/png', 'image/webp'] : ['application/pdf'];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(415);
    exit('Unsupported document type.');
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
readfile($filePath);
