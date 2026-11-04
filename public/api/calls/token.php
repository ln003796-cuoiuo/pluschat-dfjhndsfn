<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

require_post();
$userId = authenticated_user_id();
$secret = configured_app_secret();
if ($secret === null) json_response(503, ['error' => 'calls_not_configured']);

$retryAfter = consume_rate_limit('call-token', (string) $userId, 20, 60);
if ($retryAfter > 0) {
    header('Retry-After: ' . $retryAfter);
    json_response(429, ['error' => 'too_many_call_requests', 'retryAfter' => $retryAfter]);
}

$data = request_json();
$conversationId = filter_var($data['conversation_id'] ?? null, FILTER_VALIDATE_INT);
$type = (string) ($data['type'] ?? 'audio');
if (!$conversationId || $conversationId < 1 || !in_array($type, ['audio', 'video'], true)) {
    json_response(422, ['error' => 'invalid_call_request']);
}

try {
    $pdo = database();
    $query = $pdo->prepare(
        'SELECT c.id, c.kind, cm.role
         FROM conversations c
         JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = :user_id
         WHERE c.id = :conversation_id LIMIT 1'
    );
    $query->execute(['user_id' => $userId, 'conversation_id' => $conversationId]);
    $conversation = $query->fetch();
    if (!$conversation) json_response(404, ['error' => 'conversation_not_found']);
        $now = time();
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $claims = [
        'iss' => 'pluschat',
        'sub' => (string) $userId,
        'room' => 'conversation-' . (string) $conversationId,
        'conversation_id' => (int) $conversationId,
        'type' => $type,
        'role' => (string) $conversation['role'],
        'jti' => sprintf('%s-%s', bin2hex(random_bytes(8)), bin2hex(random_bytes(8))),
        'iat' => $now,
        'exp' => $now + 180,
    ];
    $encode = static fn(array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $unsigned = $encode($header) . '.' . $encode($claims);
    $signature = hash_hmac('sha256', $unsigned, $secret, true);
    $token = $unsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    $baseUrl = rtrim((string) env_value('CALLS_BASE_URL', 'https://xn--b1ajehof.xn--80avljg2a1c.xn--p1ai'), '/');

    // Publish a normal chat notice without exposing the one-time join token.
    $notice = $type === 'video'
        ? '📹 Видеозвонок начат. Нажмите кнопку звонка в заголовке чата, чтобы присоединиться.'
        : '📞 Аудиозвонок начат. Нажмите кнопку звонка в заголовке чата, чтобы присоединиться.';
    $message = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_type, body) VALUES (:conversation_id, :sender_id, 'text', :body)");
    $message->execute(['conversation_id' => $conversationId, 'sender_id' => $userId, 'body' => $notice]);

    json_response(200, [
        'ok' => true,
        'token' => $token,
        'call_url' => $baseUrl . '/#' . $token,
        'expires_in' => 180,
        'conversation_id' => (int) $conversationId,
        'type' => $type,
    ]);
} catch (Throwable $error) {
    error_log('Pluschat call token error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
