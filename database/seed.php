<?php

declare(strict_types=1);

/**
 * ResQLink database seeder (CLI).
 *
 * Usage:
 *   php -c php.ini database/seed.php
 *
 * Truncates the application tables and loads a deterministic demo dataset.
 * Run database/migrate.php first.
 */

use Database\Seeders\DatabaseSeeder;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

try {
    (new DatabaseSeeder())->run();
    echo PHP_EOL . 'Seeding complete.' . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Seeding failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
