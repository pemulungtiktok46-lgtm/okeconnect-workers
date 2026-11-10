<?php
declare(strict_types=1);

// Router PHP built-in server: file statis dilayani langsung, API diarahkan ke index.php.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = realpath(__DIR__);
$requested = realpath(__DIR__ . $path);
if ($requested !== false && $root !== false
    && str_starts_with($requested, $root . DIRECTORY_SEPARATOR)
    && is_file($requested)) {
    return false;
}
require __DIR__ . '/index.php';
