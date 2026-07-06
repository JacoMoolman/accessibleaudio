<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim($payload['email'] ?? ''));
$password = $payload['password'] ?? '';

if (
    !$config['enable_test_login']
    || !$config['test_login_email']
    || !$config['test_login_password']
    || $email !== strtolower($config['test_login_email'])
    || $password !== $config['test_login_password']
) {
    json_error('Test login is not enabled', 404);
}

json_response([
    'access_token' => 'test-' . bin2hex(random_bytes(16)),
    'token_type' => 'bearer',
    'user' => [
        'id' => $config['test_login_user_id'],
        'email' => $config['test_login_email'],
    ],
]);
