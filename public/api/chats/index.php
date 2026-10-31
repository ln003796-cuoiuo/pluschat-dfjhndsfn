<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$userId = authenticated_user_id();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    try {
        $sql = "SELECT c.id, c.kind, c.title, c.age_group, c.created_at,
            (SELECT u.display_name FROM conversation_members cm JOIN users u ON u.id = cm.user_id WHERE cm.conversation_id = c.id AND cm.user_id <> :peer_user_id ORDER BY cm.joined_at LIMIT 1) AS peer_name,
            (SELECT u.username FROM conversation_members cm JOIN users u ON u.id = cm.user_id WHERE cm.conversation_id = c.id AND cm.user_id <> :peer_user_id_2 ORDER BY cm.joined_at LIMIT 1) AS peer_username,
            (SELECT u.id FROM conversation_members cm JOIN users u ON u.id = cm.user_id WHERE cm.conversation_id = c.id AND cm.user_id <> :peer_user_id_3 ORDER BY cm.joined_at LIMIT 1) AS peer_id,
            (SELECT u.avatar_path FROM conversation_members cm JOIN users u ON u.id = cm.user_id WHERE cm.conversation_id = c.id AND cm.user_id <> :peer_user_id_4 ORDER BY cm.joined_at LIMIT 1) AS peer_avatar_path,
            (SELECT m.body FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
            (SELECT m.message_type FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message_type,
            (SELECT m.created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message_at,
            (SELECT COUNT(*) FROM messages unread WHERE unread.conversation_id = c.id AND unread.id > COALESCE(mine.last_read_message_id, 0) AND unread.sender_id <> mine.user_id) AS unread_count
            FROM conversations c JOIN conversation_members mine ON mine.conversation_id = c.id AND mine.user_id = :member_user_id
            ORDER BY COALESCE(last_message_at, c.created_at) DESC LIMIT 100";
        $query = database()->prepare($sql);
        $query->execute(['peer_user_id' => $userId, 'peer_user_id_2' => $userId, 'peer_user_id_3' => $userId, 'peer_user_id_4' => $userId, 'member_user_id' => $userId]);
        $chats = $query->fetchAll();
        foreach ($chats as &$chat) {
            $chat['unread_count'] = (int) $chat['unread_count'];
            if ($chat['kind'] === 'direct' && $chat['peer_id'] !== null) {
                $chat['peer_id'] = (int) $chat['peer_id'];
                if ($chat['peer_avatar_path']) $chat['peer_avatar_url'] = '/api/users/avatar.php?id=' . $chat['peer_id'];
            }
            unset($chat['peer_avatar_path']);
        }
        unset($chat);
        json_response(200, ['chats' => $chats]);
    } catch (Throwable $error) {
        error_log('PlusChat chat list error: ' . $error->getMessage());
        json_response(500, ['error' => 'server_error']);
    }
}

require_post();
$data = request_json();
$kind = ($data['kind'] ?? '') === 'group' ? 'group' : 'direct';
$title = trim((string) ($data['title'] ?? ''));
$usernames = array_values(array_unique(array_filter(array_map(
    static fn($value) => strtolower(trim((string) $value)),
    is_array($data['usernames'] ?? null) ? $data['usernames'] : []
))));
$unfilteredNames = is_array($data['usernames'] ?? null) ? $data['usernames'] : [];
$usernames = array_values(array_filter($usernames, static fn($name) => preg_match('/^[a-z0-9_]{3,24}$/', $name)));
if (count($usernames) !== count(array_unique(array_map(static fn($value) => strtolower(trim((string) $value)), $unfilteredNames)))) {
    json_response(422, ['error' => 'invalid_usernames']);
}
if ($kind === 'direct' && count($usernames) !== 1) {
    json_response(422, ['error' => 'one_username_required']);
}
if ($kind === 'group' && (count($usernames) < 1 || count($usernames) > 49 || $title === '' || utf8_length($title) > 100)) {
    json_response(422, ['error' => 'invalid_group']);
}

try {
    $pdo = database();
    $placeholders = implode(',', array_fill(0, count($usernames), '?'));
    $find = $pdo->prepare("SELECT id, username, display_name, avatar_path, age_years FROM users WHERE username IN ({$placeholders})");
    $find->execute($usernames);
    $targets = $find->fetchAll();
    if (count($targets) !== count($usernames) || array_filter($targets, static fn($target) => (int) $target['id'] === $userId)) {
        json_response(404, ['error' => 'user_not_found']);
    }
    $participantIds = array_merge([$userId], array_map(static fn($target) => (int) $target['id'], $targets));
    sort($participantIds, SORT_NUMERIC);
    $pdo->beginTransaction();
    $participantPlaceholders = implode(',', array_fill(0, count($participantIds), '?'));
    $lockParticipants = $pdo->prepare("SELECT id, age_years FROM users WHERE id IN ({$participantPlaceholders}) ORDER BY id FOR UPDATE");
    $lockParticipants->execute($participantIds);
    $lockedProfiles = [];
    foreach ($lockParticipants->fetchAll() as $lockedProfile) {
        $lockedProfiles[(int) $lockedProfile['id']] = $lockedProfile['age_years'] === null ? null : (int) $lockedProfile['age_years'];
    }
    if (count($lockedProfiles) !== count($participantIds)) {
        $pdo->rollBack();
        json_response(404, ['error' => 'user_not_found']);
    }
    foreach ($targets as &$target) {
        $target['age_years'] = $lockedProfiles[(int) $target['id']];
    }
    unset($target);
    $myAgeGroup = age_group($lockedProfiles[$userId]);
    foreach ($targets as $target) {
        $targetAgeGroup = age_group($target['age_years'] === null ? null : (int) $target['age_years']);
        $knownMinorAdultPair = $myAgeGroup !== 'unknown' && $targetAgeGroup !== 'unknown'
            && (($myAgeGroup === 'adult') !== ($targetAgeGroup === 'adult'));
        if ($knownMinorAdultPair) {
            $pdo->rollBack();
            json_response(403, ['error' => 'age_group_restricted']);
        }
    }
    $allKnownGroups = array_merge([$myAgeGroup], array_map(
        static fn($target) => age_group($target['age_years'] === null ? null : (int) $target['age_years']),
        $targets
    ));
    if (in_array('adult', $allKnownGroups, true)
        && (in_array('under_16', $allKnownGroups, true) || in_array('16_17', $allKnownGroups, true))) {
        $pdo->rollBack();
        json_response(403, ['error' => 'age_group_restricted']);
    }

    if ($kind === 'direct') {
        $targetId = (int) $targets[0]['id'];
        $existing = $pdo->prepare("SELECT c.id FROM conversations c
            JOIN conversation_members a ON a.conversation_id = c.id AND a.user_id = :me
            JOIN conversation_members b ON b.conversation_id = c.id AND b.user_id = :target
            WHERE c.kind = 'direct' LIMIT 1");
        $existing->execute(['me' => $userId, 'target' => $targetId]);
        $existingId = $existing->fetchColumn();
        if ($existingId) {
            $pdo->commit();
            $peer = $targets[0];
            json_response(200, [
                'conversationId' => (int) $existingId,
                'peer_name' => $peer['display_name'],
                'peer_username' => $peer['username'],
                'peer_avatar_url' => $peer['avatar_path'] ? '/api/users/avatar.php?id=' . (int) $peer['id'] : null,
            ]);
        }
    }

    $create = $pdo->prepare('INSERT INTO conversations (kind, title, created_by) VALUES (:kind, :title, :creator)');
    $create->execute(['kind' => $kind, 'title' => $kind === 'group' ? $title : null, 'creator' => $userId]);
    $conversationId = (int) $pdo->lastInsertId();
    $member = $pdo->prepare('INSERT INTO conversation_members (conversation_id, user_id, role) VALUES (:conversation_id, :user_id, :role)');
    $member->execute(['conversation_id' => $conversationId, 'user_id' => $userId, 'role' => 'owner']);
    foreach ($targets as $target) {
        $member->execute(['conversation_id' => $conversationId, 'user_id' => (int) $target['id'], 'role' => 'member']);
    }
    $pdo->commit();
    if ($kind === 'direct') {
        $peer = $targets[0];
        json_response(201, [
            'conversationId' => $conversationId,
            'peer_name' => $peer['display_name'],
            'peer_username' => $peer['username'],
            'peer_avatar_url' => $peer['avatar_path'] ? '/api/users/avatar.php?id=' . (int) $peer['id'] : null,
        ]);
    }
    json_response(201, ['conversationId' => $conversationId]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('PlusChat create chat error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
