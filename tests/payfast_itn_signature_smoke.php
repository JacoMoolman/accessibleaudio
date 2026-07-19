<?php
require __DIR__ . '/../api/lib.php';

$payload = [
    'm_payment_id' => 'AA-example',
    'pf_payment_id' => '1234567',
    'payment_status' => 'COMPLETE',
    'item_name' => 'Example book audiobook',
    'signature' => 'provided-by-payfast',
    'field_after_signature' => 'must-not-be-signed',
];
$passphrase = 'test passphrase';
$expectedString = 'm_payment_id=AA-example&pf_payment_id=1234567&payment_status=COMPLETE&item_name=Example+book+audiobook';
$expectedSignature = md5($expectedString . '&passphrase=test+passphrase');

if (payfast_notification_parameter_string($payload) !== $expectedString) {
    throw new RuntimeException('ITN parameter string must stop at the signature field.');
}
if (payfast_notification_signature($payload, $passphrase) !== $expectedSignature) {
    throw new RuntimeException('ITN signature did not match PayFast canonicalization.');
}

echo "PayFast ITN signature smoke test passed.\n";
