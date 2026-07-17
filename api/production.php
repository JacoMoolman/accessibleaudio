<?php

require_once __DIR__ . '/lib.php';

const PRODUCTION_MAX_ATTEMPTS = 3;

function upload_absolute_dir(string $uploadDir, array $record): string
{
    return rtrim($uploadDir, '/\\')
        . '/users/' . hash('sha256', (string) ($record['user_id'] ?? ''))
        . '/uploads/' . (string) ($record['id'] ?? '');
}

function production_manifest_path(string $uploadDir, array $record): string
{
    return upload_absolute_dir($uploadDir, $record) . '/production.json';
}

function read_production_manifest(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $manifest = json_decode((string) file_get_contents($path), true);
    return is_array($manifest) ? $manifest : null;
}

function write_production_manifest(string $path, array $manifest): void
{
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false || file_put_contents($temporary, $encoded . "\n", LOCK_EX) === false) {
        @unlink($temporary);
        throw new RuntimeException('Could not save production progress');
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not publish production progress');
    }
}

function build_production_manifest(array $config, string $uploadDir, array $record): array
{
    $bookPath = upload_absolute_dir($uploadDir, $record) . '/' . safe_filename((string) ($record['filename'] ?? 'upload.txt'));
    $text = file_get_contents($bookPath);
    if ($text === false || trim($text) === '') {
        throw new RuntimeException('The uploaded manuscript is missing or empty');
    }
    $voice = production_voice_config((string) ($record['narrator_voice'] ?? ''));
    if ($voice === null) {
        throw new RuntimeException('The selected narrator voice cannot be produced automatically');
    }
    $chapters = split_book_into_chapters($text);
    $manifestChapters = [];
    foreach ($chapters as $chapterIndex => $chapter) {
        $chunks = chunk_speech_text($chapter['text'], (int) $config['tts_chunk_characters']);
        $manifestChunks = [];
        foreach ($chunks as $chunkIndex => $chunkText) {
            $manifestChunks[] = [
                'index' => $chunkIndex + 1,
                'characters' => mb_strlen($chunkText),
                'text' => $chunkText,
                'status' => 'pending',
                'attempts' => 0,
                'file' => sprintf('chunks/chapter-%02d-chunk-%04d.mp3', $chapterIndex + 1, $chunkIndex + 1),
            ];
        }
        $manifestChapters[] = [
            'index' => $chapterIndex + 1,
            'title' => $chapter['title'],
            'status' => 'pending',
            'chunks' => $manifestChunks,
        ];
    }
    return [
        'version' => 1,
        'upload_id' => $record['id'],
        'model' => $config['openrouter_tts_model'],
        'public_voice' => $voice['public_label'],
        'provider_voice' => $voice['provider_voice'],
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'chapters' => $manifestChapters,
    ];
}

function split_book_into_chapters(string $text): array
{
    $text = preg_replace("/\r\n?|\x{2028}|\x{2029}/u", "\n", $text) ?? $text;
    $pattern = '/^[ \t]*(chapter|hoofstuk|isahluko|isigaba|chapitre|cap[ií]tulo|capitulo|kapitel|capitolo|part|book|deel)\b[^\n]*$/imu';
    preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
    if (empty($matches[0])) {
        return [['title' => 'Full book', 'text' => trim($text)]];
    }
    $candidates = [];
    $count = count($matches[0]);
    for ($index = 0; $index < $count; $index++) {
        $offset = $matches[0][$index][1];
        $end = $index + 1 < $count ? $matches[0][$index + 1][1] : strlen($text);
        $section = trim(substr($text, $offset, $end - $offset));
        if (count_words($section) < 40) {
            continue;
        }
        $lines = preg_split('/\n/', $section) ?: [];
        $title = trim((string) array_shift($lines));
        while ($lines && trim((string) $lines[0]) === '') {
            array_shift($lines);
        }
        if ($lines) {
            $subtitle = trim((string) $lines[0]);
            if ($subtitle !== '' && mb_strlen($subtitle) <= 100 && !preg_match('/[.!?][”"\']?$/u', $subtitle)) {
                $title .= ': ' . $subtitle;
            }
        }
        $candidates[] = ['title' => preg_replace('/\s+/u', ' ', $title) ?: $title, 'text' => $section];
    }
    return $candidates ?: [['title' => 'Full book', 'text' => trim($text)]];
}

