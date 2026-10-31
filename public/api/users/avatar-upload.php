<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$userId = authenticated_user_id();
require_post();
$file = $_FILES['avatar'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 4 * 1024 * 1024) json_response(422, ['error' => 'avatar_upload_failed']);
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
$extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$image = @getimagesize($file['tmp_name']);
if (!$image || !isset($extensions[$mime]) || $image['mime'] !== $mime || $image[0] > 4096 || $image[1] > 4096 || $image[0] * $image[1] > 12000000) {
    json_response(422, ['error' => 'avatar_type_not_supported']);
}
$avatarDir = dirname(__DIR__, 3) . '/storage/avatars';
if (!is_dir($avatarDir) && !mkdir($avatarDir, 0700, true) && !is_dir($avatarDir)) json_response(500, ['error' => 'server_error']);
$filename = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
$absolutePath = $avatarDir . '/' . $filename;
if (!move_uploaded_file($file['tmp_name'], $absolutePath)) json_response(500, ['error' => 'avatar_upload_failed']);

try {
    $pdo = database();
    $old = $pdo->prepare('SELECT avatar_path FROM users WHERE id = :id LIMIT 1');
    $old->execute(['id' => $userId]);
    $oldPath = $old->fetchColumn();
    $update = $pdo->prepare('UPDATE users SET avatar_path = :path WHERE id = :id');
    $update->execute(['path' => 'storage/avatars/' . $filename, 'id' => $userId]);
    if (is_string($oldPath) && preg_match('#^storage/avatars/[a-f0-9]{40}\.(jpg|png|webp)$#', $oldPath)) @unlink(dirname(__DIR__, 3) . '/' . $oldPath);
    json_response(200, ['avatarUrl' => '/api/users/avatar.php?id=' . $userId . '&v=' . time()]);
} catch (Throwable $error) {
    @unlink($absolutePath);
    error_log('PlusChat avatar update error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
