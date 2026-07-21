<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
reject_large_request(4096);
enforce_rate_limit($config, 'auth', 120, 60);
$user = current_user($config);
$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
$event = is_array($payload) ? trim((string) ($payload['event'] ?? '')) : '';
$allowed = ['user.login', 'user.logout', 'admin.login', 'admin.logout'];
if (!in_array($event, $allowed, true)) {
    json_error('Unsupported audit event', 400);
}
if (str_starts_with($event, 'admin.')) {
    require_admin($config, $user);
}
audit_event($config, $event, 'success', [], ['source' => 'browser']);
json_response(['ok' => true]);
