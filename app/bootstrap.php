<?php

declare(strict_types=1);

/**
 * ResQLink application bootstrap.
 *
 * Returns a fully-configured App instance. Kept free of side effects beyond
 * process configuration so it can be reused by the front controller and, later,
 * by CLI tools/tests.
 */

use App\Core\App;
use App\Core\Config;
use App\Core\Env;
use App\Core\ErrorHandler;

// 1. Autoloading ------------------------------------------------------------
require BASE_PATH . '/app/autoload.php';

// 2. Core helpers (env/config/paths) — needed by config files below ---------
require BASE_PATH . '/app/helpers/helpers.php';

// 3. Environment + configuration -------------------------------------------
Env::load(BASE_PATH . '/.env');
Config::load(BASE_PATH . '/config');

// 4. Centralized error handling (dev vs production behavior) ----------------
ErrorHandler::register((bool) config('app.debug', false));

// 5. Timezone ---------------------------------------------------------------
date_default_timezone_set((string) config('app.timezone', 'UTC'));

// 6. Remaining helpers ------------------------------------------------------
require BASE_PATH . '/app/helpers/security.php';
require BASE_PATH . '/app/helpers/response.php';
require BASE_PATH . '/app/helpers/validation.php';

// 7. Session ----------------------------------------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $session = config('app.session', []);

    session_name((string) ($session['name'] ?? 'resqlink_session'));
    session_set_cookie_params([
        'lifetime' => (int) ($session['lifetime'] ?? 120) * 60,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (bool) ($session['secure'] ?? false),
        'httponly' => true,
        'samesite' => (string) ($session['samesite'] ?? 'Lax'),
    ]);

    session_start();
}

// 8. Kernel + routes --------------------------------------------------------
$app = new App();
$app->loadRoutes(
    BASE_PATH . '/routes/web.php',
    BASE_PATH . '/routes/api.php'
);

return $app;
