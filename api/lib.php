<?php

const BOOK_COST_PER_WORD_CENTS = 0.5;
const OPTION_COSTS_CENTS = [
    'also_wav' => 2500,
    'translate' => 5000,
    'make_video' => 10000,
];
const PAYFAST_SIGNATURE_FIELD_ORDER = [
    'merchant_id',
    'merchant_key',
    'return_url',
    'cancel_url',
    'notify_url',
    'name_first',
    'name_last',
    'email_address',
    'cell_number',
    'm_payment_id',
    'amount',
    'item_name',
    'item_description',
    'custom_int1',
    'custom_int2',
    'custom_int3',
    'custom_int4',
    'custom_int5',
    'custom_str1',
    'custom_str2',
    'custom_str3',
    'custom_str4',
    'custom_str5',
    'email_confirmation',
    'confirmation_address',
    'payment_method',
];

function hostinger_config(): array
{
    $public = __DIR__ . '/config.public.php';
    $local = __DIR__ . '/config.local.php';
    $publicConfig = file_exists($public) ? require $public : [];
    $fileConfig = file_exists($local) ? require $local : [];
    if (!is_array($publicConfig)) {
        $publicConfig = [];
    }
    if (!is_array($fileConfig)) {
        $fileConfig = [];
    }
    $fileConfig = array_merge($publicConfig, $fileConfig);

    return [
        'supabase_url' => config_value($fileConfig, 'SUPABASE_URL', ''),
        'supabase_anon_key' => config_value($fileConfig, 'SUPABASE_ANON_KEY', ''),
        'turnstile_site_key' => config_value($fileConfig, 'TURNSTILE_SITE_KEY', null),
        'max_upload_bytes' => (int) config_value($fileConfig, 'MAX_UPLOAD_BYTES', 10 * 1024 * 1024),
        'upload_dir' => config_value($fileConfig, 'UPLOAD_DIR', dirname(__DIR__) . '/private_uploads'),
        'payfast_merchant_id' => config_value($fileConfig, 'PAYFAST_MERCHANT_ID', null),
        'payfast_merchant_key' => config_value($fileConfig, 'PAYFAST_MERCHANT_KEY', null),
        'payfast_passphrase' => config_value($fileConfig, 'PAYFAST_PASSPHRASE', null),
        'payfast_sandbox' => filter_var(config_value($fileConfig, 'PAYFAST_SANDBOX', true), FILTER_VALIDATE_BOOLEAN),
        'payfast_return_url' => config_value($fileConfig, 'PAYFAST_RETURN_URL', null),
        'payfast_cancel_url' => config_value($fileConfig, 'PAYFAST_CANCEL_URL', null),
        'payfast_notify_url' => config_value($fileConfig, 'PAYFAST_NOTIFY_URL', null),
        'enable_test_login' => filter_var(config_value($fileConfig, 'ENABLE_TEST_LOGIN', false), FILTER_VALIDATE_BOOLEAN),
        'test_login_email' => config_value($fileConfig, 'TEST_LOGIN_EMAIL', ''),
        'test_login_password' => config_value($fileConfig, 'TEST_LOGIN_PASSWORD', ''),
        'test_login_user_id' => config_value($fileConfig, 'TEST_LOGIN_USER_ID', '00000000-0000-4000-8000-000000000006'),
    ];
}

function config_value(array $fileConfig, string $name, mixed $default): mixed
{
    if (array_key_exists($name, $fileConfig)) {
        return $fileConfig[$name];
    }
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function json_response(mixed $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status): never
{
    json_response(['detail' => $message], $status);
}

function require_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? $_SERVER['Authorization']
        ?? '';
    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        json_error('Missing bearer token', 401);
    }
    return trim($matches[1]);
}

