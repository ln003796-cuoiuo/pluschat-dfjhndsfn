<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$userId = authenticated_user_id();

try {
    $query = database()->prepare('SELECT id, email, username, display_name, bio, avatar_path, age_years FROM users WHERE id = :id LIMIT 1');
    $query->execute(['id' => $userId]);
    $user = $query->fetch();
} catch (Throwable $error) {
    error_log('PlusChat session database error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}

if (!$user) {
    $_SESSION = [];
    session_destroy();
    json_response(401, ['error' => 'not_authenticated']);
}

json_response(200, ['user' => [
    'id' => (int) $user['id'],
    'email' => $user['email'],
    'username' => $user['username'],
    'displayName' => $user['display_name'],
    'bio' => $user['bio'],
    'ageGroup' => age_group($user['age_years'] === null ? null : (int) $user['age_years']),
    'avatarUrl' => $user['avatar_path'] ? '/api/users/avatar.php?id=' . (int) $user['id'] : null,
]]);