function chunk_speech_text(string $text, int $maxCharacters): array
{
    $text = trim(preg_replace("/\r\n?/u", "\n", $text) ?? $text);
    $paragraphs = preg_split('/\n[ \t]*\n+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $units = [];
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim(preg_replace('/[ \t]+/u', ' ', $paragraph) ?? $paragraph);
        if (mb_strlen($paragraph) <= $maxCharacters) {
            $units[] = $paragraph;
            continue;
        }
        preg_match_all('/.+?(?:[.!?][”"\']?(?=\s|$)|$)/us', $paragraph, $sentenceMatches);
        $sentences = array_values(array_filter(array_map('trim', $sentenceMatches[0] ?? [])));
        if (!$sentences) {
            $sentences = [$paragraph];
        }
        $sentenceBuffer = '';
        foreach ($sentences as $sentence) {
            if (mb_strlen($sentence) > $maxCharacters) {
                if ($sentenceBuffer !== '') {
                    $units[] = $sentenceBuffer;
                    $sentenceBuffer = '';
                }
                foreach (split_long_text($sentence, $maxCharacters) as $part) {
                    $units[] = $part;
                }
                continue;
            }
            $candidate = $sentenceBuffer === '' ? $sentence : $sentenceBuffer . ' ' . $sentence;
            if (mb_strlen($candidate) > $maxCharacters) {
                $units[] = $sentenceBuffer;
                $sentenceBuffer = $sentence;
            } else {
                $sentenceBuffer = $candidate;
            }
        }
        if ($sentenceBuffer !== '') {
            $units[] = $sentenceBuffer;
        }
    }
    $chunks = [];
    $buffer = '';
    foreach ($units as $unit) {
        $candidate = $buffer === '' ? $unit : $buffer . "\n\n" . $unit;
        if ($buffer !== '' && mb_strlen($candidate) > $maxCharacters) {
            $chunks[] = $buffer;
            $buffer = $unit;
        } else {
            $buffer = $candidate;
        }
    }
    if ($buffer !== '') {
        $chunks[] = $buffer;
    }
    return $chunks;
}

function split_long_text(string $text, int $maxCharacters): array
{
    $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $parts = [];
    $buffer = '';
    foreach ($words as $word) {
        $candidate = $buffer === '' ? $word : $buffer . ' ' . $word;
        if ($buffer !== '' && mb_strlen($candidate) > $maxCharacters) {
            $parts[] = $buffer;
            $buffer = $word;
        } else {
            $buffer = $candidate;
        }
    }
    if ($buffer !== '') {
        $parts[] = $buffer;
    }
    return $parts;
}

function next_manifest_chunk(array $manifest): ?array
{
    foreach ($manifest['chapters'] as $chapterIndex => $chapter) {
        foreach ($chapter['chunks'] as $chunkIndex => $chunk) {
            if (($chunk['status'] ?? '') === 'pending' || (($chunk['status'] ?? '') === 'failed' && (int) ($chunk['attempts'] ?? 0) < PRODUCTION_MAX_ATTEMPTS)) {
                return [$chapterIndex, $chunkIndex];
            }
        }
    }
    return null;
}

function generate_tts_chunk(array $config, array $manifest, array $chunk, string $destination): array
{
    $directory = dirname($destination);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the audio chunk directory');
    }
    $temporary = $destination . '.part';
    $handle = fopen($temporary, 'wb');
    if (!$handle) {
        throw new RuntimeException('Could not create the audio chunk');
    }
    $responseHeaders = [];
    $request = [
        'model' => $manifest['model'],
        'input' => $chunk['text'],
        'voice' => $manifest['provider_voice'],
        'response_format' => 'mp3',
    ];
    $payload = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $curl = curl_init((string) $config['openrouter_tts_url']);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $config['openrouter_api_key'],
            'Content-Type: application/json',
            'Accept: audio/mpeg',
            'HTTP-Referer: ' . request_base_url($config),
            'X-Title: Accessible Audio',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_FILE => $handle,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => (int) $config['tts_request_timeout'],
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    $error = $ok === false ? curl_error($curl) : '';
    curl_close($curl);
    fclose($handle);
    if (!$ok || $status < 200 || $status >= 300 || !str_starts_with(strtolower($contentType), 'audio/')) {
        $body = is_file($temporary) ? substr((string) file_get_contents($temporary), 0, 500) : '';
        @unlink($temporary);
        throw new RuntimeException('OpenRouter TTS failed' . ($status ? ' (HTTP ' . $status . ')' : '') . ': ' . trim($error ?: $body));
    }
    $info = inspect_mp3_file($temporary);
    if (($info['frames'] ?? 0) < 5) {
        @unlink($temporary);
        throw new RuntimeException('OpenRouter returned an invalid MP3 chunk');
    }
    if (!rename($temporary, $destination)) {
        @unlink($temporary);
        throw new RuntimeException('Could not publish the audio chunk');
    }
    return [
        'bytes' => filesize($destination),
        'generation_id' => $responseHeaders['x-generation-id'] ?? null,
        'sample_rate' => $info['sample_rate'],
        'mpeg_version' => $info['mpeg_version'],
        'layer' => $info['layer'],
    ];
}

