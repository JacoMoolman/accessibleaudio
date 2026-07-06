<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$config = hostinger_config();
$user = current_user($config);
$content = validate_upload($_FILES['file'] ?? [], $config['max_upload_bytes']);
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
    'narrator_voice' => trim($_POST['narrator_voice'] ?? ''),
    'also_wav' => bool_value('also_wav'),
    'translate' => bool_value('translate'),
    'translation_languages' => parse_csv($_POST['translation_languages'] ?? ''),
    'translation_voices' => $translationVoices,
    'source_language' => trim($_POST['source_language'] ?? ''),
    'chapter_titles' => parse_json_array($_POST['chapter_titles'] ?? ''),
    'make_video' => bool_value('make_video'),
];

$record = [
    'id' => $uploadId,
    'user_id' => $user['id'],
    'filename' => $filename,
    's3_bucket' => 'hostinger-local',
    's3_key' => $userPath . '/' . $filename,
    'status' => 'uploaded',
    'created_at' => gmdate('c'),
    'processed_at' => null,
    'result_text' => null,
    'result_path' => null,
];

file_put_contents($absoluteDir . '/options.txt', build_options_text($record, $options), LOCK_EX);
append_record($uploadDir, $record);

$wordCount = count_words($content);
$payment = build_payfast_checkout($config, $user, $record, $wordCount, $options);

json_response($record + [
    'payment' => $payment,
    'pricing' => [
        'word_count' => $wordCount,
        'estimated_cost_cents' => total_cost_cents($wordCount, $options['also_wav'], $options['translate'], $options['make_video']),
        'estimated_cost_zar' => format_zar_cents(total_cost_cents($wordCount, $options['also_wav'], $options['translate'], $options['make_video'])),
    ],
], 201);
