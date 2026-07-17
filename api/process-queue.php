<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/production.php';

try {
    $result = run_production_worker(hostinger_config(), in_array('--all', $argv ?? [], true));
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, '[' . gmdate('c') . '] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