function mp3_frame_info(string $header): ?array
{
    if (strlen($header) < 4) {
        return null;
    }
    $value = unpack('N', $header)[1];
    if (($value & 0xffe00000) !== 0xffe00000) {
        return null;
    }
    $versionBits = ($value >> 19) & 3;
    $layerBits = ($value >> 17) & 3;
    $bitrateIndex = ($value >> 12) & 15;
    $sampleIndex = ($value >> 10) & 3;
    if ($versionBits === 1 || $layerBits !== 1 || $bitrateIndex === 0 || $bitrateIndex === 15 || $sampleIndex === 3) {
        return null;
    }
    $version = $versionBits === 3 ? 1.0 : ($versionBits === 2 ? 2.0 : 2.5);
    $bitrates = $version === 1.0
        ? [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320]
        : [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
    $baseRates = [44100, 48000, 32000];
    $sampleRate = (int) ($baseRates[$sampleIndex] / ($version === 1.0 ? 1 : ($version === 2.0 ? 2 : 4)));
    $padding = ($value >> 9) & 1;
    $coefficient = $version === 1.0 ? 144 : 72;
    $length = (int) floor($coefficient * $bitrates[$bitrateIndex] * 1000 / $sampleRate) + $padding;
    return [
        'length' => $length,
        'sample_rate' => $sampleRate,
        'mpeg_version' => $version,
        'layer' => 3,
    ];
}

function mp3_audio_offset(string $data): int
{
    if (substr($data, 0, 3) !== 'ID3' || strlen($data) < 10) {
        return 0;
    }
    $bytes = array_values(unpack('C4', substr($data, 6, 4)));
    $size = (($bytes[0] & 0x7f) << 21) | (($bytes[1] & 0x7f) << 14) | (($bytes[2] & 0x7f) << 7) | ($bytes[3] & 0x7f);
    return min(strlen($data), 10 + $size + ((ord($data[5]) & 0x10) ? 10 : 0));
}

function mp3_frames(string $data): array
{
    $end = strlen($data);
    if ($end >= 128 && substr($data, -128, 3) === 'TAG') {
        $end -= 128;
    }
    $offset = mp3_audio_offset($data);
    while ($offset + 4 <= $end && mp3_frame_info(substr($data, $offset, 4)) === null) {
        $offset++;
    }
    $frames = [];
    while ($offset + 4 <= $end) {
        $info = mp3_frame_info(substr($data, $offset, 4));
        if ($info === null || $offset + $info['length'] > $end) {
            break;
        }
        $frame = substr($data, $offset, $info['length']);
        $frames[] = ['data' => $frame, 'info' => $info];
        $offset += $info['length'];
    }
    return $frames;
}

function inspect_mp3_file(string $path): array
{
    $data = file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException('Could not read MP3 data');
    }
    $frames = mp3_frames($data);
    if (!$frames) {
        throw new RuntimeException('No valid MP3 frames were found');
    }
    return $frames[0]['info'] + ['frames' => count($frames)];
}

