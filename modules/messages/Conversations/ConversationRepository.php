<?php
declare(strict_types=1);

namespace PlusChat\Modules\Messages\Conversations;

use PDO;
use LogicException;

/**
 * Owns conversation membership persistence and owner hand-off.
 * Call leave() inside the caller's transaction so related policy checks can
 * be committed or rolled back atomically with the membership change.
 */
final class ConversationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function leave(int $conversationId, int $userId): bool
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Conversation membership changes require a transaction.');
        }

        $conversation = $this->pdo->prepare(
            'SELECT id FROM conversations WHERE id = :id FOR UPDATE'
        );
        $conversation->execute(['id' => $conversationId]);
        if (!$conversation->fetchColumn()) {
            return false;
        }

        $member = $this->pdo->prepare(
            'SELECT role FROM conversation_members
             WHERE conversation_id = :conversation_id AND user_id = :user_id
             FOR UPDATE'
        );
        $member->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
        ]);
        $role = $member->fetchColumn();
        if ($role === false) {
            return false;
        }

        $nextOwnerId = null;
        if ($role === 'owner') {
            $nextOwner = $this->pdo->prepare(
                'SELECT user_id FROM conversation_members
                 WHERE conversation_id = :conversation_id AND user_id <> :user_id
                 ORDER BY joined_at, user_id LIMIT 1 FOR UPDATE'
            );
            $nextOwner->execute([
                'conversation_id' => $conversationId,
                'user_id' => $userId,
            ]);
            $nextOwnerId = $nextOwner->fetchColumn();
        }

        $delete = $this->pdo->prepare(
            'DELETE FROM conversation_members
             WHERE conversation_id = :conversation_id AND user_id = :user_id'
        );
        $delete->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
        ]);

        if ($nextOwnerId !== null && $nextOwnerId !== false) {
            $promote = $this->pdo->prepare(
                "UPDATE conversation_members SET role = 'owner'
                 WHERE conversation_id = :conversation_id AND user_id = :user_id"
            );
            $promote->execute([
                'conversation_id' => $conversationId,
                'user_id' => $nextOwnerId,
            ]);

            $updateCreator = $this->pdo->prepare(
                'UPDATE conversations SET created_by = :owner WHERE id = :id'
            );
            $updateCreator->execute([
                'owner' => $nextOwnerId,
                'id' => $conversationId,
            ]);
        }

        $remaining = $this->pdo->prepare(
            'SELECT COUNT(*) FROM conversation_members WHERE conversation_id = :id'
        );
        $remaining->execute(['id' => $conversationId]);
        if ((int) $remaining->fetchColumn() === 0) {
            $deleteConversation = $this->pdo->prepare(
                'DELETE FROM conversations WHERE id = :id'
            );
            $deleteConversation->execute(['id' => $conversationId]);
        }

        return true;
    }
}
