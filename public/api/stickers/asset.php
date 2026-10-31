<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

authenticated_user_id();
$stickerId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$stickerId || $stickerId < 1) { http_response_code(404); exit; }
try {
    $query = database()->prepare('SELECT s.asset_path FROM stickers s JOIN sticker_packs p ON p.id = s.pack_id LEFT JOIN user_sticker_packs i ON i.pack_id = p.id AND i.user_id = :user_id WHERE s.id = :sticker_id AND (p.owner_user_id = :owner_id OR p.is_public = 1 OR i.user_id IS NOT NULL OR EXISTS (SELECT 1 FROM messages m JOIN conversation_members cm ON cm.conversation_id = m.conversation_id WHERE m.sticker_id = s.id AND cm.user_id = :history_user_id)) LIMIT 1');
    $query->execute(['user_id' => (int) $_SESSION['user_id'], 'sticker_id' => $stickerId, 'owner_id' => (int) $_SESSION['user_id'], 'history_user_id' => (int) $_SESSION['user_id']]);
    $path = $query->fetchColumn();
} catch (Throwable $error) {
    error_log('PlusChat sticker asset error: ' . $error->getMessage());
    http_response_code(500); exit;
}
if (!$path || !preg_match('#^storage/stickers/[a-f0-9]{40}\.(png|webp|gif)$#', $path)) { http_response_code(404); exit; }
$file = dirname(__DIR__, 3) . '/' . $path;
if (!is_file($file)) { http_response_code(404); exit; }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) { http_response_code(404); exit; }
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Length: ' . filesize($file));
readfile($file);
