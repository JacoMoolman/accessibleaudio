<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
$user = current_user($config);
require_admin($config, $user);
$uploadId = trim((string) ($_GET['id'] ?? ''));
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uploadId)) {
    json_error('Invalid upload', 400);
}
$uploadDir = ensure_upload_dir($config);
$record = find_upload_record_any($uploadDir, $uploadId);
if ($record === null || ($record['status'] ?? '') !== 'paid') {
    json_error('Paid upload not found', 404);
}
$filename = safe_filename((string) ($record['filename'] ?? 'upload.txt'));
$path = rtrim($uploadDir, '/\\')
    . '/users/' . hash('sha256', (string) ($record['user_id'] ?? ''))
    . '/uploads/' . $uploadId . '/' . $filename;
$root = realpath($uploadDir);
$realPath = realpath($path);
if (!$root || !$realPath || !str_starts_with($realPath, $root . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
    json_error('Book file not found', 404);
}

header('Content-Type: text/plain; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . addcslashes($filename, "\"\\") . '"');
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($realPath);
