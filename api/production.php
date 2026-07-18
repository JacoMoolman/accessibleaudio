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
                'file' => sprintf('chunks/chapter-%02d-chunk-%04d.pcm', $chapterIndex + 1, $chunkIndex + 1),
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
        'version' => 2,
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
        'response_format' => 'pcm',
    ];
    $payload = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $curl = curl_init((string) $config['openrouter_tts_url']);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $config['openrouter_api_key'],
            'Content-Type: application/json',
            'Accept: audio/pcm, application/octet-stream',
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
    $info = inspect_pcm_file($temporary);
    if (($info['samples'] ?? 0) < 2400) {
        @unlink($temporary);
        throw new RuntimeException('The voice service returned an invalid audio chunk');
    }
    if (!rename($temporary, $destination)) {
        @unlink($temporary);
        throw new RuntimeException('Could not publish the audio chunk');
    }
    return [
        'bytes' => filesize($destination),
        'generation_id' => $responseHeaders['x-generation-id'] ?? null,
        'sample_rate' => 24000,
        'channels' => 1,
        'bits_per_sample' => 16,
        'samples' => $info['samples'],
    ];
}

function inspect_pcm_file(string $path): array
{
    $bytes = filesize($path);
    if ($bytes === false || $bytes < 2 || $bytes % 2 !== 0) {
        throw new RuntimeException('The PCM audio data is incomplete');
    }
    return ['bytes' => $bytes, 'samples' => intdiv($bytes, 2)];
}

function inspect_mp3_file(string $path): array
{
    $bytes = filesize($path);
    if ($bytes === false || $bytes < 1000) {
        throw new RuntimeException('The MP3 chapter is incomplete');
    }
    $header = file_get_contents($path, false, null, 0, 3);
    $hasId3 = $header === 'ID3';
    $hasFrameSync = is_string($header)
        && strlen($header) >= 2
        && ord($header[0]) === 0xff
        && (ord($header[1]) & 0xe0) === 0xe0;
    if (!$hasId3 && !$hasFrameSync) {
        throw new RuntimeException('The encoder did not create a valid MP3 chapter');
    }
    return ['bytes' => $bytes];
}

function encode_pcm_as_mp3(string $pcmPath, string $destination, string $binary): void
{
    if (!is_file($binary) || !is_executable($binary)) {
        throw new RuntimeException('The private MP3 encoder is unavailable');
    }
    $command = [
        $binary,
        '--silent',
        '-r',
        '-s',
        '24',
        '--bitwidth',
        '16',
        '--signed',
        '--little-endian',
        '-m',
        'm',
        '-b',
        '96',
        $pcmPath,
        $destination,
    ];
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the private MP3 encoder');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        @unlink($destination);
        $detail = trim((string) ($stderr ?: $stdout));
        throw new RuntimeException('MP3 encoding failed' . ($detail !== '' ? ': ' . substr($detail, 0, 300) : ''));
    }
}

function join_pcm_chunks_as_mp3(array $paths, string $destination, string $binary, ?callable $encoder = null): array
{
    $pcmTemporary = $destination . '.pcm.part';
    $mp3Temporary = $destination . '.part';
    $output = fopen($pcmTemporary, 'wb');
    if (!$output) {
        throw new RuntimeException('Could not create the chapter audio');
    }
    $pcmBytes = 0;
    try {
        foreach ($paths as $path) {
            $info = inspect_pcm_file($path);
            $pcmBytes += $info['bytes'];
            $input = fopen($path, 'rb');
            if (!$input) {
                throw new RuntimeException('A chapter chunk could not be read');
            }
            try {
                if (stream_copy_to_stream($input, $output) === false) {
                    throw new RuntimeException('Could not assemble the chapter audio');
                }
            } finally {
                fclose($input);
            }
        }
    } finally {
        fclose($output);
    }
    try {
        if ($encoder !== null) {
            $encoder($pcmTemporary, $mp3Temporary);
        } else {
            encode_pcm_as_mp3($pcmTemporary, $mp3Temporary, $binary);
        }
        $info = inspect_mp3_file($mp3Temporary);
        if (!rename($mp3Temporary, $destination)) {
            throw new RuntimeException('Could not publish the chapter MP3');
        }
    } finally {
        @unlink($pcmTemporary);
        @unlink($mp3Temporary);
    }
    return [
        'bytes' => $info['bytes'],
        'pcm_bytes' => $pcmBytes,
        'sample_rate' => 24000,
        'channels' => 1,
        'bit_rate' => 96000,
    ];
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
        $result = join_pcm_chunks_as_mp3($paths, $outputDir . '/' . $filename, (string) $config['lame_binary']);
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
            $manifest = read_production_manifest($manifestPath);
            if ($manifest === null || (int) ($manifest['version'] ?? 0) < 2) {
                $manifest = build_production_manifest($config, $uploadDir, $record);
            }
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
