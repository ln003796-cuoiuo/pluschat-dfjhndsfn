<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../modules/bootstrap.php';

$userId = authenticated_user_id();
require_post();
$data = request_json();
$conversationId = (int) ($data['conversationId'] ?? 0);
if ($conversationId < 1) json_response(422, ['error' => 'invalid_conversation']);

try {
    $pdo = database();
    $pdo->beginTransaction();
    $conversationQuery = $pdo->prepare('SELECT id FROM conversations WHERE id = :id FOR UPDATE');
    $conversationQuery->execute(['id' => $conversationId]);
    if (!$conversationQuery->fetchColumn()) {
        $pdo->rollBack();
        json_response(404, ['error' => 'conversation_not_found']);
    }
    $memberQuery = $pdo->prepare('SELECT role FROM conversation_members WHERE conversation_id = :conversation_id AND user_id = :user_id FOR UPDATE');
    $memberQuery->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
    $role = $memberQuery->fetchColumn();
    if ($role === false) {
        $pdo->rollBack();
        json_response(404, ['error' => 'conversation_not_found']);
    }
    $repository = new \PlusChat\Modules\Messages\Conversations\ConversationRepository($pdo);
    $repository->leave($conversationId, $userId);
    $pdo->commit();
    json_response(200, ['ok' => true]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('PlusChat leave chat error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
