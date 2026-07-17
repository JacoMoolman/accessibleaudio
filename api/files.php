<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
enforce_rate_limit($config, 'auth', 120, 60);
$user = current_user($config);
$uploadDir = ensure_upload_dir($config);

$records = array_map(static function (array $record): array {
    $record['outputs'] = array_map(static function (array $output) use ($record): array {
        $chapter = (int) ($output['chapter'] ?? 0);
        return [
            'chapter' => $chapter,
            'title' => $output['title'] ?? ('Chapter ' . $chapter),
            'filename' => $output['filename'] ?? ('chapter-' . $chapter . '.mp3'),
            'bytes' => $output['bytes'] ?? 0,
            'download_url' => '/api/download-audio.php?id=' . rawurlencode((string) ($record['id'] ?? '')) . '&chapter=' . $chapter,
        ];
    }, is_array($record['outputs'] ?? null) ? $record['outputs'] : []);
    unset(
        $record['payfast_payment_id'],
        $record['merchant_payment_id'],
        $record['payer_first_name'],
        $record['payer_last_name'],
        $record['payer_email'],
        $record['s3_key'],
        $record['production_error'],
    );
    return $record;
}, list_records($uploadDir, $user['id']));

json_response($records);
