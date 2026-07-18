<?php
require __DIR__ . '/../api/lib.php';

$base = [
    'payfast_merchant_id' => '10000100',
    'payfast_merchant_key' => '46f0cd694581a',
    'payfast_passphrase' => '',
    'payfast_sandbox' => true,
    'payfast_unsigned_sandbox' => true,
    'payfast_return_url' => 'https://accessibleaudio.co.za/submit/?payment=success',
    'payfast_cancel_url' => 'https://accessibleaudio.co.za/submit/?payment=cancelled',
    'payfast_notify_url' => 'https://accessibleaudio.co.za/api/payfast-notify.php',
    'public_base_url' => 'https://accessibleaudio.co.za',
    'admin_email' => 'merchant@example.com',
];
$user = ['email' => 'sandbox@example.com'];
$record = [
    'id' => '00000000-0000-4000-8000-000000000001',
    'user_id' => '00000000-0000-4000-8000-000000000002',
    'filename' => 'Alice.txt',
];
$options = [
    'narrator_voice' => 'Voice 6',
    'also_wav' => false,
    'translate' => false,
    'make_video' => false,
];

$sandbox = build_payfast_checkout($base, $user, $record, 1000, $options);
if (!$sandbox || isset($sandbox['fields']['signature'])) {
    throw new RuntimeException('Shared sandbox checkout should omit the rejected signature.');
}
if (($sandbox['fields']['email_address'] ?? '') !== 'sandbox@example.com' || ($sandbox['requires_alternate_payer_email'] ?? true)) {
    throw new RuntimeException('Normal customer checkout should retain its payer email.');
}

$merchant = build_payfast_checkout($base, ['email' => 'MERCHANT@example.com'], $record, 1000, $options);
if (!$merchant || isset($merchant['fields']['email_address']) || !($merchant['requires_alternate_payer_email'] ?? false)) {
    throw new RuntimeException('Merchant self-checkout must omit the PayFast payer email and request an alternate address.');
}

$live = $base;
$live['payfast_sandbox'] = false;
$live['payfast_unsigned_sandbox'] = false;
$live['payfast_passphrase'] = 'private-live-passphrase';
$signed = build_payfast_checkout($live, $user, $record, 1000, $options);
if (!$signed || !preg_match('/^[a-f0-9]{32}$/', (string) ($signed['fields']['signature'] ?? ''))) {
    throw new RuntimeException('Live checkout must retain its PayFast signature.');
}
if (($signed['fields']['email_address'] ?? '') !== 'sandbox@example.com') {
    throw new RuntimeException('Live customer checkout should retain its payer email.');
}

echo "PayFast checkout smoke test passed.\n";
