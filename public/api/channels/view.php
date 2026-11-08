<?php
declare(strict_types=1);

use PlusChat\Modules\Channels\ChannelPageService;
use PlusChat\Modules\Channels\ChannelRepository;

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../modules/bootstrap.php';

$userId = authenticated_user_id();
$slug = (string) ($_GET['slug'] ?? '');

try {
    $service = new ChannelPageService(new ChannelRepository(database()));
    $channel = $service->getPage($slug, $userId);
    if ($channel === null) {
        json_response(404, ['error' => 'channel_not_found']);
    }
    json_response(200, ['channel' => $channel]);
} catch (InvalidArgumentException $error) {
    json_response(422, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('PlusChat channel page error: ' . $error->getMessage());
    json_response(500, ['error' => 'server_error']);
}
