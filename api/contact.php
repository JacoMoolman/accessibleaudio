<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && !in_array($origin, ['https://accessibleaudio.co.za', 'https://www.accessibleaudio.co.za'], true)) {
    json_error('Origin not allowed', 403);
}

$config = hostinger_config();
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$value = static fn(string $key): string => trim((string) ($payload[$key] ?? ''));
$name = $value('name');
$email = $value('email');
$organisation = $value('organisation');
$subjectLine = $value('subject');
$message = $value('message');
$honeypot = $value('website');
$captchaToken = $value('captcha_token');
$startedAt = (int) ($payload['started_at'] ?? 0);

if ($honeypot !== '') {
    json_response(['ok' => true]);
}
if (strlen($name) < 2 || strlen($name) > 100) {
    json_error('Enter your name.', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    json_error('Enter a valid reply email address.', 422);
}
if (strlen($organisation) > 120) {
    json_error('Organisation is too long.', 422);
}
if (strlen($subjectLine) < 3 || strlen($subjectLine) > 150) {
    json_error('Enter a subject.', 422);
}
if (strlen($message) < 20 || strlen($message) > 5000) {
    json_error('Enter a message between 20 and 5,000 characters.', 422);
}
if ($startedAt <= 0 || (int) floor(microtime(true) * 1000) - $startedAt < 3000) {
    json_error('Please take a moment to complete the form.', 422);
}
if ($captchaToken === '') {
    json_error('Complete the human check.', 422);
}
if (!$config['recaptcha_secret_key'] || !$config['contact_recipient']) {
    json_error('The protected contact form is not configured.', 503);
}

$verification = verify_recaptcha($config['recaptcha_secret_key'], $captchaToken, $_SERVER['REMOTE_ADDR'] ?? '');
if (empty($verification['success'])) {
    json_error('The human check failed. Complete it again.', 422);
}
$hostname = strtolower((string) ($verification['hostname'] ?? ''));
if ($hostname && !in_array($hostname, ['accessibleaudio.co.za', 'www.accessibleaudio.co.za'], true)) {
    json_error('The human check was issued for another site.', 422);
}

enforce_contact_rate_limit($config['upload_dir'], $_SERVER['REMOTE_ADDR'] ?? 'unknown');

$safeSubject = preg_replace('/[\r\n]+/', ' ', $subjectLine);
$body = implode("\n", [
    'New Accessible Audio website enquiry',
    '',
    'Name: ' . $name,
    'Reply email: ' . $email,
    'Organisation: ' . ($organisation ?: 'Not supplied'),
    'Subject: ' . $safeSubject,
    '',
    $message,
    '',
    'Submitted: ' . gmdate('c'),
    'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
]);
$headers = implode("\r\n", [
    'From: Accessible Audio website <no-reply@accessibleaudio.co.za>',
    'Reply-To: ' . $email,
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: Accessible Audio contact form',
]);

if (!mail($config['contact_recipient'], 'Accessible Audio contact: ' . $safeSubject, $body, $headers)) {
    json_error('Your message could not be delivered. Please try again later.', 502);
}

json_response(['ok' => true]);

function verify_recaptcha(string $secret, string $token, string $remoteIp): array
{
    $fields = ['secret' => $secret, 'response' => $token];
    if ($remoteIp !== '') {
        $fields['remoteip'] = $remoteIp;
    }
    $body = http_build_query($fields);
    if (function_exists('curl_init')) {
        $curl = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($curl);
        curl_close($curl);
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 10,
        ]]);
        $response = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
    }
    $decoded = is_string($response) ? json_decode($response, true) : null;
    return is_array($decoded) ? $decoded : ['success' => false];
}

function enforce_contact_rate_limit(string $uploadDir, string $ip): void
{
    $directory = rtrim($uploadDir, '/\\') . '/.contact-rate-limit';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        json_error('Contact protection is temporarily unavailable.', 503);
    }
    $path = $directory . '/' . hash('sha256', $ip) . '.json';
    $handle = fopen($path, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        json_error('Contact protection is temporarily unavailable.', 503);
    }
    $contents = stream_get_contents($handle);
    $timestamps = json_decode($contents ?: '[]', true);
    if (!is_array($timestamps)) {
        $timestamps = [];
    }
    $cutoff = time() - 3600;
    $timestamps = array_values(array_filter($timestamps, static fn($timestamp): bool => (int) $timestamp >= $cutoff));
    if (count($timestamps) >= 5) {
        flock($handle, LOCK_UN);
        fclose($handle);
        json_error('Too many messages were sent from this connection. Try again later.', 429);
    }
    $timestamps[] = time();
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($timestamps));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}
