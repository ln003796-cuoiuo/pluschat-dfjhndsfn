<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$userId = authenticated_user_id();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
try {
    $pdo = database();
    if ($method === 'GET') {
        $query = $pdo->prepare('SELECT location_city, (location_enabled_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS location_fresh FROM users WHERE id = :id LIMIT 1');
        $query->execute(['id' => $userId]);
        $row = $query->fetch();
        if (!$row) json_response(404, ['error' => 'profile_not_found']);
        json_response(200, ['locationCity' => $row['location_city'], 'enabled' => (bool) $row['location_fresh']]);
    }
    if ($method === 'DELETE') {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE cm FROM conversation_members cm JOIN conversations c ON c.id = cm.conversation_id WHERE cm.user_id = :user_id AND c.kind = \'local\'')->execute(['user_id' => $userId]);
        $update = $pdo->prepare('UPDATE users SET location_city = NULL, location_enabled_at = NULL, location_cell = NULL WHERE id = :id');
        $update->execute(['id' => $userId]);
        $pdo->commit();
        json_response(200, ['ok' => true]);
    }
    require_post();
    $data = request_json();
    $city = trim((string) ($data['city'] ?? ''));
    $lat = filter_var($data['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $lon = filter_var($data['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($city === '' || utf8_length($city) > 92 || $lat === false || $lon === false || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        json_response(422, ['error' => 'invalid_location']);
    }
    $secret = configured_app_secret();
    if ($secret === null) json_response(503, ['error' => 'location_not_configured']);
    // Keep only a keyed hash of a roughly 1 km grid cell. Raw coordinates are never stored.
    $cell = sprintf('%.2f:%.2f', round((float) $lat, 2), round((float) $lon, 2));
    $cellHash = hash_hmac('sha256', $cell, $secret);
    $pdo->beginTransaction();
    $update = $pdo->prepare('UPDATE users SET location_city = :city, location_enabled_at = NOW(), location_cell = :cell WHERE id = :id');
    $update->execute(['city' => $city, 'cell' => $cellHash, 'id' => $userId]);
    $pdo->prepare('DELETE cm FROM conversation_members cm JOIN conversations c ON c.id = cm.conversation_id WHERE cm.user_id = :user_id AND c.kind = \'local\'')->execute(['user_id' => $userId]);
    $pdo->commit();
    json_response(200, ['ok' => true, 'locationCity' => $city, 'locationEnabled' => true, 'privacy' => 'Точные координаты не сохраняются.']);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('PlusChat location error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
