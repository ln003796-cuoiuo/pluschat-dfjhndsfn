<?php
declare(strict_types=1);

function load_local_environment(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    $path = dirname(__DIR__) . '/.env';
    if (!is_file($path) || !is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) || getenv($name) !== false) continue;
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

load_local_environment();

function env_value(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function configured_app_secret(): ?string
{
    $secret = trim((string) env_value('APP_SECRET', ''));
    if (strlen($secret) < 32 || str_contains(strtolower($secret), 'generate-a-random-secret')) return null;
    return $secret;
}

function utf8_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : $count;
}

function normalize_email_address(string $email): ?string
{
    $email = trim($email);
    $separator = strrpos($email, '@');
    if ($separator === false || $separator === 0) return null;
    $local = strtolower(substr($email, 0, $separator));
    $domain = strtolower(substr($email, $separator + 1));
    if (preg_match('/[^\x20-\x7E]/', $domain)) {
        if (!function_exists('idn_to_ascii')) return null;
        $domain = idn_to_ascii($domain) ?: '';
    }
    $normalized = $local . '@' . $domain;
    if (strlen($normalized) > 254 || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) return null;
    return $normalized;
}

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_value('DB_HOST', 'localhost');
    $port = env_value('DB_PORT', '3306');
    $name = env_value('DB_NAME');
    $user = env_value('DB_USER');
    $password = env_value('DB_PASSWORD');
    if (!$name || !$user || $password === null) {
        throw new RuntimeException('Database configuration is incomplete.');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $pdo;
}

