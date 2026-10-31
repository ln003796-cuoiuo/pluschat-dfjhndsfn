<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$userId = authenticated_user_id();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['POST', 'DELETE'], true)) {
    header('Allow: POST, DELETE');
    json_response(405, ['error' => 'method_not_allowed']);
}
$data = request_json();
$packId = (int) ($data['packId'] ?? 0);
if ($packId < 1) json_response(422, ['error' => 'invalid_pack']);
try {
    $pdo = database();
    $check = $pdo->prepare('SELECT owner_user_id FROM sticker_packs WHERE id = :id AND is_public = 1 LIMIT 1');
    $check->execute(['id' => $packId]);
    $ownerId = $check->fetchColumn();
    if ($ownerId === false) json_response(404, ['error' => 'pack_not_found']);
    if ((int) $ownerId === $userId) json_response(409, ['error' => 'pack_is_owned']);
    if ($method === 'DELETE') {
        $remove = $pdo->prepare('DELETE FROM user_sticker_packs WHERE user_id = :user_id AND pack_id = :pack_id');
        $remove->execute(['user_id' => $userId, 'pack_id' => $packId]);
    } else {
        $install = $pdo->prepare('INSERT IGNORE INTO user_sticker_packs (user_id, pack_id) VALUES (:user_id, :pack_id)');
        $install->execute(['user_id' => $userId, 'pack_id' => $packId]);
    }
    json_response(200, ['ok' => true]);
} catch (Throwable $error) {
    error_log('PlusChat sticker install error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
