<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

$userId = authenticated_user_id();
$beforeId = filter_input(INPUT_GET, 'before_id', FILTER_VALIDATE_INT);
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
$limit = max(1, min(50, $limit ?: 30));

try {
    $pdo = database();
    $sql = "SELECT p.id, p.channel_id, p.post_type, p.body, p.created_at,
                   c.title AS channel_title, c.slug AS channel_slug,
                   u.id AS author_id, u.display_name AS author_name, u.username AS author_username
            FROM channel_posts p
            JOIN channels c ON c.id = p.channel_id
            JOIN channel_subscriptions s ON s.channel_id = c.id AND s.user_id = :user_id
            JOIN users u ON u.id = p.author_user_id";
    $params = ['user_id' => $userId];
    if ($beforeId && $beforeId > 0) {
        $sql .= ' WHERE p.id < :before_id';
        $params['before_id'] = $beforeId;
    }
    $sql .= ' ORDER BY p.id DESC LIMIT ' . $limit;
    $query = $pdo->prepare($sql);
    $query->execute($params);
    $posts = $query->fetchAll();
    foreach ($posts as &$post) {
        $post['id'] = (int) $post['id'];
        $post['channel_id'] = (int) $post['channel_id'];
        $post['author_id'] = (int) $post['author_id'];
    }
    unset($post);
    json_response(200, ['posts' => $posts]);
} catch (Throwable $error) {
    error_log('PlusChat channel feed error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
