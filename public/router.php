<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in development server.
 *
 * Usage:
 *   php -S localhost:8000 -t public public/router.php
 *
 * Existing static files (CSS/JS/images) are served directly; everything else
 * is forwarded to the front controller.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$target = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $path);

if ($path !== '/' && is_file($target)) {
    // Let the built-in server handle the static file.
    return false;
}

// Treat the front controller as the executing script for base-path detection.
$_SERVER['SCRIPT_NAME'] = '/index.php';

require __DIR__ . '/index.php';
