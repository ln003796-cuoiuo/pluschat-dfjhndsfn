<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

require_post();
start_app_session();
$data = request_json();
$email = normalize_email_address((string) ($data['email'] ?? ''));
$password = (string) ($data['password'] ?? '');

if ($email === null) {
    json_response(422, ['error' => 'invalid_email']);
}
if ($password === '' || strlen($password) > 72) {
    json_response(422, ['error' => 'invalid_credentials']);
}

try {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ipWait = consume_rate_limit('login-ip', $ip, 30, 900);
    $accountWait = consume_rate_limit('login-account', $ip . "\0" . $email, 10, 900);
    $retryAfter = max($ipWait, $accountWait);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        json_response(429, ['error' => 'too_many_attempts', 'retryAfter' => $retryAfter]);
    }
    $query = database()->prepare('SELECT id, email, username, password_hash, session_version, display_name, age_years FROM users WHERE email = :email AND email_verified_at IS NOT NULL LIMIT 1');
    $query->execute(['email' => $email]);
    $user = $query->fetch();
} catch (Throwable $error) {
    error_log('PlusChat login database error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}

if (!$user || !password_verify($password, $user['password_hash'])) {
    json_response(401, ['error' => 'invalid_credentials']);
}

if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    try {
        $rehash = database()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $rehash->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
    } catch (Throwable $error) {
        error_log('PlusChat password rehash error: ' . $error->getMessage());
    }
}

try {
    create_persistent_device_session((int) $user['id'], (int) $user['session_version']);
} catch (Throwable $error) {
    error_log('PlusChat device session error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}

json_response(200, [
    'user' => [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'username' => $user['username'],
        'displayName' => $user['display_name'],
        'ageGroup' => age_group($user['age_years'] === null ? null : (int) $user['age_years']),
    ],
]);
