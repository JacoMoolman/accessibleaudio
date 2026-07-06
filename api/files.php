<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
$user = current_user($config);
$uploadDir = ensure_upload_dir($config);

json_response(list_records($uploadDir, $user['id']));
