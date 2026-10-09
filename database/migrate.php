<?php

declare(strict_types=1);

/**
 * ResQLink migration runner (CLI).
 *
 * Usage:
 *   php -c php.ini database/migrate.php           # apply pending migrations
 *   php -c php.ini database/migrate.php --fresh   # drop everything, then migrate
 *   php -c php.ini database/migrate.php --status  # show applied/pending
 */

use App\Core\Migrator;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

$options = $argv ?? [];
$fresh = in_array('--fresh', $options, true);
$status = in_array('--status', $options, true);

$say = static function (string $line): void {
    echo $line . PHP_EOL;
};

$migrator = new Migrator(base_path('database/migrations'), $say);

try {
    if ($status) {
        $applied = $migrator->applied();
        $pending = $migrator->pending();

        $say('Applied migrations (' . count($applied) . '):');
        foreach (array_keys($applied) as $name) {
            $say('  ✓ ' . $name);
        }

        $say('Pending migrations (' . count($pending) . '):');
        foreach ($pending as $name) {
            $say('  · ' . $name);
        }

        exit(0);
    }

    if ($fresh) {
        $say('Dropping all application tables (--fresh)...');
        $migrator->fresh();
    }

    $say('Applying migrations...');
    $ran = $migrator->migrate();

    $say($ran === []
        ? 'Nothing to migrate. Database is up to date.'
        : sprintf('Done. Applied %d migration(s).', count($ran)));

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
