<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
json_response([
    'supabaseUrl' => $config['supabase_url'],
    'supabaseAnonKey' => $config['supabase_anon_key'],
    'turnstileSiteKey' => $config['turnstile_site_key'],
]);
