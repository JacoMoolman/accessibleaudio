<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
reject_large_request($config['max_upload_bytes'] + 1024 * 1024);
enforce_rate_limit($config, 'auth', 120, 60);
enforce_rate_limit($config, 'upload', 12, 600);
$user = current_user($config);
if (!payfast_configured($config)) {
    json_error('Payment checkout is temporarily unavailable. No book was uploaded. Please try again later.', 503);
}
if (!production_configured($config)) {
    json_error('Audiobook production is temporarily unavailable. No book was uploaded. Please try again later.', 503);
}
$termsVersion = '2026-07-18';
if (!bool_value('terms_accepted') || !hash_equals($termsVersion, trim((string) ($_POST['terms_version'] ?? '')))) {
    json_error('You must agree to the current Terms and Conditions before uploading.', 400);
}
$content = validate_upload($_FILES['file'] ?? [], $config['max_upload_bytes']);
$narratorVoice = trim($_POST['narrator_voice'] ?? '');
$voicePricing = narrator_voice_pricing($narratorVoice);
if ($voicePricing === null) {
    json_error('Choose a valid narrator voice', 400);
}
if (production_voice_config($narratorVoice) === null) {
    json_error('Choose an available narrator voice for automated production', 400);
}
$uploadDir = ensure_upload_dir($config);

$uploadId = uuid_v4();
$filename = safe_filename($_FILES['file']['name'] ?? 'upload.txt');
$userPath = 'users/' . hash('sha256', $user['id']) . '/uploads/' . $uploadId;
$absoluteDir = rtrim($uploadDir, '/\\') . '/' . $userPath;
if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0750, true)) {
    json_error('Could not create upload folder', 500);
}

$bookPath = $absoluteDir . '/' . $filename;
if (file_put_contents($bookPath, $content, LOCK_EX) === false) {
    json_error('Could not save uploaded file', 500);
}

$translationVoices = json_decode($_POST['translation_voices'] ?? '{}', true);
if (!is_array($translationVoices)) {
    $translationVoices = [];
}
$options = [
    'narrator_voice' => $narratorVoice,
    'also_wav' => bool_value('also_wav'),
    'translate' => bool_value('translate'),
    'translation_languages' => parse_csv($_POST['translation_languages'] ?? ''),
    'translation_voices' => $translationVoices,
    'source_language' => trim($_POST['source_language'] ?? ''),
    'chapter_titles' => parse_json_array($_POST['chapter_titles'] ?? ''),
    'make_video' => bool_value('make_video'),
];
$wordCount = count_words($content);

$record = [
    'id' => $uploadId,
    'user_id' => $user['id'],
    'user_email' => strtolower(trim((string) $user['email'])),
    'filename' => $filename,
    's3_bucket' => 'hostinger-local',
    's3_key' => $userPath . '/' . $filename,
    'status' => 'uploaded',
    'created_at' => gmdate('c'),
    'processed_at' => null,
    'result_text' => null,
    'result_path' => null,
    'word_count' => $wordCount,
    'narrator_voice' => $options['narrator_voice'],
    'also_wav' => $options['also_wav'],
    'translate' => $options['translate'],
    'make_video' => $options['make_video'],
    'terms_accepted' => true,
    'terms_version' => $termsVersion,
    'terms_accepted_at' => gmdate('c'),
];

file_put_contents($absoluteDir . '/options.txt', build_options_text($record, $options), LOCK_EX);
append_record($uploadDir, $record);

$payment = build_payfast_checkout($config, $user, $record, $wordCount, $options);
$estimatedCostCents = total_cost_cents($wordCount, $options['narrator_voice'], $options['also_wav'], $options['translate'], $options['make_video']);

json_response($record + [
    'payment' => $payment,
    'pricing' => [
        'word_count' => $wordCount,
        'voice_type' => $voicePricing['type'],
        'cost_per_word_cents' => $voicePricing['cost_per_word_cents'],
        'estimated_cost_cents' => $estimatedCostCents,
        'estimated_cost_zar' => format_zar_cents($estimatedCostCents),
    ],
], 201);
