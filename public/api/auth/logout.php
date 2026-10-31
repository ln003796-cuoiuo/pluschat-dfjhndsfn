<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

require_post();
start_app_session();
$deviceToken = (string) ($_COOKIE['pluschat_device'] ?? '');
if (preg_match('/^[a-f0-9]{64}$/', $deviceToken) === 1) {
    try {
        $pdo = database();
        $pdo->prepare('UPDATE user_devices SET revoked_at = NOW() WHERE token_hash = :token_hash AND revoked_at IS NULL')
            ->execute(['token_hash' => hash('sha256', $deviceToken)]);
    } catch (Throwable $error) {
        error_log('PlusChat device logout error: ' . $error->getMessage());
    }
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}
session_destroy();
clear_device_cookie();
json_response(200, ['ok' => true]);
