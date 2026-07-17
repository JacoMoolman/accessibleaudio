<?php

require dirname(__DIR__) . '/api/production.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$text = "CHAPTER I.\n\nDown the Rabbit-Hole\n\n"
    . str_repeat("Alice followed the rabbit. This is a sentence.\n\n", 80)
    . "CHAPTER II.\n\nThe Pool of Tears\n\n"
    . str_repeat("Alice began to cry. Then she stopped.\n\n", 80);
$chapters = split_book_into_chapters($text);
assert_true(count($chapters) === 2, 'Chapter detection must return two chapters');
assert_true(str_contains($chapters[0]['title'], 'Down the Rabbit-Hole'), 'Chapter subtitle must be retained');
$chunks = chunk_speech_text($chapters[0]['text'], 1000);
assert_true(count($chunks) >= 2, 'Long chapters must be divided into chunks');
assert_true(max(array_map('mb_strlen', $chunks)) <= 1000, 'Chunks must stay within the character limit');

$temporaryDirectory = dirname(__DIR__) . '/tmp';
if (!is_dir($temporaryDirectory)) {
    mkdir($temporaryDirectory, 0750, true);
}
$joinedPath = $temporaryDirectory . '/production-smoke-joined.mp3';
$result = join_mp3_chunks([
    dirname(__DIR__) . '/assets/voice-samples/catalog/voice-06.mp3',
    dirname(__DIR__) . '/assets/voice-samples/catalog/voice-07.mp3',
], $joinedPath);
$inspection = inspect_mp3_file($joinedPath);
assert_true($result['bytes'] > 10000, 'Joined MP3 must contain audio data');
assert_true($inspection['frames'] > 10, 'Joined MP3 must contain valid frames');

echo json_encode([
    'chapters' => count($chapters),
    'chunks' => count($chunks),
    'joined_bytes' => $result['bytes'],
    'joined_frames' => $inspection['frames'],
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
