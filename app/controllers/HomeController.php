<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Public landing controller.
 *
 * Phase 1 only — later phases add feature dashboards under their own
 * controllers and route groups.
 */
final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('home', [
            'title'    => config('app.name', 'ResQLink'),
            'date'     => date('Y-m-d H:i'),
            'database' => Database::ping() ? 'available' : 'unavailable',
            'foundation' => [
                'Modular MVC directory structure',
                'Environment configuration (.env)',
                'Front controller & routing',
                'Base controller / service / model',
                'Centralized error handling & logging',
                'Responsive UI shell & components',
            ],
        ]);
    }

    /**
     * Plain status page (not a monitoring endpoint — see /api/v1/health).
     */
    public function status(Request $request): Response
    {
        return $this->view('system-status', [
            'title'    => 'System Status',
            'checks'   => $this->checks(),
        ]);
    }

    /**
     * @return array<int, array{label: string, ok: bool, hint: string}>
     */
    private function checks(): array
    {
        return [
            [
                'label' => 'PHP runtime',
                'ok'    => version_compare(PHP_VERSION, '8.1.0', '>='),
                'hint'  => 'PHP ' . PHP_VERSION,
            ],
            [
                'label' => 'PDO MySQL extension',
                'ok'    => extension_loaded('pdo_mysql'),
                'hint'  => extension_loaded('pdo_mysql') ? 'Loaded' : 'Enable pdo_mysql in php.ini',
            ],
            [
                'label' => 'Environment file',
                'ok'    => is_file(base_path('.env')),
                'hint'  => is_file(base_path('.env')) ? '.env loaded' : 'Copy .env.example to .env',
            ],
            [
                'label' => 'Writable storage',
                'ok'    => is_writable(storage_path('logs')),
                'hint'  => 'storage/logs',
            ],
            [
                'label' => 'Database connection',
                'ok'    => Database::ping(),
                'hint'  => 'Configured in Phase 2 / .env',
            ],
        ];
    }
}
