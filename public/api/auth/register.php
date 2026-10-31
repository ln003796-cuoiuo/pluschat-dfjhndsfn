<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/Mailer.php';

require_post();
start_app_session();
$email = normalize_email_address((string) ($_POST['email'] ?? ''));
$username = strtolower(trim((string) ($_POST['username'] ?? '')));
$displayName = trim((string) ($_POST['displayName'] ?? ''));
$bio = trim((string) ($_POST['bio'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($email === null || ($email !== ($_SESSION['verified_registration_email'] ?? ''))
    || (int) ($_SESSION['verified_registration_at'] ?? 0) < time() - 1800) {
    json_response(403, ['error' => 'email_not_verified']);
}
if (!preg_match('/^[a-z0-9_]{3,24}$/', $username)) {
    json_response(422, ['error' => 'invalid_username']);
}
if ($displayName === '' || utf8_length($displayName) > 80 || utf8_length($bio) > 280) {
    json_response(422, ['error' => 'invalid_profile']);
}
if (strlen($password) < 8 || strlen($password) > 72 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)
    || !preg_match('/\d/', $password) || !preg_match('/[^\p{L}\p{N}]/u', $password)) {
    json_response(422, ['error' => 'password_policy']);
}

$avatarPath = null;
$savedAvatar = null;
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['avatar'];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 4 * 1024 * 1024) {
        json_response(422, ['error' => 'avatar_upload_failed']);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $image = @getimagesize($file['tmp_name']);
    if (!$image || !isset($extensions[$mime]) || $image['mime'] !== $mime || $image[0] > 4096 || $image[1] > 4096 || $image[0] * $image[1] > 12000000) {
        json_response(422, ['error' => 'avatar_type_not_supported']);
    }
    $avatarDir = dirname(__DIR__, 3) . '/storage/avatars';
    if (!is_dir($avatarDir) && !mkdir($avatarDir, 0700, true) && !is_dir($avatarDir)) {
        json_response(500, ['error' => 'server_error']);
    }
    $filename = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
    $savedAvatar = $avatarDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $savedAvatar)) {
        json_response(500, ['error' => 'avatar_upload_failed']);
    }
    $avatarPath = 'storage/avatars/' . $filename;
}

try {
    $pdo = database();
    $verification = $pdo->prepare('SELECT (verified_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)) AS is_fresh FROM email_verification_codes WHERE email = :email LIMIT 1');
    $verification->execute(['email' => $email]);
    $isFresh = $verification->fetchColumn();
    if (!$isFresh) {
        if ($savedAvatar) @unlink($savedAvatar);
        json_response(403, ['error' => 'email_not_verified']);
    }
    $insert = $pdo->prepare(
        'INSERT INTO users (email, username, password_hash, email_verified_at, display_name, bio, avatar_path) '
        . 'VALUES (:email, :username, :password_hash, NOW(), :display_name, :bio, :avatar_path)'
    );
    $insert->execute([
        'email' => $email,
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'display_name' => $displayName,
        'bio' => $bio === '' ? null : $bio,
        'avatar_path' => $avatarPath,
    ]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare('DELETE FROM email_verification_codes WHERE email = :email')->execute(['email' => $email]);
} catch (PDOException $error) {
    if ($savedAvatar) @unlink($savedAvatar);
    if ($error->getCode() === '23000') {
        json_response(409, ['error' => 'email_or_username_taken']);
    }
    error_log('PlusChat registration error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
} catch (Throwable $error) {
    if ($savedAvatar) @unlink($savedAvatar);
    error_log('PlusChat registration error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}

try {
    create_persistent_device_session($userId, 1);
} catch (Throwable $error) {
    error_log('PlusChat device session creation error: ' . $error->getMessage());
    json_response(500, ['error' => 'account_created_login_again']);
}
send_registration_success($email, $displayName);
json_response(201, ['user' => [
    'id' => $userId,
    'email' => $email,
    'username' => $username,
    'displayName' => $displayName,
    'bio' => $bio,
    'ageGroup' => 'unknown',
    'avatarUrl' => $avatarPath ? '/api/users/avatar.php?id=' . $userId : null,
]]);
