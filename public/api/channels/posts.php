<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

require_post();
$userId = authenticated_user_id();
$data = request_json();
$channelId = filter_var($data['channel_id'] ?? null, FILTER_VALIDATE_INT);
$body = trim((string) ($data['body'] ?? ''));
if (!$channelId || $channelId < 1 || $body === '' || utf8_length($body) > 5000) {
    json_response(422, ['error' => 'invalid_post']);
}

try {
    $pdo = database();
    $owner = $pdo->prepare('SELECT id FROM channels WHERE id = :channel_id AND owner_user_id = :user_id LIMIT 1');
    $owner->execute(['channel_id' => $channelId, 'user_id' => $userId]);
    if (!$owner->fetchColumn()) json_response(404, ['error' => 'channel_not_found']);
    $insert = $pdo->prepare(
        "INSERT INTO channel_posts (channel_id, author_user_id, post_type, body)
         VALUES (:channel_id, :author_id, 'text', :body)"
    );
    $insert->execute(['channel_id' => $channelId, 'author_id' => $userId, 'body' => $body]);
    json_response(201, ['ok' => true, 'post_id' => (int) $pdo->lastInsertId()]);
} catch (Throwable $error) {
    error_log('PlusChat channel post error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
