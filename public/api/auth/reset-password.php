<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

require_post();
$data = request_json();
$email = normalize_email_address((string) ($data['email'] ?? ''));
$code = trim((string) ($data['code'] ?? ''));
$password = (string) ($data['password'] ?? '');
if ($email === null || !preg_match('/^\d{6}$/', $code)) json_response(422, ['error' => 'invalid_or_expired_code']);
if (strlen($password) < 8 || strlen($password) > 72 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)
    || !preg_match('/\d/', $password) || !preg_match('/[^\p{L}\p{N}]/u', $password)) json_response(422, ['error' => 'password_policy']);

try {
    $pdo = database();
    $pdo->beginTransaction();
    $query = $pdo->prepare('SELECT code_hash, (expires_at > NOW()) AS is_active, attempts FROM password_reset_codes WHERE email = :email LIMIT 1 FOR UPDATE');
    $query->execute(['email' => $email]);
    $reset = $query->fetch();
    if (!$reset || !(bool) $reset['is_active'] || (int) $reset['attempts'] >= 5) {
        $pdo->rollBack();
        json_response(422, ['error' => 'invalid_or_expired_code']);
    }
    if (!password_verify($code, $reset['code_hash'])) {
        $pdo->prepare('UPDATE password_reset_codes SET attempts = attempts + 1 WHERE email = :email')->execute(['email' => $email]);
        $pdo->commit();
        json_response(422, ['error' => 'invalid_or_expired_code']);
    }
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $update = $pdo->prepare('UPDATE users SET password_hash = :hash, session_version = session_version + 1 WHERE email = :email AND email_verified_at IS NOT NULL');
    $update->execute(['hash' => $passwordHash, 'email' => $email]);
    if ($update->rowCount() !== 1) {
        $pdo->rollBack();
        json_response(422, ['error' => 'invalid_or_expired_code']);
    }
    $userIdQuery = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $userIdQuery->execute(['email' => $email]);
    $userId = (int) $userIdQuery->fetchColumn();
    $pdo->prepare('UPDATE user_devices SET revoked_at = NOW() WHERE user_id = :user_id AND revoked_at IS NULL')->execute(['user_id' => $userId]);
    $pdo->prepare('DELETE FROM password_reset_codes WHERE email = :email')->execute(['email' => $email]);
    $pdo->commit();
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('PlusChat password reset error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}

json_response(200, ['ok' => true]);
