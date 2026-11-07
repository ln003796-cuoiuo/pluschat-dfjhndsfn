<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$userId = authenticated_user_id();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    $pdo = database();
    if ($method === 'GET') {
        $query = $pdo->prepare(
            "SELECT c.id, c.slug, c.title, c.description, c.owner_user_id,
                    (c.owner_user_id = :owner_id) AS is_owner,
                    EXISTS(
                        SELECT 1 FROM channel_subscriptions mine
                        WHERE mine.channel_id = c.id AND mine.user_id = :subscriber_id
                    ) AS is_subscribed,
                    (SELECT COUNT(*) FROM channel_subscriptions totals
                        WHERE totals.channel_id = c.id) AS subscriber_count
             FROM channels c
             WHERE c.visibility = 'public' OR c.owner_user_id = :visible_owner
             ORDER BY is_subscribed DESC, c.updated_at DESC
             LIMIT 100"
        );
        $query->execute(['owner_id' => $userId, 'subscriber_id' => $userId, 'visible_owner' => $userId]);
        $channels = $query->fetchAll();
        foreach ($channels as &$channel) {
            $channel['id'] = (int) $channel['id'];
            $channel['owner_user_id'] = (int) $channel['owner_user_id'];
            $channel['is_owner'] = (bool) $channel['is_owner'];
            $channel['is_subscribed'] = (bool) $channel['is_subscribed'];
            $channel['subscriber_count'] = (int) $channel['subscriber_count'];
        }
        unset($channel);
        json_response(200, ['channels' => $channels]);
    }

    require_post();
    $data = request_json();
    $title = trim((string) ($data['title'] ?? ''));
    $slug = strtolower(trim((string) ($data['slug'] ?? '')));
    $description = trim((string) ($data['description'] ?? ''));
    if ($title === '' || utf8_length($title) > 100
        || !preg_match('/^[a-z0-9][a-z0-9_-]{2,47}$/', $slug)
        || utf8_length($description) > 500) {
        json_response(422, ['error' => 'invalid_channel']);
    }
    $insert = $pdo->prepare(
        "INSERT INTO channels (owner_user_id, slug, title, description, visibility)
         VALUES (:owner_id, :slug, :title, :description, 'public')"
    );
    $insert->execute([
        'owner_id' => $userId,
        'slug' => $slug,
        'title' => $title,
        'description' => $description !== '' ? $description : null,
    ]);
    $channelId = (int) $pdo->lastInsertId();
    $subscribe = $pdo->prepare(
        'INSERT INTO channel_subscriptions (channel_id, user_id) VALUES (:channel_id, :user_id)'
    );
    $subscribe->execute(['channel_id' => $channelId, 'user_id' => $userId]);
    json_response(201, ['ok' => true, 'channel_id' => $channelId]);
} catch (PDOException $error) {
    if ($error->getCode() === '23000') json_response(409, ['error' => 'channel_slug_taken']);
    error_log('PlusChat channel index error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
} catch (Throwable $error) {
    error_log('PlusChat channel index error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
