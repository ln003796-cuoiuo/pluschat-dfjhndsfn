<?php
declare(strict_types=1);

/**
 * Lightweight module autoloader for shared hosting.
 * Domain directory names are lowercase on disk; class namespaces remain StudlyCase.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'PlusChat\\Modules\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $segments = explode('\\', $relative);
    if ($segments === [] || $segments[0] === '') {
        return;
    }
    $segments[0] = strtolower($segments[0]);
    $path = __DIR__ . '/' . implode('/', $segments) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