function join_mp3_chunks(array $paths, string $destination): array
{
    $temporary = $destination . '.part';
    $output = fopen($temporary, 'wb');
    if (!$output) {
        throw new RuntimeException('Could not create the chapter MP3');
    }
    $expected = null;
    $frameCount = 0;
    try {
        foreach ($paths as $path) {
            $data = file_get_contents($path);
            if ($data === false) {
                throw new RuntimeException('A chapter chunk could not be read');
            }
            $frames = mp3_frames($data);
            if (!$frames) {
                throw new RuntimeException('A chapter chunk contains no MP3 frames');
            }
            $current = $frames[0]['info'];
            $signature = [$current['sample_rate'], $current['mpeg_version'], $current['layer']];
            if ($expected !== null && $signature !== $expected) {
                throw new RuntimeException('Audio chunks use incompatible MP3 settings');
            }
            $expected = $signature;
            foreach ($frames as $frame) {
                $isVbrHeader = str_contains($frame['data'], 'Xing') || str_contains($frame['data'], 'Info') || str_contains($frame['data'], 'VBRI');
                if ($isVbrHeader) {
                    continue;
                }
                if (fwrite($output, $frame['data']) === false) {
                    throw new RuntimeException('Could not write the chapter MP3');
                }
                $frameCount++;
            }
        }
    } finally {
        fclose($output);
    }
    if ($frameCount < 5 || !rename($temporary, $destination)) {
        @unlink($temporary);
        throw new RuntimeException('Could not publish the chapter MP3');
    }
    return ['bytes' => filesize($destination), 'frames' => $frameCount];
}

function safe_chapter_filename(int $index, string $title): string
{
    $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-'));
    $slug = substr($slug ?: 'chapter', 0, 70);
    return sprintf('%02d-%s.mp3', $index, $slug);
}

function finalize_production(array $config, string $uploadDir, array $record, array &$manifest): array
{
    $baseDir = upload_absolute_dir($uploadDir, $record);
    $outputDir = $baseDir . '/output';
    if (!is_dir($outputDir) && !mkdir($outputDir, 0750, true) && !is_dir($outputDir)) {
        throw new RuntimeException('Could not create the chapter output directory');
    }
    $outputs = [];
    foreach ($manifest['chapters'] as $chapterIndex => &$chapter) {
        $paths = [];
        foreach ($chapter['chunks'] as $chunk) {
            if (($chunk['status'] ?? '') !== 'completed') {
                throw new RuntimeException('A chapter still has unfinished chunks');
            }
            $paths[] = $baseDir . '/' . $chunk['file'];
        }
        $filename = safe_chapter_filename($chapterIndex + 1, (string) $chapter['title']);
        $result = join_mp3_chunks($paths, $outputDir . '/' . $filename);
        $chapter['status'] = 'completed';
        $chapter['output_file'] = 'output/' . $filename;
        $outputs[] = [
            'chapter' => $chapterIndex + 1,
            'title' => $chapter['title'],
            'filename' => $filename,
            'path' => 'output/' . $filename,
            'bytes' => $result['bytes'],
        ];
    }
    unset($chapter);
    $manifest['completed_at'] = gmdate('c');
    $manifest['updated_at'] = gmdate('c');
    write_production_manifest(production_manifest_path($uploadDir, $record), $manifest);
    $updated = update_upload_record($uploadDir, (string) $record['id'], static function (array $current) use ($outputs): array {
        $current['status'] = 'completed';
        $current['processed_at'] = gmdate('c');
        $current['result_path'] = 'output';
        $current['outputs'] = $outputs;
        unset($current['production_error']);
        return $current;
    });
    if ($updated === null) {
        throw new RuntimeException('Could not mark the audiobook completed');
    }
    return $updated;
}

function send_completion_email(array $config, string $uploadDir, array $record): void
{
    if (!empty($record['completion_email_sent_at']) || empty($record['user_email'])) {
        return;
    }
    require_once __DIR__ . '/smtp.php';
    $body = implode("\n", [
        'Your Accessible Audio audiobook is ready.',
        '',
        'Book: ' . ($record['filename'] ?? 'Audiobook'),
        'Chapters: ' . count($record['outputs'] ?? []),
        '',
        'Sign in to download your MP3 chapter files:',
        request_base_url($config) . '/submit/',
    ]);
    aa_send_smtp_email($config, (string) $record['user_email'], 'Your audiobook is ready: ' . ($record['filename'] ?? 'Audiobook'), $body);
    update_upload_record($uploadDir, (string) $record['id'], static function (array $current): array {
        $current['completion_email_sent_at'] = gmdate('c');
        return $current;
    });
}

