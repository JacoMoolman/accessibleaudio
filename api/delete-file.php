<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
reject_large_request(8192);
enforce_rate_limit($config, 'auth', 120, 60);
$user = current_user($config);
$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
$uploadId = is_array($payload) ? trim((string) ($payload['upload_id'] ?? '')) : '';
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uploadId)) {
    json_error('Choose a valid upload to delete', 400);
}

$uploadDir = ensure_upload_dir($config);
$record = find_upload_record($uploadDir, $user['id'], $uploadId);
if ($record !== null && ($record['status'] ?? '') === 'processing') {
    json_error('Wait for production to finish before deleting this book', 409);
}
$deleted = delete_upload_record($uploadDir, $user['id'], $uploadId, $user['id'], 'user');
if ($deleted === null) {
    json_error('Upload not found', 404);
}
audit_event($config, 'upload.deleted', 'success', [], [
    'upload_id' => $uploadId,
    'filename' => (string) ($deleted['filename'] ?? ''),
    'source' => 'user',
]);

json_response([
    'ok' => true,
    'id' => $uploadId,
]);
