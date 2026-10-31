<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$userId = authenticated_user_id();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
try {
    $pdo = database();
    if ($method === 'GET') {
        $query = $pdo->prepare('SELECT id, device_label AS deviceLabel, created_at AS createdAt, last_used_at AS lastUsedAt FROM user_devices WHERE user_id = :user_id AND revoked_at IS NULL AND expires_at > NOW() ORDER BY last_used_at DESC');
        $query->execute(['user_id' => $userId]);
        $devices = $query->fetchAll();
        $currentId = (int) ($_SESSION['device_id'] ?? 0);
        foreach ($devices as &$device) {
            $device['id'] = (int) $device['id'];
            $device['isCurrent'] = $device['id'] === $currentId;
        }
        unset($device);
        json_response(200, ['devices' => $devices]);
    }
    if ($method === 'DELETE') {
        $deviceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$deviceId) json_response(422, ['error' => 'invalid_device']);
        if ((int) ($_SESSION['device_id'] ?? 0) === (int) $deviceId) json_response(409, ['error' => 'current_device']);
        $delete = $pdo->prepare('UPDATE user_devices SET revoked_at = NOW() WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL');
        $delete->execute(['id' => $deviceId, 'user_id' => $userId]);
        json_response($delete->rowCount() ? 200 : 404, $delete->rowCount() ? ['ok' => true] : ['error' => 'device_not_found']);
    }
    header('Allow: GET, DELETE');
    json_response(405, ['error' => 'method_not_allowed']);
} catch (Throwable $error) {
    error_log('PlusChat device list error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
