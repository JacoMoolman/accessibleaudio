<?php

function aa_send_smtp_email(array $config, string $recipient, string $subject, string $body, ?string $replyEmail = null): void
{
    $host = (string) ($config['contact_smtp_host'] ?? '');
    $port = (int) ($config['contact_smtp_port'] ?? 465);
    $username = (string) ($config['contact_smtp_username'] ?? '');
    $password = (string) ($config['contact_smtp_password'] ?? '');
    if (!$host || !$username || !$password || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Admin email delivery is not configured');
    }
    $transport = $port === 465 ? 'ssl' : 'tcp';
    $socket = @stream_socket_client($transport . '://' . $host . ':' . $port, $errorNumber, $errorMessage, 15, STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) {
        throw new RuntimeException('Could not connect to the configured SMTP server');
    }
    try {
        stream_set_timeout($socket, 15);
        aa_smtp_expect($socket, [220]);
        aa_smtp_command($socket, 'EHLO accessibleaudio.co.za', [250]);
        if ($port === 587) {
            aa_smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not enable SMTP encryption');
            }
            aa_smtp_command($socket, 'EHLO accessibleaudio.co.za', [250]);
        }
        aa_smtp_command($socket, 'AUTH LOGIN', [334]);
        aa_smtp_command($socket, base64_encode($username), [334]);
        aa_smtp_command($socket, base64_encode($password), [235]);
        aa_smtp_command($socket, 'MAIL FROM:<' . $username . '>', [250]);
        aa_smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        aa_smtp_command($socket, 'DATA', [354]);
        $safeSubject = preg_replace('/[\r\n]+/', ' ', $subject);
        if (function_exists('mb_encode_mimeheader')) {
            $safeSubject = mb_encode_mimeheader($safeSubject, 'UTF-8', 'B', "\r\n");
        }
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: Accessible Audio payments <' . $username . '>',
            'To: Accessible Audio admin <' . $recipient . '>',
            'Subject: ' . $safeSubject,
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@accessibleaudio.co.za>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Accessible Audio payment notification',
        ];
        if ($replyEmail && filter_var($replyEmail, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: <' . preg_replace('/[\r\n]+/', '', $replyEmail) . '>';
        }
        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
        $normalizedBody = str_replace("\n", "\r\n", $normalizedBody);
        $data = implode("\r\n", $headers) . "\r\n\r\n" . $normalizedBody;
        $data = preg_replace('/^\./m', '..', $data);
        aa_smtp_write_all($socket, $data . "\r\n.\r\n");
        aa_smtp_expect($socket, [250]);
        aa_smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function aa_smtp_command($socket, string $command, array $expectedCodes): string
{
    aa_smtp_write_all($socket, $command . "\r\n");
    return aa_smtp_expect($socket, $expectedCodes);
}

function aa_smtp_write_all($socket, string $data): void
{
    $offset = 0;
    while ($offset < strlen($data)) {
        $written = fwrite($socket, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Could not write to the SMTP server');
        }
        $offset += $written;
    }
}

function aa_smtp_expect($socket, array $expectedCodes): string
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
        throw new RuntimeException('The SMTP server rejected a delivery step (code ' . $code . ')');
    }
    return $response;
}
