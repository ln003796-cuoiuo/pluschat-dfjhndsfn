<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$userId = authenticated_user_id();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
try {
    $pdo = database();
    if ($method === 'GET') {
        $query = $pdo->prepare("SELECT p.id, p.owner_user_id AS ownerUserId, p.title, p.description, p.is_public AS isPublic,
            u.username AS ownerUsername, (p.owner_user_id = :owner_id) AS isOwner, (i.user_id IS NOT NULL) AS isInstalled
            FROM sticker_packs p JOIN users u ON u.id = p.owner_user_id
            LEFT JOIN user_sticker_packs i ON i.pack_id = p.id AND i.user_id = :install_user_id
            WHERE p.is_public = 1 OR p.owner_user_id = :where_owner OR i.user_id IS NOT NULL
            ORDER BY p.updated_at DESC LIMIT 100");
        $query->execute(['owner_id' => $userId, 'install_user_id' => $userId, 'where_owner' => $userId]);
        $packs = $query->fetchAll();
        $stickers = $pdo->prepare('SELECT id, alt_text AS altText, sort_order AS sortOrder FROM stickers WHERE pack_id = :pack_id ORDER BY sort_order, id');
        foreach ($packs as &$pack) {
            $pack['id'] = (int) $pack['id'];
            $pack['ownerUserId'] = (int) $pack['ownerUserId'];
            $pack['isPublic'] = (bool) $pack['isPublic'];
            $pack['isOwner'] = (bool) $pack['isOwner'];
            $pack['isInstalled'] = (bool) $pack['isInstalled'];
            $stickers->execute(['pack_id' => $pack['id']]);
            $pack['stickers'] = array_map(static fn($sticker) => [
                'id' => (int) $sticker['id'],
                'altText' => $sticker['altText'],
                'assetUrl' => '/api/stickers/asset.php?id=' . (int) $sticker['id'],
            ], $stickers->fetchAll());
        }
        unset($pack);
        json_response(200, ['packs' => $packs]);
    }

    if ($method === 'POST') {
        $data = request_json();
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        if ($title === '' || utf8_length($title) > 80 || utf8_length($description) > 255) json_response(422, ['error' => 'invalid_pack']);
        $insert = $pdo->prepare('INSERT INTO sticker_packs (owner_user_id, title, description, is_public) VALUES (:owner, :title, :description, :is_public)');
        $insert->execute(['owner' => $userId, 'title' => $title, 'description' => $description ?: null, 'is_public' => !empty($data['isPublic']) ? 1 : 0]);
        json_response(201, ['packId' => (int) $pdo->lastInsertId()]);
    }

    if ($method === 'DELETE') {
        $packId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$packId) json_response(422, ['error' => 'invalid_pack']);
        $pdo->beginTransaction();
        $owned = $pdo->prepare('SELECT id FROM sticker_packs WHERE id = :id AND owner_user_id = :owner FOR UPDATE');
        $owned->execute(['id' => $packId, 'owner' => $userId]);
        if (!$owned->fetchColumn()) {
            $pdo->rollBack();
            json_response(404, ['error' => 'pack_not_found']);
        }
        $inUse = $pdo->prepare('SELECT COUNT(*) FROM messages m JOIN stickers s ON s.id = m.sticker_id WHERE s.pack_id = :pack_id');
        $inUse->execute(['pack_id' => $packId]);
        if ((int) $inUse->fetchColumn() > 0) {
            $pdo->rollBack();
            json_response(409, ['error' => 'pack_in_use']);
        }
        $paths = $pdo->prepare('SELECT asset_path FROM stickers WHERE pack_id = :pack_id');
        $paths->execute(['pack_id' => $packId]);
        $assetPaths = $paths->fetchAll(PDO::FETCH_COLUMN);
        $delete = $pdo->prepare('DELETE FROM sticker_packs WHERE id = :id AND owner_user_id = :owner');
        $delete->execute(['id' => $packId, 'owner' => $userId]);
        $pdo->commit();
        foreach ($assetPaths as $path) {
            if (preg_match('#^storage/stickers/[a-f0-9]{40}\.(png|webp|gif)$#', $path)) @unlink(dirname(__DIR__, 3) . '/' . $path);
        }
        json_response(200, ['ok' => true]);
    }
    header('Allow: GET, POST, DELETE');
    json_response(405, ['error' => 'method_not_allowed']);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($error instanceof PDOException && $error->getCode() === '23000') {
        json_response(409, ['error' => 'pack_in_use']);
    }
    error_log('PlusChat sticker packs error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