function run_production_worker(array $config, bool $processAll = false): array
{
    if (!production_configured($config)) {
        throw new RuntimeException('OPENROUTER_API_KEY is not configured');
    }
    $uploadDir = ensure_upload_dir($config);
    $lock = fopen(rtrim($uploadDir, '/\\') . '/.production-worker.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return ['processed_chunks' => 0, 'message' => 'Another production worker is running'];
    }
    $limit = $processAll ? PHP_INT_MAX : (int) $config['worker_chunks_per_run'];
    $processed = 0;
    try {
        foreach (list_production_records($uploadDir) as $completedRecord) {
            if (($completedRecord['status'] ?? '') === 'completed' && empty($completedRecord['completion_email_sent_at'])) {
                try {
                    send_completion_email($config, $uploadDir, $completedRecord);
                } catch (Throwable $emailError) {
                    error_log('Accessible Audio completion email failed: ' . $emailError->getMessage());
                }
            }
        }
        while ($processed < $limit) {
            $record = null;
            foreach (list_production_records($uploadDir) as $candidate) {
                if (in_array(($candidate['status'] ?? ''), ['queued', 'processing'], true)) {
                    $record = $candidate;
                    break;
                }
            }
            if ($record === null) {
                break;
            }
            if (($record['status'] ?? '') === 'queued') {
                $record = update_upload_record($uploadDir, (string) $record['id'], static function (array $current): array {
                    $current['status'] = 'processing';
                    $current['processing_started_at'] = $current['processing_started_at'] ?? gmdate('c');
                    return $current;
                }) ?? $record;
            }
            $manifestPath = production_manifest_path($uploadDir, $record);
            $manifest = read_production_manifest($manifestPath) ?? build_production_manifest($config, $uploadDir, $record);
            write_production_manifest($manifestPath, $manifest);
            $next = next_manifest_chunk($manifest);
            if ($next === null) {
                $completed = finalize_production($config, $uploadDir, $record, $manifest);
                send_completion_email($config, $uploadDir, $completed);
                continue;
            }
            [$chapterIndex, $chunkIndex] = $next;
            $chunk = $manifest['chapters'][$chapterIndex]['chunks'][$chunkIndex];
            $manifest['chapters'][$chapterIndex]['chunks'][$chunkIndex]['attempts'] = (int) ($chunk['attempts'] ?? 0) + 1;
            try {
                $destination = upload_absolute_dir($uploadDir, $record) . '/' . $chunk['file'];
                $result = generate_tts_chunk($config, $manifest, $chunk, $destination);
                $manifest['chapters'][$chapterIndex]['chunks'][$chunkIndex] = array_merge(
                    $manifest['chapters'][$chapterIndex]['chunks'][$chunkIndex],
                    $result,
                    ['status' => 'completed', 'completed_at' => gmdate('c')],
                );
                unset($manifest['chapters'][$chapterIndex]['chunks'][$chunkIndex]['last_error']);
                $processed++;
                $totalChunks = 0;
                $completedChunks = 0;
                foreach ($manifest['chapters'] as $progressChapter) {
                    foreach ($progressChapter['chunks'] as $progressChunk) {
                        $totalChunks++;
                        if (($progressChunk['status'] ?? '') === 'completed') {
                            $completedChunks++;
                        }
                    }
                }
                update_upload_record($uploadDir, (string) $record['id'], static function (array $current) use ($completedChunks, $totalChunks): array {
                    $current['status'] = 'processing';
                    $current['progress_completed_chunks'] = $completedChunks;
                    $current['progress_total_chunks'] = $totalChunks;
                    return $current;
                });
            } catch (Throwable $error) {
                $attempts = (int) $manifest['chapters'][$chapterIndex]['chunks'][$chunkIndex]['attempts'];
                $manifest['chapters'][$chapterIndex]['chunks'][$chunkIndex]['status'] = 'failed';
                $manifest['chapters'][$chapterIndex]['chunks'][$chunkIndex]['last_error'] = $error->getMessage();
                if ($attempts >= PRODUCTION_MAX_ATTEMPTS) {
                    update_upload_record($uploadDir, (string) $record['id'], static function (array $current) use ($error): array {
                        $current['status'] = 'failed';
                        $current['production_error'] = $error->getMessage();
                        return $current;
                    });
                }
                throw $error;
            } finally {
                $manifest['updated_at'] = gmdate('c');
                write_production_manifest($manifestPath, $manifest);
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return ['processed_chunks' => $processed, 'message' => $processed ? 'Production advanced' : 'No queued work'];
}
