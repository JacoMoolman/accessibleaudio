<?php
require __DIR__ . '/../api/lib.php';

function assert_admin_delete(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'accessible-audio-admin-delete-' . bin2hex(random_bytes(8));
$uploadId = '2f151e15-187f-4acc-8f95-f2aa78eeb990';
$userId = 'admin-delete-smoke-user';
$uploadPath = $root
    . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . hash('sha256', $userId)
    . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $uploadId;

try {
    $outputPath = $uploadPath . DIRECTORY_SEPARATOR . 'output';
    assert_admin_delete(mkdir($outputPath, 0777, true), 'Could not create fixture folders');
    file_put_contents($uploadPath . DIRECTORY_SEPARATOR . 'book.txt', 'Fixture manuscript');
    file_put_contents($outputPath . DIRECTORY_SEPARATOR . 'chapter-1.mp3', 'Fixture audio');

    $record = [
        'id' => $uploadId,
        'user_id' => $userId,
        'filename' => 'Fixture book.txt',
        'status' => 'completed',
        'outputs' => [['chapter' => 1, 'filename' => 'chapter-1.mp3']],
    ];
    file_put_contents($root . DIRECTORY_SEPARATOR . 'uploads.jsonl', json_encode($record) . "\n");

    $deleted = delete_upload_record($root, $userId, $uploadId);
    assert_admin_delete(($deleted['id'] ?? '') === $uploadId, 'Expected record was not deleted');
    assert_admin_delete(!file_exists($uploadPath), 'Private manuscript/audio tree still exists');
    assert_admin_delete(trim((string) file_get_contents($root . DIRECTORY_SEPARATOR . 'uploads.jsonl')) === '', 'Upload index still contains the record');

    echo "Admin delete smoke test passed.\n";
} finally {
    delete_private_tree($root);
}