function current_user(array $config): array
{
    $token = require_bearer_token();
    if (str_starts_with($token, 'test-') && $config['enable_test_login']) {
        return [
            'id' => $config['test_login_user_id'],
            'email' => $config['test_login_email'] ?: 'test@accessibleaudio.local',
            'token' => $token,
        ];
    }

    if (!$config['supabase_url'] || !$config['supabase_anon_key']) {
        json_error('Supabase auth is not configured', 500);
    }

    $url = rtrim($config['supabase_url'], '/') . '/auth/v1/user';
    $response = http_request('GET', $url, [
        'apikey: ' . $config['supabase_anon_key'],
        'Authorization: Bearer ' . $token,
    ]);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        json_error('Invalid Supabase session', 401);
    }
    $data = json_decode($response['body'], true);
    if (!is_array($data) || empty($data['id'])) {
        json_error('Invalid Supabase user response', 401);
    }
    return [
        'id' => $data['id'],
        'email' => $data['email'] ?? '',
        'token' => $token,
    ];
}

function http_request(string $method, string $url, array $headers, ?string $body = null): array
{
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 20);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $responseBody = curl_exec($curl);
    if ($responseBody === false) {
        $error = curl_error($curl);
        curl_close($curl);
        json_error('HTTP request failed: ' . $error, 502);
    }
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return ['status' => $status, 'body' => $responseBody];
}

function ensure_upload_dir(array $config): string
{
    $dir = $config['upload_dir'];
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        json_error('Could not create upload directory', 500);
    }
    $htaccess = rtrim($dir, '/\\') . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
    return $dir;
}

function validate_upload(array $file, int $maxBytes): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_error('Upload failed', 400);
    }
    $name = $file['name'] ?? '';
    if (!str_ends_with(strtolower($name), '.txt')) {
        json_error('Only .txt files are accepted', 400);
    }
    if (($file['size'] ?? 0) <= 0) {
        json_error('Uploaded .txt file is empty', 400);
    }
    if (($file['size'] ?? 0) > $maxBytes) {
        json_error('Uploaded file is larger than ' . $maxBytes . ' bytes', 400);
    }
    $content = file_get_contents($file['tmp_name']);
    if ($content === false || !mb_check_encoding($content, 'UTF-8')) {
        json_error('Uploaded .txt file must contain readable plain text', 400);
    }
    return $content;
}

function safe_filename(string $filename): string
{
    $name = basename(trim($filename));
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    $name = trim($name ?: '', '._');
    return $name ?: 'upload.txt';
}

function uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function bool_value(string $name): bool
{
    return filter_var($_POST[$name] ?? false, FILTER_VALIDATE_BOOLEAN);
}

function parse_csv(string $value): array
{
    return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($item) => $item !== ''));
}

function parse_json_array(string $value): array
{
    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        return array_values(array_filter(array_map('strval', $decoded), fn ($item) => trim($item) !== ''));
    }
    return parse_csv(trim($value, '[]'));
}

function build_options_text(array $record, array $options): string
{
    $lines = [
        'Accessible Audio upload options',
        'upload_id: ' . $record['id'],
        'user_id: ' . $record['user_id'],
        'filename: ' . $record['filename'],
        'book_storage: hostinger-local',
        'book_path: ' . $record['s3_key'],
        'narrator_voice: ' . ($options['narrator_voice'] ?: 'not selected'),
        'output_format: mp3',
        'also_wav: ' . ($options['also_wav'] ? 'true' : 'false'),
        'source_language: ' . ($options['source_language'] ?: 'not detected'),
        'detected_chapter_count: ' . count($options['chapter_titles']),
    ];
    foreach ($options['chapter_titles'] as $index => $title) {
        $lines[] = 'chapter_' . ($index + 1) . '_title: ' . $title;
    }
    $lines[] = 'translate: ' . ($options['translate'] ? 'true' : 'false');
    $lines[] = 'translation_languages: ' . ($options['translation_languages'] ? implode(', ', $options['translation_languages']) : 'none');
    if ($options['translate']) {
        foreach ($options['translation_languages'] as $language) {
            $voice = $options['translation_voices'][$language] ?? ($language . ' voice');
            $lines[] = 'translation_voice_' . $language . ': ' . $voice;
        }
    }
    $lines[] = 'make_video: ' . ($options['make_video'] ? 'true' : 'false');
    $lines[] = $options['make_video'] ? 'video_plan: one image per chapter' : 'video_plan: none';
    return implode("\n", $lines) . "\n";
}

