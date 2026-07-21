<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
reject_large_request(8192);
enforce_rate_limit($config, 'auth', 120, 60);
$user = current_user($config);
require_admin($config, $user);

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
$uploadId = is_array($payload) ? trim((string) ($payload['upload_id'] ?? '')) : '';
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uploadId)) {
    json_error('Choose a valid upload to delete', 400);
}

$uploadDir = ensure_upload_dir($config);
$record = find_upload_record_any($uploadDir, $uploadId);
if ($record === null) {
    json_error('Upload not found', 404);
}
if (($record['status'] ?? '') === 'processing') {
    json_error('Wait for production to finish before deleting this book', 409);
}

$ownerId = trim((string) ($record['user_id'] ?? ''));
if ($ownerId === '') {
    json_error('Upload owner is missing', 409);
}

$deleted = delete_upload_record($uploadDir, $ownerId, $uploadId, (string) $user['id'], 'admin');
if ($deleted === null) {
    json_error('Upload not found', 404);
}
audit_event($config, 'upload.deleted', 'success', [], [
    'upload_id' => $uploadId,
    'filename' => (string) ($deleted['filename'] ?? ''),
    'owner_id' => $ownerId,
    'source' => 'admin',
]);

json_response([
    'ok' => true,
    'id' => $uploadId,
    'filename' => (string) ($deleted['filename'] ?? ''),
    'deleted_audio_files' => count(is_array($deleted['outputs'] ?? null) ? $deleted['outputs'] : []),
]);
