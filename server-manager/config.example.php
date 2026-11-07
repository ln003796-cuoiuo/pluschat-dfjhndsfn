<?php
declare(strict_types=1);

/**
 * Copy to config.php and set a unique random token (at least 40 characters).
 * Keep this file private; .htaccess below denies direct HTTP access.
 */
return [
    'root' => '/www/xn--80avljg2a1c.xn--p1ai',
    'token' => 'REPLACE_WITH_A_LONG_RANDOM_SECRET_MINIMUM_40_CHARACTERS',
];