function secure_session_cookies(): bool
{
    $configured = getenv('SESSION_SECURE_COOKIE');
    if ($configured === false || $configured === '') return true;
    return filter_var($configured, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    session_name('pluschat_session');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'secure' => secure_session_cookies(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function authenticated_user_id(): int
{
    start_app_session();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $sessionVersion = (int) ($_SESSION['session_version'] ?? 0);
    try {
        if ($userId > 0 && $sessionVersion > 0 && (int) ($_SESSION['device_id'] ?? 0) > 0) {
            $deviceId = (int) $_SESSION['device_id'];
            $query = database()->prepare('SELECT d.token_hash, d.session_version, d.expires_at > NOW() AS is_active, u.session_version AS current_version FROM user_devices d JOIN users u ON u.id = d.user_id WHERE d.id = :device_id AND d.user_id = :user_id AND d.revoked_at IS NULL LIMIT 1');
            $query->execute(['device_id' => $deviceId, 'user_id' => $userId]);
            $device = $query->fetch();
            $cookie = (string) ($_COOKIE['pluschat_device'] ?? '');
            $cookieMatches = $device !== false
                && preg_match('/^[a-f0-9]{64}$/', $cookie) === 1
                && hash_equals((string) $device['token_hash'], hash('sha256', $cookie));
            if ($device && (bool) $device['is_active'] && $cookieMatches
                && (int) $device['session_version'] === $sessionVersion
                && (int) $device['current_version'] === $sessionVersion) {
                $now = time();
                if (!empty($_COOKIE['pluschat_device']) && $now - (int) ($_SESSION['device_cookie_refreshed_at'] ?? 0) > 86400) {
                    database()->prepare('UPDATE user_devices SET last_used_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = :id')->execute(['id' => $deviceId]);
                    set_device_cookie($cookie, $now + 60 * 60 * 24 * 30);
                    $_SESSION['device_cookie_refreshed_at'] = $now;
                    $_SESSION['device_last_recorded_at'] = $now;
                } elseif ($now - (int) ($_SESSION['device_last_recorded_at'] ?? 0) > 300) {
                    database()->prepare('UPDATE user_devices SET last_used_at = NOW() WHERE id = :id')->execute(['id' => $deviceId]);
                    $_SESSION['device_last_recorded_at'] = $now;
                }
                return $userId;
            }
        }

        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to rotate the application session ID.');
        }
        $cookie = (string) ($_COOKIE['pluschat_device'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $cookie)) {
            $query = database()->prepare('SELECT d.id, d.user_id, d.session_version, u.session_version AS current_version FROM user_devices d JOIN users u ON u.id = d.user_id WHERE d.token_hash = :token_hash AND d.revoked_at IS NULL AND d.expires_at > NOW() LIMIT 1');
            $query->execute(['token_hash' => hash('sha256', $cookie)]);
            $device = $query->fetch();
            if ($device && (int) $device['session_version'] === (int) $device['current_version']) {
                $userId = (int) $device['user_id'];
                $sessionVersion = (int) $device['session_version'];
                $_SESSION = [
                    'user_id' => $userId,
                    'session_version' => $sessionVersion,
                    'device_id' => (int) $device['id'],
                    'authenticated_at' => time(),
                    'device_cookie_refreshed_at' => time(),
                    'device_last_recorded_at' => time(),
                ];
                database()->prepare('UPDATE user_devices SET last_used_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = :id')->execute(['id' => (int) $device['id']]);
                set_device_cookie($cookie, time() + 60 * 60 * 24 * 30);
                return $userId;
            }
        }
        clear_device_cookie();
        session_destroy();
        json_response(401, ['error' => 'not_authenticated']);
    } catch (Throwable $error) {
        error_log('PlusChat session check error: ' . $error->getMessage());
        json_response(503, ['error' => 'server_unavailable']);
    }
}

function set_device_cookie(string $token, int $expires): void
{
    setcookie('pluschat_device', $token, [
        'expires' => $expires,
        'path' => '/',
        'secure' => secure_session_cookies(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_device_cookie(): void
{
    setcookie('pluschat_device', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => secure_session_cookies(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['pluschat_device']);
}

function create_persistent_device_session(int $userId, int $sessionVersion): void
{
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Unable to rotate the application session ID.');
    }
    $token = bin2hex(random_bytes(32));
    $label = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Устройство'));
    $label = substr(preg_replace('/[\x00-\x1F\x7F]/', '', $label) ?: 'Устройство', 0, 255);
    $query = database()->prepare('INSERT INTO user_devices (user_id, token_hash, session_version, device_label, expires_at) VALUES (:user_id, :token_hash, :version, :label, DATE_ADD(NOW(), INTERVAL 30 DAY))');
    $query->execute(['user_id' => $userId, 'token_hash' => hash('sha256', $token), 'version' => $sessionVersion, 'label' => $label]);
    $now = time();
    $_SESSION = [
        'user_id' => $userId,
        'session_version' => $sessionVersion,
        'device_id' => (int) database()->lastInsertId(),
        'authenticated_at' => $now,
        'device_cookie_refreshed_at' => $now,
        'device_last_recorded_at' => $now,
    ];
    set_device_cookie($token, $now + 60 * 60 * 24 * 30);
}

function age_group(?int $ageYears): string
{
    if ($ageYears === null || $ageYears < 1 || $ageYears > 120) return 'unknown';
    if ($ageYears < 16) return 'under_16';
    if ($ageYears < 18) return '16_17';
    return 'adult';
}

function age_group_label(string $group): string
{
    return match ($group) {
        'under_16' => 'до 16 лет',
        '16_17' => '16–17 лет',
        'adult' => '18+',
        default => 'не указана',
    };
}

function remove_conversation_member(PDO $pdo, int $conversationId, int $userId): bool
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Conversation membership changes require a transaction.');
    }
    $conversation = $pdo->prepare('SELECT id FROM conversations WHERE id = :id FOR UPDATE');
    $conversation->execute(['id' => $conversationId]);
    if (!$conversation->fetchColumn()) return false;

    $member = $pdo->prepare('SELECT role FROM conversation_members WHERE conversation_id = :conversation_id AND user_id = :user_id FOR UPDATE');
    $member->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
    $role = $member->fetchColumn();
    if ($role === false) return false;

    $nextOwner = null;
    if ($role === 'owner') {
        $next = $pdo->prepare('SELECT user_id FROM conversation_members WHERE conversation_id = :conversation_id AND user_id <> :user_id ORDER BY joined_at, user_id LIMIT 1 FOR UPDATE');
        $next->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
        $nextOwner = $next->fetchColumn();
    }
    $pdo->prepare('DELETE FROM conversation_members WHERE conversation_id = :conversation_id AND user_id = :user_id')->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
    if ($nextOwner !== null && $nextOwner !== false) {
        $pdo->prepare('UPDATE conversation_members SET role = \'owner\' WHERE conversation_id = :conversation_id AND user_id = :user_id')->execute(['conversation_id' => $conversationId, 'user_id' => $nextOwner]);
        $pdo->prepare('UPDATE conversations SET created_by = :owner WHERE id = :id')->execute(['owner' => $nextOwner, 'id' => $conversationId]);
    }
    $remaining = $pdo->prepare('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = :id');
    $remaining->execute(['id' => $conversationId]);
    if ((int) $remaining->fetchColumn() === 0) {
        $pdo->prepare('DELETE FROM conversations WHERE id = :id')->execute(['id' => $conversationId]);
    }
    return true;
}

function consume_rate_limit(string $scope, string $identity, int $limit, int $windowSeconds): int
{
    if ($limit < 1 || $windowSeconds < 1 || $windowSeconds > 86400) {
        throw new InvalidArgumentException('Invalid rate limit configuration.');
    }
    $secret = configured_app_secret();
    if ($secret === null) throw new RuntimeException('APP_SECRET is not securely configured.');
    $bucket = hash_hmac('sha256', $scope . "\0" . $identity, $secret);
    $window = (string) $windowSeconds;
    $pdo = database();
    if (random_int(1, 100) === 1) {
        $pdo->exec('DELETE FROM auth_rate_limits WHERE window_started_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }
    $upsert = $pdo->prepare(
        'INSERT INTO auth_rate_limits (bucket_key, window_started_at, attempts) VALUES (:bucket, NOW(), 1) '
        . 'ON DUPLICATE KEY UPDATE '
        . 'attempts = IF(window_started_at <= DATE_SUB(NOW(), INTERVAL ' . $window . ' SECOND), 1, attempts + 1), '
        . 'window_started_at = IF(window_started_at <= DATE_SUB(NOW(), INTERVAL ' . $window . ' SECOND), NOW(), window_started_at)'
    );
    $upsert->execute(['bucket' => $bucket]);
    $query = $pdo->prepare('SELECT attempts, GREATEST(0, TIMESTAMPDIFF(SECOND, window_started_at, NOW())) AS elapsed FROM auth_rate_limits WHERE bucket_key = :bucket LIMIT 1');
    $query->execute(['bucket' => $bucket]);
    $row = $query->fetch();
    if (!$row || (int) $row['attempts'] <= $limit) return 0;
    return max(1, $windowSeconds - (int) $row['elapsed']);
}

function json_response(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        json_response(405, ['error' => 'method_not_allowed']);
    }
}

function request_json(): array
{
    $maxBytes = 65536;
    $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    if ($contentLength !== false && $contentLength !== null && $contentLength > $maxBytes) {
        json_response(413, ['error' => 'request_too_large']);
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw !== false && strlen($raw) > $maxBytes) {
        json_response(413, ['error' => 'request_too_large']);
    }
    try {
        $data = json_decode($raw === false ? '' : $raw, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_response(400, ['error' => 'invalid_json']);
    }
    if (!$data instanceof stdClass) json_response(400, ['error' => 'invalid_json']);
    return get_object_vars($data);
}
