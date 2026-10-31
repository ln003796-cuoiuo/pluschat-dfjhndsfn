<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    json_response(405, ['error' => 'method_not_allowed']);
}
$username = strtolower(trim((string) ($_GET['username'] ?? '')));
if (!preg_match('/^[a-z0-9_]{3,24}$/', $username)) {
    json_response(200, ['available' => false, 'reason' => 'invalid']);
}
try {
    $query = database()->prepare('SELECT 1 FROM users WHERE username = :username LIMIT 1');
    $query->execute(['username' => $username]);
    json_response(200, ['available' => !$query->fetchColumn()]);
} catch (Throwable $error) {
    error_log('PlusChat username check error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
