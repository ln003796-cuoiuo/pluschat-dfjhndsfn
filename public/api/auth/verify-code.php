<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

require_post();
start_app_session();
$data = request_json();
$email = normalize_email_address((string) ($data['email'] ?? ''));
$code = trim((string) ($data['code'] ?? ''));
if ($email === null || !preg_match('/^\d{6}$/', $code)) {
    json_response(422, ['error' => 'invalid_code']);
}

try {
    $pdo = database();
    $pdo->beginTransaction();
    $query = $pdo->prepare('SELECT code_hash, (expires_at > NOW()) AS is_active, attempts FROM email_verification_codes WHERE email = :email LIMIT 1 FOR UPDATE');
    $query->execute(['email' => $email]);
    $verification = $query->fetch();
    if (!$verification || !(bool) $verification['is_active'] || (int) $verification['attempts'] >= 5) {
        $pdo->rollBack();
        json_response(422, ['error' => 'invalid_or_expired_code']);
    }
    if (!password_verify($code, $verification['code_hash'])) {
        $wrong = $pdo->prepare('UPDATE email_verification_codes SET attempts = attempts + 1 WHERE email = :email');
        $wrong->execute(['email' => $email]);
        $pdo->commit();
        json_response(422, ['error' => 'invalid_code']);
    }

    $mark = $pdo->prepare('UPDATE email_verification_codes SET verified_at = NOW() WHERE email = :email');
    $mark->execute(['email' => $email]);
    $pdo->commit();
    if (!session_regenerate_id(true)) {
        json_response(500, ['error' => 'server_error']);
    }
    $_SESSION['verified_registration_email'] = $email;
    $_SESSION['verified_registration_at'] = time();
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('PlusChat code verification error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}

json_response(200, ['ok' => true]);
