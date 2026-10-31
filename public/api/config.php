<?php
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

header('Access-Control-Allow-Origin: ' . (getenv('APP_ORIGIN') ?: 'https://xn--80avljg2a1c.xn--p1ai'));
header('Vary: Origin');
json_response(200, ['giphyApiKey' => getenv('GIPHY_API_KEY') ?: null]);
