<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
enforce_rate_limit($config, 'auth', 120, 60);
$user = current_user($config);
require_admin($config, $user);
$uploadDir = ensure_upload_dir($config);
$records = array_map(static function (array $record): array {
    return [
        'id' => $record['id'] ?? '',
        'filename' => $record['filename'] ?? '',
        'status' => $record['status'] ?? '',
        'created_at' => $record['created_at'] ?? null,
        'paid_at' => $record['paid_at'] ?? null,
        'user_email' => $record['user_email'] ?? '',
        'payer_first_name' => $record['payer_first_name'] ?? '',
        'payer_last_name' => $record['payer_last_name'] ?? '',
        'payer_email' => $record['payer_email'] ?? '',
        'payment_amount_zar' => $record['payment_amount_zar'] ?? '',
        'payfast_payment_id' => $record['payfast_payment_id'] ?? '',
        'storage_key' => $record['s3_key'] ?? '',
        'word_count' => $record['word_count'] ?? 0,
        'narrator_voice' => $record['narrator_voice'] ?? '',
        'also_wav' => (bool) ($record['also_wav'] ?? false),
        'translate' => (bool) ($record['translate'] ?? false),
        'make_video' => (bool) ($record['make_video'] ?? false),
        'download_url' => '/api/admin-download.php?id=' . rawurlencode((string) ($record['id'] ?? '')),
    ];
}, list_paid_records($uploadDir));

json_response($records);
