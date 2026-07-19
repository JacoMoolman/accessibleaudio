<?php
require __DIR__ . '/lib.php';
require __DIR__ . '/smtp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
reject_large_request(65536);
enforce_rate_limit($config, 'payfast-itn', 120, 60);
if (!payfast_configured($config) || empty($config['admin_email'])) {
    json_error('Payment notification is not configured', 503);
}

$payload = [];
foreach ($_POST as $key => $value) {
    if (is_string($key) && !is_array($value)) {
        $payload[$key] = stripslashes((string) $value);
    }
}
payfast_itn_audit($config, 'received', $payload);
if (!$payload || empty($payload['signature'])) {
    payfast_itn_audit($config, 'invalid_notification', $payload);
    json_error('Invalid PayFast notification', 400);
}
if (!hash_equals((string) $config['payfast_merchant_id'], (string) ($payload['merchant_id'] ?? ''))) {
    payfast_itn_audit($config, 'merchant_mismatch', $payload);
    json_error('PayFast merchant does not match', 400);
}
if (
    !payfast_uses_unsigned_shared_sandbox($config)
    && !hash_equals(strtolower(payfast_notification_signature($payload, (string) $config['payfast_passphrase'])), strtolower($payload['signature']))
) {
    payfast_itn_audit($config, 'signature_mismatch', $payload);
    json_error('Invalid PayFast signature', 400);
}
if (!payfast_server_validation($payload, (bool) $config['payfast_sandbox'])) {
    payfast_itn_audit($config, 'server_validation_failed', $payload);
    json_error('PayFast could not validate this notification', 400);
}
if (strtoupper((string) ($payload['payment_status'] ?? '')) !== 'COMPLETE') {
    payfast_itn_audit($config, 'payment_not_complete', $payload);
    json_response(['ok' => true, 'ignored' => 'payment_not_complete']);
}

$uploadId = trim((string) ($payload['custom_str1'] ?? ''));
if ($uploadId === '' && preg_match('/^AA-([0-9a-f-]{36})$/i', (string) ($payload['m_payment_id'] ?? ''), $matches)) {
    $uploadId = $matches[1];
}
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uploadId)) {
    payfast_itn_audit($config, 'invalid_payment_reference', $payload);
    json_error('Invalid payment reference', 400);
}

$uploadDir = ensure_upload_dir($config);
$record = find_upload_record_any($uploadDir, $uploadId);
if ($record === null) {
    payfast_itn_audit($config, 'upload_not_found', $payload);
    json_error('Upload not found', 404);
}
$uploadPath = rtrim($uploadDir, '/\\')
    . '/users/' . hash('sha256', (string) ($record['user_id'] ?? ''))
    . '/uploads/' . $uploadId;
$savedOptions = [];
$optionsPath = $uploadPath . '/options.txt';
if (file_exists($optionsPath)) {
    foreach (file($optionsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $savedOptions[trim($parts[0])] = trim($parts[1]);
        }
    }
}
$wordCount = (int) ($record['word_count'] ?? 0);
if ($wordCount < 1) {
    $bookPath = $uploadPath . '/' . safe_filename((string) ($record['filename'] ?? 'upload.txt'));
    $content = file_exists($bookPath) ? file_get_contents($bookPath) : false;
    if ($content === false) {
        json_error('The uploaded book file could not be read', 500);
    }
    $wordCount = count_words($content);
}
$narratorVoice = (string) ($record['narrator_voice'] ?? $savedOptions['narrator_voice'] ?? '');
$alsoWav = array_key_exists('also_wav', $record) ? (bool) $record['also_wav'] : (($savedOptions['also_wav'] ?? 'false') === 'true');
$translate = array_key_exists('translate', $record) ? (bool) $record['translate'] : (($savedOptions['translate'] ?? 'false') === 'true');
$makeVideo = array_key_exists('make_video', $record) ? (bool) $record['make_video'] : (($savedOptions['make_video'] ?? 'false') === 'true');
$expectedCents = total_cost_cents(
    $wordCount,
    $narratorVoice,
    $alsoWav,
    $translate,
    $makeVideo,
);
$amountGross = (float) ($payload['amount_gross'] ?? -1);
if (abs($amountGross - ($expectedCents / 100)) > 0.01) {
    payfast_itn_audit($config, 'amount_mismatch', $payload);
    json_error('Payment amount does not match the upload', 400);
}

