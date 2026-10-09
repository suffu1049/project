<?php

declare(strict_types=1);

/**
 * ResQLink front controller.
 *
 * This is the ONLY PHP file exposed to the web. All requests are routed here;
 * application code lives outside the document root (app/, config/, storage/).
 */

define('BASE_PATH', dirname(__DIR__));

/** @var \App\Core\App $app */
$app = require BASE_PATH . '/app/bootstrap.php';

// Dispatch the request. Uncaught exceptions are handled centrally by
// App\Core\ErrorHandler (registered during bootstrap).
$app->run();
