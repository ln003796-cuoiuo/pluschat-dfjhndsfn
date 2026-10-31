<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$userId = authenticated_user_id();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
try {
    $pdo = database();
    if ($method === 'GET') {
        $query = $pdo->prepare('SELECT display_name, bio, age_years, location_city, (location_enabled_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS location_fresh, avatar_path FROM users WHERE id = :id LIMIT 1');
        $query->execute(['id' => $userId]);
        $profile = $query->fetch();
        if (!$profile) json_response(404, ['error' => 'profile_not_found']);
        $group = age_group($profile['age_years'] === null ? null : (int) $profile['age_years']);
        json_response(200, [
            'displayName' => $profile['display_name'],
            'bio' => $profile['bio'],
            'ageYears' => $profile['age_years'] === null ? null : (int) $profile['age_years'],
            'ageGroup' => $group,
            'ageGroupLabel' => age_group_label($group),
            'locationCity' => $profile['location_city'],
            'locationEnabled' => (bool) $profile['location_fresh'],
            'avatarUrl' => $profile['avatar_path'] ? '/api/users/avatar.php?id=' . $userId . '&v=' . time() : null,
        ]);
    }
    require_post();
    $data = request_json();
    $displayName = trim((string) ($data['displayName'] ?? ''));
    $bio = trim((string) ($data['bio'] ?? ''));
    $ageInput = $data['ageYears'] ?? null;
    if ($displayName === '' || utf8_length($displayName) > 80 || utf8_length($bio) > 280) json_response(422, ['error' => 'invalid_profile']);
    $ageYears = null;
    if ($ageInput !== null && $ageInput !== '') {
        $ageYears = filter_var($ageInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
        if ($ageYears === false) json_response(422, ['error' => 'invalid_age']);
    }
    $pdo->beginTransaction();
    $previousAge = $pdo->prepare('SELECT age_years FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
    $previousAge->execute(['id' => $userId]);
    $storedAge = $previousAge->fetchColumn();
    if ($storedAge === false) {
        $pdo->rollBack();
        json_response(404, ['error' => 'profile_not_found']);
    }
    $previousAgeGroup = age_group($storedAge === null ? null : (int) $storedAge);
    $update = $pdo->prepare('UPDATE users SET display_name = :name, bio = :bio, age_years = :age_years WHERE id = :id');
    $update->execute(['name' => $displayName, 'bio' => $bio ?: null, 'age_years' => $ageYears, 'id' => $userId]);
    $group = age_group($ageYears === null ? null : (int) $ageYears);

    $membershipQuery = $pdo->prepare("SELECT c.id AS conversation_id, u.age_years
        FROM conversations c
        JOIN conversation_members cm ON cm.conversation_id = c.id
        JOIN users u ON u.id = cm.user_id
        WHERE c.kind IN ('direct', 'group')
            AND EXISTS (SELECT 1 FROM conversation_members mine WHERE mine.conversation_id = c.id AND mine.user_id = :user_id)
        ORDER BY c.id, u.id");
    $membershipQuery->execute(['user_id' => $userId]);
    $conversationGroups = [];
    foreach ($membershipQuery->fetchAll() as $member) {
        $conversationId = (int) $member['conversation_id'];
        $conversationGroups[$conversationId][] = age_group($member['age_years'] === null ? null : (int) $member['age_years']);
    }
    $conflictingIds = [];
    foreach ($conversationGroups as $conversationId => $groups) {
        if (in_array('adult', $groups, true)
            && (in_array('under_16', $groups, true) || in_array('16_17', $groups, true))) {
            $conflictingIds[] = (int) $conversationId;
        }
    }
    $removedFromChats = 0;
    $removedConversationIds = [];
    foreach ($conflictingIds as $conversationId) {
        if (remove_conversation_member($pdo, $conversationId, $userId)) {
            $removedFromChats++;
            $removedConversationIds[] = $conversationId;
        }
    }
    $removedFromLocalChats = 0;
    if ($group !== $previousAgeGroup) {
        $removeLocal = $pdo->prepare("DELETE cm FROM conversation_members cm JOIN conversations c ON c.id = cm.conversation_id WHERE cm.user_id = :user_id AND c.kind = 'local'");
        $removeLocal->execute(['user_id' => $userId]);
        $removedFromLocalChats = $removeLocal->rowCount();
    }
    $pdo->commit();
    json_response(200, [
        'ok' => true,
        'ageGroup' => $group,
        'ageGroupLabel' => age_group_label($group),
        'removedFromChats' => $removedFromChats,
        'removedConversationIds' => $removedConversationIds,
        'removedFromLocalChats' => $removedFromLocalChats,
    ]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('PlusChat profile error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
