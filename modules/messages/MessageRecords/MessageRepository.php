<?php
declare(strict_types=1);

namespace PlusChat\Modules\Messages\MessageRecords;

use PDO;

/**
 * Persistence boundary for chat message records.
 *
 * Authorization and conversation policy stay in the HTTP/service layer;
 * this repository only reads and writes records for an already-authorized
 * conversation. Public API response fields are intentionally unchanged.
 */
final class MessageRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function history(int $conversationId, int $afterId = 0, int $beforeId = 0, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT m.id, m.sender_id, u.username, u.display_name,
                       u.avatar_path AS sender_avatar_path, m.message_type,
                       m.body, m.sticker_id, m.media_url,
                       s.alt_text AS sticker_alt_text, m.created_at
                FROM messages m
                JOIN users u ON u.id = m.sender_id
                LEFT JOIN stickers s ON s.id = m.sticker_id
                WHERE m.conversation_id = :conversation_id';
        $params = ['conversation_id' => $conversationId];

        if ($beforeId > 0) {
            $sql .= ' AND m.id < :cursor_id ORDER BY m.id DESC LIMIT ' . $limit;
            $params['cursor_id'] = $beforeId;
        } elseif ($afterId > 0) {
            $sql .= ' AND m.id > :cursor_id ORDER BY m.id ASC LIMIT ' . $limit;
            $params['cursor_id'] = $afterId;
        } else {
            $sql .= ' ORDER BY m.id DESC LIMIT ' . $limit;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $messages = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($beforeId > 0 || ($afterId === 0 && $messages)) {
            $messages = array_reverse($messages);
        }

        foreach ($messages as &$message) {
            $message['id'] = (int) $message['id'];
            $message['sender_id'] = (int) $message['sender_id'];
            if ($message['sticker_id'] !== null) {
                $message['sticker_id'] = (int) $message['sticker_id'];
            }
            if (!empty($message['sender_avatar_path'])) {
                $message['avatar_url'] = '/api/users/avatar.php?id=' . $message['sender_id'];
            }
            unset($message['sender_avatar_path']);
        }
        unset($message);

        return $messages;
    }

    public function createText(int $conversationId, int $senderId, string $body): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO messages (conversation_id, sender_id, message_type, body)
             VALUES (:conversation_id, :sender_id, 'text', :body)"
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'body' => $body,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createGif(int $conversationId, int $senderId, string $url): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO messages (conversation_id, sender_id, message_type, media_url)
             VALUES (:conversation_id, :sender_id, 'gif', :url)"
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'url' => $url,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createSticker(int $conversationId, int $senderId, int $stickerId): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO messages (conversation_id, sender_id, message_type, sticker_id)
             VALUES (:conversation_id, :sender_id, 'sticker', :sticker_id)"
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'sticker_id' => $stickerId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
