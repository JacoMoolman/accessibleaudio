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
$joinedPath = $temporaryDirectory . '/production-smoke-joined.wav';
file_put_contents($pcmOne, str_repeat(pack('v', 1200), 3000));
file_put_contents($pcmTwo, str_repeat(pack('v', 2200), 3000));
$result = join_pcm_chunks_as_wav([$pcmOne, $pcmTwo], $joinedPath);
$wav = file_get_contents($joinedPath);
assert_true($result['bytes'] === 12044, 'Joined WAV must contain its header and all PCM data');
assert_true(substr($wav, 0, 4) === 'RIFF', 'Joined WAV must have a RIFF header');
assert_true(substr($wav, 8, 4) === 'WAVE', 'Joined WAV must identify the WAVE format');
assert_true(unpack('V', substr($wav, 24, 4))[1] === 24000, 'Joined WAV must use the provider sample rate');
assert_true(unpack('v', substr($wav, 34, 2))[1] === 16, 'Joined WAV must contain 16-bit samples');
assert_true(unpack('V', substr($wav, 40, 4))[1] === 12000, 'Joined WAV data length must match the PCM chunks');
@unlink($pcmOne);
@unlink($pcmTwo);
@unlink($joinedPath);

echo json_encode([
    'chapters' => count($chapters),
    'chunks' => count($chunks),
    'joined_bytes' => $result['bytes'],
    'sample_rate' => $result['sample_rate'],
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
