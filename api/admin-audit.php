<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
enforce_rate_limit($config, 'auth', 120, 60);
$user = current_user($config);
require_admin($config, $user);
$limit = max(1, min(500, (int) ($_GET['limit'] ?? 200)));
$event = substr(trim((string) ($_GET['event'] ?? '')), 0, 80);
$query = substr(trim((string) ($_GET['q'] ?? '')), 0, 160);
json_response(list_audit_events($config, $limit, $event, $query));
