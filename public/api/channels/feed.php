<?php
declare(strict_types=1);

use PlusChat\Modules\Feed\FeedRepository;

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../modules/bootstrap.php';

$userId = authenticated_user_id();
$beforeId = filter_input(INPUT_GET, 'before_id', FILTER_VALIDATE_INT);
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
$limit = max(1, min(50, $limit ?: 30));

try {
    $repository = new FeedRepository(database());
    $posts = $repository->subscribedPosts($userId, $beforeId ?: null, $limit);
    json_response(200, ['posts' => $posts]);
} catch (Throwable $error) {
    error_log('PlusChat channel feed error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
