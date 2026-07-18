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
$pcmOne = $temporaryDirectory . '/production-smoke-one.pcm';
$pcmTwo = $temporaryDirectory . '/production-smoke-two.pcm';
$joinedPath = $temporaryDirectory . '/production-smoke-joined.mp3';
file_put_contents($pcmOne, str_repeat(pack('v', 1200), 3000));
file_put_contents($pcmTwo, str_repeat(pack('v', 2200), 3000));
$testEncoder = static function (string $pcmPath, string $mp3Path): void {
    assert_true(filesize($pcmPath) === 12000, 'All PCM chunks must be joined before encoding');
    file_put_contents($mp3Path, "\xff\xfb\x90\x64" . str_repeat("\0", 2000));
};
$result = join_pcm_chunks_as_mp3([$pcmOne, $pcmTwo], $joinedPath, '/private/lame', $testEncoder);
$mp3 = file_get_contents($joinedPath);
assert_true($result['bytes'] === 2004, 'Encoded MP3 byte count must be recorded');
assert_true($result['pcm_bytes'] === 12000, 'Joined PCM byte count must include every chunk');
assert_true(ord($mp3[0]) === 0xff && (ord($mp3[1]) & 0xe0) === 0xe0, 'Output must have an MP3 frame signature');
@unlink($pcmOne);
@unlink($pcmTwo);
@unlink($joinedPath);

echo json_encode([
    'chapters' => count($chapters),
    'chunks' => count($chunks),
    'joined_bytes' => $result['bytes'],
    'sample_rate' => $result['sample_rate'],
    'bit_rate' => $result['bit_rate'],
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
