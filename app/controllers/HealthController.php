<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * API health controller.
 *
 * Returns safe service information only. It never reveals credentials,
 * filesystem paths, stack traces or database error details.
 */
final class HealthController extends Controller
{
    public function base(Request $request): Response
    {
        return $this->json([
            'service' => config('app.name', 'ResQLink'),
            'version' => 'v1',
            'docs'    => url('api/v1/health'),
        ], 'ResQLink API v1.');
    }

    public function index(Request $request): Response
    {
        $database = Database::ping();

        return $this->json([
            'status'      => 'ok',
            'service'     => config('app.name', 'ResQLink'),
            'environment' => config('app.env', 'production'),
            'time'        => date(DATE_ATOM),
            'checks'      => [
                'php'      => version_compare(PHP_VERSION, '8.1.0', '>='),
                'database' => $database,
            ],
        ], 'Service healthy.');
    }
}
