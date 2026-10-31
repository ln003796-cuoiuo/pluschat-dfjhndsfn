<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$userId = authenticated_user_id();
require_post();
try {
    $pdo = database();
    $pdo->beginTransaction();
    $user = $pdo->prepare('SELECT display_name, age_years, location_city, location_enabled_at, (location_enabled_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS location_fresh, location_cell FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
    $user->execute(['id' => $userId]);
    $profile = $user->fetch();
    if (!$profile) {
        $pdo->rollBack();
        json_response(404, ['error' => 'profile_not_found']);
    }
    if (!$profile['location_cell'] || !$profile['location_fresh']) {
        $pdo->rollBack();
        json_response(403, ['error' => 'location_required']);
    }
    $group = age_group($profile['age_years'] === null ? null : (int) $profile['age_years']);
    $cityTitle = utf8_length((string) $profile['location_city']) <= 92 ? (string) $profile['location_city'] : null;
    $title = $cityTitle === null ? 'Местный чат' : 'Рядом · ' . $cityTitle;
    $find = $pdo->prepare("SELECT id FROM conversations WHERE kind = 'local' AND city_code = :cell AND age_group = :age_group LIMIT 1");
    $find->execute(['cell' => $profile['location_cell'], 'age_group' => $group]);
    $conversationId = $find->fetchColumn();
    if (!$conversationId) {
        $create = $pdo->prepare("INSERT IGNORE INTO conversations (kind, title, city_code, age_group, created_by) VALUES ('local', :title, :cell, :age_group, :creator)");
        $create->execute(['title' => $title, 'cell' => $profile['location_cell'], 'age_group' => $group, 'creator' => $userId]);
        $find->execute(['cell' => $profile['location_cell'], 'age_group' => $group]);
        $conversationId = $find->fetchColumn();
    }
    $join = $pdo->prepare("INSERT IGNORE INTO conversation_members (conversation_id, user_id, role) VALUES (:conversation_id, :user_id, 'member')");
    $join->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
    $pdo->commit();
    json_response(200, ['conversationId' => (int) $conversationId, 'title' => $title, 'ageGroup' => $group, 'ageGroupLabel' => age_group_label($group)]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('PlusChat local chat error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
