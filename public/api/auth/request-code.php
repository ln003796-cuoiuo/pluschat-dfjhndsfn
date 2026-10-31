<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/Mailer.php';

require_post();
start_app_session();
$data = request_json();
$email = normalize_email_address((string) ($data['email'] ?? ''));
if ($email === null) {
    json_response(422, ['error' => 'invalid_email']);
}

try {
    $pdo = database();
    $retryAfter = max(
        consume_rate_limit('registration-code-ip', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 3600),
        consume_rate_limit('registration-code-email', $email, 5, 3600)
    );
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        json_response(429, ['error' => 'too_many_attempts', 'retryAfter' => $retryAfter]);
    }
    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $check->execute(['email' => $email]);
    if ($check->fetch()) {
        json_response(409, ['error' => 'email_already_registered']);
    }

    $existing = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) AS seconds_since_send FROM email_verification_codes WHERE email = :email LIMIT 1');
    $existing->execute(['email' => $email]);
    $previous = $existing->fetch();
    if ($previous && (int) $previous['seconds_since_send'] < 60) {
        json_response(429, ['error' => 'please_wait_before_resending']);
    }

    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $save = $pdo->prepare(
        'INSERT INTO email_verification_codes (email, code_hash, expires_at, verified_at, attempts, last_sent_at) '
        . 'VALUES (:email, :hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NULL, 0, NOW()) '
        . 'ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), expires_at = VALUES(expires_at), verified_at = NULL, attempts = 0, last_sent_at = NOW()'
    );
    $save->execute(['email' => $email, 'hash' => $hash]);
    if (!send_verification_code($email, $code)) {
        $pdo->prepare('DELETE FROM email_verification_codes WHERE email = :email')->execute(['email' => $email]);
        json_response(503, ['error' => 'mail_unavailable']);
    }
} catch (Throwable $error) {
    error_log('PlusChat registration code error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}

json_response(200, ['ok' => true, 'expiresIn' => 600]);
