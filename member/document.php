<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Database.php';

$member = Auth::requireMember();
$documents = [
    'id' => ['NationalIDDocument', 'nationalID'],
    'kra' => ['KRADocument', 'KRA'],
    'photo' => ['PassportPhoto', 'passportPhoto'],
    'form' => ['MembershipForm', 'membership_form'],
];
$type = (string)($_GET['type'] ?? '');
if (!isset($documents[$type])) {
    http_response_code(400);
    exit('Invalid document request.');
}

[$column, $folder] = $documents[$type];
$stmt = Database::connection()->prepare("SELECT {$column} FROM members WHERE MemberID = :member_id LIMIT 1");
$stmt->execute([':member_id' => $member['MemberID']]);
$relativePath = (string)($stmt->fetchColumn() ?: '');
$expectedPrefix = 'member_documents/' . $folder . '/';
if (!str_starts_with($relativePath, $expectedPrefix)) {
    http_response_code(404);
    exit('Document is not recorded for this member.');
}

$basePath = dirname(__DIR__);
$storageRoot = realpath($basePath . '/member_documents');
$filePath = realpath($basePath . '/' . $relativePath);
if ($storageRoot === false || $filePath === false || !str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
    http_response_code(404);
    exit('Document file is missing from the server.');
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
header('Cache-Control: private, no-store');
readfile($filePath);
