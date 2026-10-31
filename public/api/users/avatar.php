<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$viewerId = authenticated_user_id();
$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$userId || $userId < 1) {
    http_response_code(404);
    exit;
}
if ((int) $userId !== $viewerId) {
    try {
        $access = database()->prepare('SELECT 1 FROM conversation_members a JOIN conversation_members b ON b.conversation_id = a.conversation_id WHERE a.user_id = :viewer AND b.user_id = :target LIMIT 1');
        $access->execute(['viewer' => $viewerId, 'target' => $userId]);
        if (!$access->fetchColumn()) { http_response_code(404); exit; }
    } catch (Throwable $error) {
        error_log('PlusChat avatar access error: ' . $error->getMessage());
        http_response_code(500); exit;
    }
}
try {
    $query = database()->prepare('SELECT avatar_path FROM users WHERE id = :id LIMIT 1');
    $query->execute(['id' => $userId]);
    $relativePath = $query->fetchColumn();
} catch (Throwable $error) {
    error_log('PlusChat avatar error: ' . $error->getMessage());
    http_response_code(500);
    exit;
}
if (!$relativePath || !preg_match('#^storage/avatars/[a-f0-9]{40}\.(jpg|png|webp)$#', $relativePath)) {
    http_response_code(404);
    exit;
}
$file = dirname(__DIR__, 3) . '/' . $relativePath;
if (!is_file($file)) {
    http_response_code(404);
    exit;
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Length: ' . filesize($file));
readfile($file);
