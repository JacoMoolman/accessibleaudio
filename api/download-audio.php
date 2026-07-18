<?php

require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
enforce_rate_limit($config, 'auth', 120, 60);
$user = current_user($config);
$uploadId = trim((string) ($_GET['id'] ?? ''));
$chapterNumber = (int) ($_GET['chapter'] ?? 0);
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uploadId) || $chapterNumber < 1) {
    json_error('Invalid audiobook download', 400);
}
$uploadDir = ensure_upload_dir($config);
$record = find_upload_record_any($uploadDir, $uploadId);
if ($record === null || ($record['status'] ?? '') !== 'completed') {
    json_error('Completed audiobook not found', 404);
}
$isOwner = hash_equals((string) ($record['user_id'] ?? ''), (string) ($user['id'] ?? ''));
$isAdmin = strtolower(trim((string) ($config['admin_email'] ?? ''))) !== ''
    && hash_equals(strtolower(trim((string) $config['admin_email'])), strtolower(trim((string) ($user['email'] ?? ''))));
if (!$isOwner && !$isAdmin) {
    json_error('You do not have access to this audiobook', 403);
}
$output = null;
foreach (($record['outputs'] ?? []) as $candidate) {
    if ((int) ($candidate['chapter'] ?? 0) === $chapterNumber) {
        $output = $candidate;
        break;
    }
}
if ($output === null) {
    json_error('Audiobook chapter not found', 404);
}
$baseDir = rtrim($uploadDir, '/\\') . '/users/' . hash('sha256', (string) $record['user_id']) . '/uploads/' . $uploadId;
$path = $baseDir . '/' . ltrim((string) ($output['path'] ?? ''), '/\\');
$root = realpath($baseDir);
$realPath = realpath($path);
if (!$root || !$realPath || !str_starts_with($realPath, $root . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
    json_error('Audiobook chapter file not found', 404);
}
$filename = safe_filename((string) ($output['filename'] ?? ('chapter-' . $chapterNumber . '.mp3')));
$extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
header('Content-Type: ' . ($extension === 'wav' ? 'audio/wav' : 'audio/mpeg'));
header('Content-Disposition: attachment; filename="' . addcslashes($filename, "\"\\") . '"');
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($realPath);
