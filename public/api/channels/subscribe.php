<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

require_post();
$userId = authenticated_user_id();
$data = request_json();
$channelId = filter_var($data['channel_id'] ?? null, FILTER_VALIDATE_INT);
$action = (string) ($data['action'] ?? 'subscribe');
if (!$channelId || $channelId < 1 || !in_array($action, ['subscribe', 'unsubscribe'], true)) {
    json_response(422, ['error' => 'invalid_subscription']);
}

try {
    $pdo = database();
    $channel = $pdo->prepare("SELECT id, owner_user_id, visibility FROM channels WHERE id = :id LIMIT 1");
    $channel->execute(['id' => $channelId]);
    $record = $channel->fetch();
    if (!$record || ($record['visibility'] !== 'public' && (int) $record['owner_user_id'] !== $userId)) {
        json_response(404, ['error' => 'channel_not_found']);
    }
    if ($action === 'subscribe') {
        $statement = $pdo->prepare(
            'INSERT IGNORE INTO channel_subscriptions (channel_id, user_id) VALUES (:channel_id, :user_id)'
        );
        $statement->execute(['channel_id' => $channelId, 'user_id' => $userId]);
    } else {
        $statement = $pdo->prepare(
            'DELETE FROM channel_subscriptions WHERE channel_id = :channel_id AND user_id = :user_id'
        );
        $statement->execute(['channel_id' => $channelId, 'user_id' => $userId]);
    }
    json_response(200, ['ok' => true, 'subscribed' => $action === 'subscribe']);
} catch (Throwable $error) {
    error_log('PlusChat channel subscription error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
