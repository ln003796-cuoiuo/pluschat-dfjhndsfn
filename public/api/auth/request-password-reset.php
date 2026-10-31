<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/Mailer.php';

require_post();
$data = request_json();
$email = normalize_email_address((string) ($data['email'] ?? ''));
if ($email === null) json_response(422, ['error' => 'invalid_email']);
try {
    $pdo = database();
    $ipWait = consume_rate_limit('password-reset-ip', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 3600);
    $emailWait = consume_rate_limit('password-reset-email', $email, 5, 3600);
    if ($ipWait > 0) {
        header('Retry-After: ' . $ipWait);
        json_response(429, ['error' => 'too_many_attempts', 'retryAfter' => $ipWait]);
    }
    if ($emailWait > 0) {
        // Preserve the same response for registered and unknown addresses.
        json_response(200, ['ok' => true]);
    }
    $user = $pdo->prepare('SELECT id FROM users WHERE email = :email AND email_verified_at IS NOT NULL LIMIT 1');
    $user->execute(['email' => $email]);
    if ($user->fetchColumn()) {
        $previous = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) FROM password_reset_codes WHERE email = :email LIMIT 1');
        $previous->execute(['email' => $email]);
        $seconds = $previous->fetchColumn();
        if ($seconds === false || (int) $seconds >= 60) {
            $code = (string) random_int(100000, 999999);
            $save = $pdo->prepare('INSERT INTO password_reset_codes (email, code_hash, expires_at, attempts, last_sent_at) VALUES (:email, :hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, NOW()) ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), expires_at = VALUES(expires_at), attempts = 0, last_sent_at = NOW()');
            $save->execute(['email' => $email, 'hash' => password_hash($code, PASSWORD_DEFAULT)]);
            if (!send_verification_code($email, $code, 'password_reset')) {
                $pdo->prepare('DELETE FROM password_reset_codes WHERE email = :email')->execute(['email' => $email]);
            }
        }
    }
} catch (Throwable $error) {
    error_log('PlusChat password reset request error: ' . $error->getMessage());
}
// Keep the response identical whether the address exists or not.
json_response(200, ['ok' => true]);
