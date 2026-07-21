<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
reject_large_request(8192);
enforce_rate_limit($config, 'auth', 120, 60);
$user = current_user($config);
if (!payfast_configured($config)) {
    json_error('Payment checkout is temporarily unavailable. Please try again later.', 503);
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
$uploadId = is_array($payload) ? trim((string) ($payload['upload_id'] ?? '')) : '';
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uploadId)) {
    json_error('Choose a valid upload to pay for', 400);
}

$uploadDir = ensure_upload_dir($config);
$record = find_upload_record($uploadDir, $user['id'], $uploadId);
if ($record === null) {
    json_error('Upload not found', 404);
}

$uploadPath = rtrim($uploadDir, '/\\')
    . '/users/' . hash('sha256', $user['id'])
    . '/uploads/' . $uploadId;
$filename = safe_filename($record['filename'] ?? 'upload.txt');
$bookPath = $uploadPath . '/' . $filename;
$content = file_exists($bookPath) ? file_get_contents($bookPath) : false;
if ($content === false) {
    json_error('The uploaded book file could not be read', 500);
}

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

$options = [
    'narrator_voice' => (string) ($record['narrator_voice'] ?? $savedOptions['narrator_voice'] ?? ''),
    'also_wav' => array_key_exists('also_wav', $record)
        ? (bool) $record['also_wav']
        : (($savedOptions['also_wav'] ?? 'false') === 'true'),
    'translate' => array_key_exists('translate', $record)
        ? (bool) $record['translate']
        : (($savedOptions['translate'] ?? 'false') === 'true'),
    'make_video' => array_key_exists('make_video', $record)
        ? (bool) $record['make_video']
        : (($savedOptions['make_video'] ?? 'false') === 'true'),
];
$wordCount = (int) ($record['word_count'] ?? 0);
if ($wordCount < 1) {
    $wordCount = count_words($content);
}

try {
    $payment = build_payfast_checkout($config, $user, $record, $wordCount, $options);
} catch (InvalidArgumentException $error) {
    json_error($error->getMessage(), 400);
}
audit_event($config, 'payment.checkout_created', 'success', [], [
    'upload_id' => $uploadId,
    'filename' => (string) ($record['filename'] ?? ''),
    'payment_reference' => 'AA-' . $uploadId,
]);

json_response([
    'payment' => $payment,
]);
