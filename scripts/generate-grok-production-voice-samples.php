<?php

require dirname(__DIR__) . '/api/production.php';

$config = hostinger_config();
$samples = [
    6 => ['voice' => 'Eve', 'text' => 'Alice followed the White Rabbit across the field. A moment later, she disappeared down the rabbit hole after him.'],
    7 => ['voice' => 'Ara', 'text' => 'The little door opened onto the loveliest garden Alice had ever seen, bright with flowers and cool fountains.'],
    8 => ['voice' => 'Rex', 'text' => 'Down, down, down. Would the fall never come to an end? Alice wondered what might be waiting below.'],
    9 => ['voice' => 'Sal', 'text' => 'The bottle had a paper label tied around its neck. On it, in large letters, were the words: Drink me.'],
    10 => ['voice' => 'Leo', 'text' => 'Curiouser and curiouser, cried Alice, as the adventure became stranger with every passing moment.'],
];

foreach ($samples as $number => $sample) {
    $manifest = ['model' => $config['openrouter_tts_model'], 'provider_voice' => $sample['voice']];
    $destination = dirname(__DIR__) . sprintf('/assets/voice-samples/catalog/voice-%02d.mp3', $number);
    @unlink($destination);
    $result = generate_tts_chunk($config, $manifest, ['text' => $sample['text']], $destination);
    fwrite(STDOUT, sprintf("Voice %d: %d bytes\n", $number, $result['bytes']));
}
