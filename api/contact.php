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
reject_large_request(16384);
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
if (
    !$config['recaptcha_secret_key']
    || !$config['contact_recipient']
    || !$config['contact_smtp_host']
    || !$config['contact_smtp_username']
    || !$config['contact_smtp_password']
) {
    json_error('The protected contact form is not configured.', 503);
}

enforce_rate_limit($config, 'contact', 5, 3600);
$verification = verify_recaptcha($config['recaptcha_secret_key'], $captchaToken, $_SERVER['REMOTE_ADDR'] ?? '');
if (empty($verification['success'])) {
    json_error('The human check failed. Complete it again.', 422);
}
$hostname = strtolower((string) ($verification['hostname'] ?? ''));
if ($hostname && !in_array($hostname, ['accessibleaudio.co.za', 'www.accessibleaudio.co.za'], true)) {
    json_error('The human check was issued for another site.', 422);
}

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
try {
    send_contact_email(
        $config,
        'Accessible Audio contact: ' . $safeSubject,
        $body,
        $email,
    );
} catch (RuntimeException $error) {
    error_log('Accessible Audio contact SMTP failure: ' . $error->getMessage());
    json_error('Your message could not be delivered. Please try again later.', 502);
}
audit_event($config, 'contact.submitted', 'success', ['email' => strtolower($email)], [
    'organisation' => $organisation,
    'subject' => $safeSubject,
    'message_length' => strlen($message),
]);

json_response(['ok' => true]);

function send_contact_email(array $config, string $subject, string $body, string $replyEmail): void
{
    $host = (string) $config['contact_smtp_host'];
    $port = (int) $config['contact_smtp_port'];
    $username = (string) $config['contact_smtp_username'];
    $password = (string) $config['contact_smtp_password'];
    $recipient = (string) $config['contact_recipient'];
    $transport = $port === 465 ? 'ssl' : 'tcp';
    $socket = @stream_socket_client(
        $transport . '://' . $host . ':' . $port,
        $errorNumber,
        $errorMessage,
        15,
        STREAM_CLIENT_CONNECT,
    );
    if (!is_resource($socket)) {
        throw new RuntimeException('Could not connect to the configured SMTP server.');
    }

    try {
        stream_set_timeout($socket, 15);
        smtp_expect($socket, [220]);
        smtp_command($socket, 'EHLO accessibleaudio.co.za', [250]);
        if ($port === 587) {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not enable SMTP encryption.');
            }
            smtp_command($socket, 'EHLO accessibleaudio.co.za', [250]);
        }
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $username . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $safeReplyEmail = preg_replace('/[\r\n]+/', '', $replyEmail);
        $safeSubject = preg_replace('/[\r\n]+/', ' ', $subject);
        if (function_exists('mb_encode_mimeheader')) {
            $safeSubject = mb_encode_mimeheader($safeSubject, 'UTF-8', 'B', "\r\n");
        }
        $messageId = bin2hex(random_bytes(16)) . '@accessibleaudio.co.za';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: Accessible Audio website <' . $username . '>',
            'To: Accessible Audio <' . $recipient . '>',
            'Reply-To: <' . $safeReplyEmail . '>',
            'Subject: ' . $safeSubject,
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Accessible Audio contact form',
        ];
        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
        $normalizedBody = str_replace("\n", "\r\n", $normalizedBody);
        $data = implode("\r\n", $headers) . "\r\n\r\n" . $normalizedBody;
        $data = preg_replace('/^\./m', '..', $data);
        smtp_write_all($socket, $data . "\r\n.\r\n");
        smtp_expect($socket, [250]);
        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function smtp_command($socket, string $command, array $expectedCodes): string
{
    smtp_write_all($socket, $command . "\r\n");
    return smtp_expect($socket, $expectedCodes);
}

function smtp_write_all($socket, string $data): void
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($socket, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Could not write to the SMTP server.');
        }
        $offset += $written;
    }
}

function smtp_expect($socket, array $expectedCodes): string
{
    $response = '';
    $code = 0;
    while (($line = fgets($socket, 4096)) !== false) {
        $response .= $line;
        if (preg_match('/^(\d{3})([ -])/', $line, $matches)) {
            $code = (int) $matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        }
    }
    $meta = stream_get_meta_data($socket);
    if (!in_array($code, $expectedCodes, true) || !empty($meta['timed_out'])) {
        throw new RuntimeException('The SMTP server rejected a delivery step (code ' . $code . ').');
    }
    return $response;
}

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