function count_words(string $text): int
{
    preg_match_all('/[A-Za-z\x{00C0}-\x{00FF}]+/u', strtolower($text), $matches);
    return count($matches[0]);
}

function format_zar_cents(float|int $cents): string
{
    return 'R ' . number_format(((float) $cents) / 100, 2, '.', '');
}

function total_cost_cents(int $wordCount, bool $alsoWav, bool $translate, bool $makeVideo): float
{
    $total = $wordCount * BOOK_COST_PER_WORD_CENTS;
    if ($alsoWav) {
        $total += OPTION_COSTS_CENTS['also_wav'];
    }
    if ($translate) {
        $total += OPTION_COSTS_CENTS['translate'];
    }
    if ($makeVideo) {
        $total += OPTION_COSTS_CENTS['make_video'];
    }
    return $total;
}

function build_payfast_checkout(array $config, array $user, array $record, int $wordCount, array $options): ?array
{
    if (!$config['payfast_merchant_id'] || !$config['payfast_merchant_key'] || !$config['payfast_passphrase']) {
        return null;
    }
    $baseUrl = request_base_url();
    $amountCents = total_cost_cents($wordCount, $options['also_wav'], $options['translate'], $options['make_video']);
    $fields = [
        'merchant_id' => $config['payfast_merchant_id'],
        'merchant_key' => $config['payfast_merchant_key'],
        'return_url' => $config['payfast_return_url'] ?: $baseUrl . '/submit/?payment=success',
        'cancel_url' => $config['payfast_cancel_url'] ?: $baseUrl . '/submit/?payment=cancelled',
        'notify_url' => $config['payfast_notify_url'] ?: $baseUrl . '/api/payfast-notify.php',
        'email_address' => $user['email'],
        'm_payment_id' => 'AA-' . $record['id'],
        'amount' => number_format($amountCents / 100, 2, '.', ''),
        'item_name' => substr($record['filename'] . ' audiobook', 0, 100),
        'item_description' => substr('Accessible Audio production for ' . $record['filename'] . ': ' . $wordCount . ' words.', 0, 255),
        'custom_str1' => $record['id'],
        'custom_str2' => $record['user_id'],
    ];
    $fields['signature'] = payfast_signature($fields, $config['payfast_passphrase']);
    $host = $config['payfast_sandbox'] ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
    return [
        'provider' => 'payfast',
        'form_action' => 'https://' . $host . '/eng/process',
        'amount_zar' => format_zar_cents($amountCents),
        'book_name' => $record['filename'],
        'fields' => $fields,
    ];
}

function payfast_signature(array $fields, string $passphrase): string
{
    $parts = [];
    foreach (PAYFAST_SIGNATURE_FIELD_ORDER as $key) {
        if (isset($fields[$key]) && $fields[$key] !== '') {
            $parts[] = $key . '=' . urlencode(trim((string) $fields[$key]));
        }
    }
    $parts[] = 'passphrase=' . urlencode(trim($passphrase));
    return md5(implode('&', $parts));
}

function request_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'accessibleaudio.co.za');
}

function append_record(string $uploadDir, array $record): void
{
    $index = rtrim($uploadDir, '/\\') . '/uploads.jsonl';
    $handle = fopen($index, 'ab');
    if (!$handle) {
        json_error('Could not open upload index', 500);
    }
    flock($handle, LOCK_EX);
    fwrite($handle, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n");
    flock($handle, LOCK_UN);
    fclose($handle);
}

function list_records(string $uploadDir, string $userId): array
{
    $index = rtrim($uploadDir, '/\\') . '/uploads.jsonl';
    if (!file_exists($index)) {
        return [];
    }
    $records = [];
    $lines = file($index, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $record = json_decode($line, true);
        if (is_array($record) && ($record['user_id'] ?? '') === $userId) {
            $records[] = $record;
        }
    }
    usort($records, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    return $records;
}
