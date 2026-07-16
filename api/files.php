<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
enforce_rate_limit($config, 'auth', 120, 60);
$user = current_user($config);
$uploadDir = ensure_upload_dir($config);

json_response(list_records($uploadDir, $user['id']));
