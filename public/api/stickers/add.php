<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$userId = authenticated_user_id();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'DELETE') {
    $stickerId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$stickerId) json_response(422, ['error' => 'invalid_sticker']);
    try {
        $pdo = database();
        $pdo->beginTransaction();
        $find = $pdo->prepare('SELECT s.asset_path FROM stickers s JOIN sticker_packs p ON p.id = s.pack_id WHERE s.id = :id AND p.owner_user_id = :owner LIMIT 1 FOR UPDATE');
        $find->execute(['id' => $stickerId, 'owner' => $userId]);
        $path = $find->fetchColumn();
        if (!$path) {
            $pdo->rollBack();
            json_response(404, ['error' => 'sticker_not_found']);
        }
        $used = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE sticker_id = :id');
        $used->execute(['id' => $stickerId]);
        if ((int) $used->fetchColumn() > 0) {
            $pdo->rollBack();
            json_response(409, ['error' => 'sticker_in_use']);
        }
        $pdo->prepare('DELETE FROM stickers WHERE id = :id')->execute(['id' => $stickerId]);
        $pdo->commit();
        if (preg_match('#^storage/stickers/[a-f0-9]{40}\.(png|webp|gif)$#', $path)) @unlink(dirname(__DIR__, 3) . '/' . $path);
        json_response(200, ['ok' => true]);
    } catch (Throwable $error) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && $error->getCode() === '23000') {
            json_response(409, ['error' => 'sticker_in_use']);
        }
        error_log('PlusChat sticker delete error: ' . $error->getMessage());
        json_response(500, ['error' => 'server_error']);
    }
}
require_post();
$packId = (int) ($_POST['packId'] ?? 0);
$altText = trim((string) ($_POST['altText'] ?? ''));
$file = $_FILES['sticker'] ?? null;
if ($packId < 1 || !$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024 || utf8_length($altText) > 120) {
    json_response(422, ['error' => 'invalid_sticker']);
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$extensions = ['image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$image = @getimagesize($file['tmp_name']);
if (!isset($extensions[$mime]) || !$image || $image[0] > 4096 || $image[1] > 4096 || $image[0] * $image[1] > 12000000) json_response(422, ['error' => 'sticker_type_not_supported']);

$absolutePath = null;
try {
    $pdo = database();
    $retryAfter = consume_rate_limit('sticker-upload-user', (string) $userId, 50, 3600);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        json_response(429, ['error' => 'too_many_sticker_uploads', 'retryAfter' => $retryAfter]);
    }
    $pdo->beginTransaction();
    $owner = $pdo->prepare('SELECT id FROM sticker_packs WHERE id = :pack_id AND owner_user_id = :owner_id LIMIT 1 FOR UPDATE');
    $owner->execute(['pack_id' => $packId, 'owner_id' => $userId]);
    if (!$owner->fetchColumn()) {
        $pdo->rollBack();
        json_response(404, ['error' => 'pack_not_found']);
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM stickers WHERE pack_id = :pack_id');
    $count->execute(['pack_id' => $packId]);
    if ((int) $count->fetchColumn() >= 100) {
        $pdo->rollBack();
        json_response(409, ['error' => 'pack_sticker_limit']);
    }
    $stickerDir = dirname(__DIR__, 3) . '/storage/stickers';
    if (!is_dir($stickerDir) && !mkdir($stickerDir, 0700, true) && !is_dir($stickerDir)) {
        $pdo->rollBack();
        json_response(500, ['error' => 'server_error']);
    }
    $filename = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
    $absolutePath = $stickerDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
        $pdo->rollBack();
        json_response(500, ['error' => 'upload_failed']);
    }
    $order = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM stickers WHERE pack_id = :pack_id');
    $order->execute(['pack_id' => $packId]);
    $insert = $pdo->prepare('INSERT INTO stickers (pack_id, asset_path, alt_text, sort_order) VALUES (:pack_id, :path, :alt, :sort_order)');
    $insert->execute(['pack_id' => $packId, 'path' => 'storage/stickers/' . $filename, 'alt' => $altText ?: null, 'sort_order' => (int) $order->fetchColumn()]);
    $stickerId = (int) $pdo->lastInsertId();
    $pdo->commit();
    json_response(201, ['stickerId' => $stickerId, 'assetUrl' => '/api/stickers/asset.php?id=' . $stickerId]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($absolutePath !== null) @unlink($absolutePath);
    error_log('PlusChat sticker upload error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