$claimToken = bin2hex(random_bytes(16));
$now = gmdate('c');
$record = update_upload_record($uploadDir, $uploadId, static function (array $record) use ($payload, $amountGross, $claimToken, $now): array {
    if (in_array(($record['status'] ?? ''), ['uploaded', 'paid'], true)) {
        $record['status'] = 'queued';
    }
    $record['paid_at'] = $record['paid_at'] ?? $now;
    $record['payfast_payment_id'] = (string) ($payload['pf_payment_id'] ?? '');
    $record['merchant_payment_id'] = (string) ($payload['m_payment_id'] ?? '');
    $record['payment_amount_zar'] = number_format($amountGross, 2, '.', '');
    $record['payer_first_name'] = (string) ($payload['name_first'] ?? '');
    $record['payer_last_name'] = (string) ($payload['name_last'] ?? '');
    $record['payer_email'] = strtolower(trim((string) ($payload['email_address'] ?? '')));
    if (empty($record['user_email'])) {
        $record['user_email'] = $record['payer_email'];
    }
    $claimedAt = strtotime((string) ($record['admin_notification_claimed_at'] ?? '')) ?: 0;
    if (empty($record['admin_notified_at']) && (!$claimedAt || $claimedAt < time() - 600)) {
        $record['admin_notification_claim'] = $claimToken;
        $record['admin_notification_claimed_at'] = $now;
    }
    return $record;
});
if ($record === null) {
    payfast_itn_audit($config, 'update_failed', $payload);
    json_error('Upload not found', 404);
}
payfast_itn_audit($config, 'queued', $payload);

if (($record['admin_notification_claim'] ?? '') === $claimToken && empty($record['admin_notified_at'])) {
    $replyEmail = (string) ($record['payer_email'] ?: ($record['user_email'] ?? ''));
    $body = implode("\n", [
        'A paid audiobook order has been queued for automatic production.',
        '',
        'Book: ' . ($record['filename'] ?? 'Unknown'),
        'Upload ID: ' . $uploadId,
        'Uploaded by: ' . ($record['user_email'] ?? 'Email unavailable'),
        'Payer: ' . trim(($record['payer_first_name'] ?? '') . ' ' . ($record['payer_last_name'] ?? '')),
        'Payer email: ' . ($record['payer_email'] ?? 'Email unavailable'),
        'Paid: R ' . ($record['payment_amount_zar'] ?? number_format($amountGross, 2, '.', '')),
        'PayFast payment ID: ' . ($record['payfast_payment_id'] ?? ''),
        'Stored as: ' . ($record['s3_key'] ?? ''),
        '',
        'Open the private admin queue to download it:',
        request_base_url($config) . '/admin/',
    ]);
    try {
        aa_send_smtp_email($config, (string) $config['admin_email'], 'Paid audiobook: ' . ($record['filename'] ?? $uploadId), $body, $replyEmail);
        update_upload_record($uploadDir, $uploadId, static function (array $record) use ($claimToken): array {
            if (($record['admin_notification_claim'] ?? '') === $claimToken) {
                $record['admin_notified_at'] = gmdate('c');
                unset($record['admin_notification_claim'], $record['admin_notification_claimed_at']);
            }
            return $record;
        });
    } catch (RuntimeException $error) {
        update_upload_record($uploadDir, $uploadId, static function (array $record) use ($claimToken): array {
            if (($record['admin_notification_claim'] ?? '') === $claimToken) {
                unset($record['admin_notification_claim'], $record['admin_notification_claimed_at']);
            }
            return $record;
        });
        error_log('Accessible Audio admin payment email failed: ' . $error->getMessage());
        json_error('Admin notification could not be delivered', 500);
    }
}

json_response(['ok' => true]);

function payfast_itn_audit(array $config, string $stage, array $payload): void
{
    $entry = [
        'time' => gmdate('c'),
        'stage' => $stage,
        'merchant_id' => (string) ($payload['merchant_id'] ?? ''),
        'payment_status' => (string) ($payload['payment_status'] ?? ''),
        'm_payment_id' => (string) ($payload['m_payment_id'] ?? ''),
        'custom_str1' => (string) ($payload['custom_str1'] ?? ''),
        'amount_gross' => (string) ($payload['amount_gross'] ?? ''),
        'signature_present' => !empty($payload['signature']),
        'signature_length' => strlen((string) ($payload['signature'] ?? '')),
        'field_order' => array_keys($payload),
    ];
    $path = rtrim(ensure_upload_dir($config), '/\\') . '/payfast-itn-audit.jsonl';
    @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

function payfast_server_validation(array $payload, bool $sandbox): bool
{
    $host = $sandbox ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
    $curl = curl_init('https://' . $host . '/eng/query/validate');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => payfast_notification_parameter_string($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return $status === 200 && trim((string) $response) === 'VALID';
}
