<?php

declare(strict_types=1);

/**
 * Database configuration (MySQL 8+, InnoDB, utf8mb4).
 *
 * Credentials are read from `.env` only. The connection is created lazily by
 * App\Core\Database so the application can boot without a database.
 */
return [
    'driver'   => 'mysql',
    'host'     => env('DB_HOST', '127.0.0.1'),
    'port'     => env('DB_PORT', '3306'),
    'database' => env('DB_NAME', 'resqlink'),
    'username' => env('DB_USER', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset'  => env('DB_CHARSET', 'utf8mb4'),
];
