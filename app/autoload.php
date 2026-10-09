<?php

declare(strict_types=1);

/**
 * Dependency-free PSR-4 autoloader.
 *
 * Namespaces:
 *   App\       → app/         (application code)
 *   Database\  → database/    (seeders; migrations are raw .sql)
 *
 * If Composer is later installed, `vendor/autoload.php` can be used instead
 * without changing application code.
 */

$resqlinkAutoloadMap = [
    'App\\'      => BASE_PATH . '/app',
    'Database\\' => BASE_PATH . '/database',
];

spl_autoload_register(static function (string $class) use ($resqlinkAutoloadMap): void {
    foreach ($resqlinkAutoloadMap as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
});
