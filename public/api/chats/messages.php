<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$userId = authenticated_user_id();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    json_response(405, ['error' => 'method_not_allowed']);
}
$conversationId = filter_input(INPUT_GET, 'conversation_id', FILTER_VALIDATE_INT);
$requestData = null;
if ((!$conversationId || $conversationId < 1) && $method === 'POST') {
    $requestData = request_json();
    $conversationId = (int) ($requestData['conversationId'] ?? 0);
}
if ($conversationId < 1) json_response(422, ['error' => 'invalid_conversation']);

try {
    $pdo = database();
    $kindHintQuery = $pdo->prepare('SELECT kind FROM conversations WHERE id = :id LIMIT 1');
    $kindHintQuery->execute(['id' => $conversationId]);
    $kindHint = $kindHintQuery->fetchColumn();
    if ($kindHint === false) json_response(404, ['error' => 'conversation_not_found']);

    $pdo->beginTransaction();
    $participantIds = [$userId];
    if ($kindHint !== 'local') {
        $participantQuery = $pdo->prepare('SELECT user_id FROM conversation_members WHERE conversation_id = :conversation_id ORDER BY user_id');
        $participantQuery->execute(['conversation_id' => $conversationId]);
        $participantIds = array_map('intval', $participantQuery->fetchAll(PDO::FETCH_COLUMN));
        $participantIds[] = $userId;
    }
    $participantIds = array_values(array_unique($participantIds));
    sort($participantIds, SORT_NUMERIC);
    $userPlaceholders = implode(',', array_fill(0, count($participantIds), '?'));
    $lockUsers = $pdo->prepare("SELECT id FROM users WHERE id IN ({$userPlaceholders}) ORDER BY id FOR UPDATE");
    $lockUsers->execute($participantIds);

    $conversationQuery = $pdo->prepare('SELECT kind, age_group, city_code FROM conversations WHERE id = :id LIMIT 1 FOR UPDATE');
    $conversationQuery->execute(['id' => $conversationId]);
    $conversation = $conversationQuery->fetch();
    if (!$conversation) {
        $pdo->rollBack();
        json_response(404, ['error' => 'conversation_not_found']);
    }
    $memberCheck = $pdo->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = :conversation_id AND user_id = :user_id LIMIT 1 FOR UPDATE');
    $memberCheck->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
    if (!$memberCheck->fetchColumn()) {
        $pdo->rollBack();
        json_response(404, ['error' => 'conversation_not_found']);
    }
    if ($conversation['kind'] === 'local') {
        $localQuery = $pdo->prepare('SELECT age_years, location_cell, location_enabled_at, (location_enabled_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS location_fresh FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
        $localQuery->execute(['id' => $userId]);
        $localProfile = $localQuery->fetch();
        $age = $localProfile['age_years'] ?? null;
        if (!$localProfile || !$localProfile['location_cell'] || !$localProfile['location_fresh']
            || !hash_equals((string) $conversation['city_code'], (string) $localProfile['location_cell'])) {
            $pdo->prepare('DELETE FROM conversation_members WHERE conversation_id = :conversation_id AND user_id = :user_id')->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
            $pdo->commit();
            json_response(403, ['error' => 'local_access_disabled']);
        }
        if (!hash_equals((string) $conversation['age_group'], age_group($age === null ? null : (int) $age))) {
            $pdo->prepare('DELETE FROM conversation_members WHERE conversation_id = :conversation_id AND user_id = :user_id')->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
            $pdo->commit();
            json_response(403, ['error' => 'age_group_changed']);
        }
    } else {
        $ages = $pdo->prepare('SELECT u.age_years FROM conversation_members cm JOIN users u ON u.id = cm.user_id WHERE cm.conversation_id = :id ORDER BY u.id FOR UPDATE');
        $ages->execute(['id' => $conversationId]);
        $groups = array_map(static fn($age) => age_group($age === null ? null : (int) $age), $ages->fetchAll(PDO::FETCH_COLUMN));
        if (in_array('adult', $groups, true)
            && (in_array('under_16', $groups, true) || in_array('16_17', $groups, true))) {
            $membershipRemoved = remove_conversation_member($pdo, $conversationId, $userId);
            $pdo->commit();
            json_response(403, ['error' => 'age_group_restricted', 'membershipRemoved' => $membershipRemoved]);
        }
    }

    if ($method === 'GET') {
        $afterId = max(0, (int) ($_GET['after_id'] ?? 0));
        $beforeId = max(0, (int) ($_GET['before_id'] ?? 0));
        if ($beforeId > 0) {
            $query = $pdo->prepare('SELECT m.id, m.sender_id, u.username, u.display_name, u.avatar_path AS sender_avatar_path, m.message_type, m.body, m.sticker_id, m.media_url, s.alt_text AS sticker_alt_text, m.created_at FROM messages m JOIN users u ON u.id = m.sender_id LEFT JOIN stickers s ON s.id = m.sticker_id WHERE m.conversation_id = :conversation_id AND m.id < :before_id ORDER BY m.id DESC LIMIT 100');
            $query->execute(['conversation_id' => $conversationId, 'before_id' => $beforeId]);
            $messages = array_reverse($query->fetchAll());
        } elseif ($afterId > 0) {
            $query = $pdo->prepare('SELECT m.id, m.sender_id, u.username, u.display_name, u.avatar_path AS sender_avatar_path, m.message_type, m.body, m.sticker_id, m.media_url, s.alt_text AS sticker_alt_text, m.created_at FROM messages m JOIN users u ON u.id = m.sender_id LEFT JOIN stickers s ON s.id = m.sticker_id WHERE m.conversation_id = :conversation_id AND m.id > :after_id ORDER BY m.id ASC LIMIT 100');
            $query->execute(['conversation_id' => $conversationId, 'after_id' => $afterId]);
            $messages = $query->fetchAll();
        } else {
            $query = $pdo->prepare('SELECT m.id, m.sender_id, u.username, u.display_name, u.avatar_path AS sender_avatar_path, m.message_type, m.body, m.sticker_id, m.media_url, s.alt_text AS sticker_alt_text, m.created_at FROM messages m JOIN users u ON u.id = m.sender_id LEFT JOIN stickers s ON s.id = m.sticker_id WHERE m.conversation_id = :conversation_id ORDER BY m.id DESC LIMIT 100');
            $query->execute(['conversation_id' => $conversationId]);
            $messages = array_reverse($query->fetchAll());
        }
        if ($messages) {
            $latestMessageId = (int) $messages[count($messages) - 1]['id'];
            $markRead = $pdo->prepare('UPDATE conversation_members SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), :message_id) WHERE conversation_id = :conversation_id AND user_id = :user_id');
            $markRead->execute(['message_id' => $latestMessageId, 'conversation_id' => $conversationId, 'user_id' => $userId]);
        }
        foreach ($messages as &$message) {
            if ($message['sender_avatar_path']) $message['avatar_url'] = '/api/users/avatar.php?id=' . (int) $message['sender_id'];
            unset($message['sender_avatar_path']);
        }
        unset($message);
        $pdo->commit();
        json_response(200, ['messages' => $messages]);
    }

    require_post();
    $data = $requestData ?? request_json();
    $type = (string) ($data['type'] ?? 'text');
    $enforceSendLimit = static function () use ($userId, $pdo): void {
        $retryAfter = consume_rate_limit('message-send', (string) $userId, 120, 60);
        if ($retryAfter > 0) {
            header('Retry-After: ' . $retryAfter);
            if ($pdo->inTransaction()) $pdo->commit();
            json_response(429, ['error' => 'too_many_messages', 'retryAfter' => $retryAfter]);
        }
    };
    if ($type === 'text') {
        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '' || utf8_length($body) > 5000) json_response(422, ['error' => 'invalid_message']);
        $enforceSendLimit();
        $insert = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_type, body) VALUES (:conversation_id, :sender_id, 'text', :body)");
        $insert->execute(['conversation_id' => $conversationId, 'sender_id' => $userId, 'body' => $body]);
    } elseif ($type === 'gif') {
        $url = trim((string) ($data['url'] ?? ''));
        $host = parse_url($url, PHP_URL_HOST);
        if (strlen($url) > 1024 || parse_url($url, PHP_URL_SCHEME) !== 'https' || !$host || !preg_match('/(^|\.)giphy\.com$/i', $host)) json_response(422, ['error' => 'invalid_gif']);
        $enforceSendLimit();
        $insert = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_type, media_url) VALUES (:conversation_id, :sender_id, 'gif', :url)");
        $insert->execute(['conversation_id' => $conversationId, 'sender_id' => $userId, 'url' => $url]);
    } elseif ($type === 'sticker') {
        $stickerId = (int) ($data['stickerId'] ?? 0);
        $stickerTransactionStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $stickerTransactionStarted = true;
        }
        $canUse = $pdo->prepare('SELECT 1 FROM stickers s JOIN sticker_packs p ON p.id = s.pack_id LEFT JOIN user_sticker_packs i ON i.pack_id = p.id AND i.user_id = :user_id WHERE s.id = :sticker_id AND (p.owner_user_id = :owner_id OR i.user_id IS NOT NULL) LIMIT 1 FOR UPDATE');
        $canUse->execute(['user_id' => $userId, 'sticker_id' => $stickerId, 'owner_id' => $userId]);
        if (!$canUse->fetchColumn()) {
            if ($stickerTransactionStarted) $pdo->rollBack();
            json_response(404, ['error' => 'sticker_not_found']);
        }
        $enforceSendLimit();
        $insert = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_type, sticker_id) VALUES (:conversation_id, :sender_id, 'sticker', :sticker_id)");
        $insert->execute(['conversation_id' => $conversationId, 'sender_id' => $userId, 'sticker_id' => $stickerId]);
        if ($stickerTransactionStarted) $pdo->commit();
    } else {
        json_response(422, ['error' => 'invalid_message_type']);
    }
    if ($pdo->inTransaction()) $pdo->commit();
    json_response(201, ['id' => (int) $pdo->lastInsertId()]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('PlusChat messages error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
