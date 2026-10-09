<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\LocationService;

/**
 * Operational map page (Phase 8).
 *
 * Markers are assembled server-side with role-aware precision. The Leaflet
 * client enhances the page when CDN assets load; the HTML/JSON payload works
 * without JavaScript.
 */
final class MapController extends Controller
{
    private readonly LocationService $service;

    public function __construct()
    {
        $this->service = new LocationService();
    }

    /**
     * GET /map
     */
    public function index(Request $request): Response
    {
        $user = $this->viewer($request);

        if ($user === []) {
            return $this->redirect(url('login'));
        }

        $result = $this->service->markersForViewer($user);
        $leafletCss = (string) config('services.maps.leaflet_css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
        $leafletJs = (string) config('services.maps.leaflet_js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js');

        return $this->view('map/index', [
            'title'      => 'Operations map',
            'user'       => $user,
            'markers'    => $result['items'],
            'meta'       => $result['meta'],
            'leafletCss' => $leafletCss,
            'leafletJs'  => $leafletJs,
            'headExtra'  => '<link rel="stylesheet" href="' . e($leafletCss) . '">',
            'scripts'    => '<script src="' . e($leafletJs) . '" defer></script>'
                . '<script src="' . e(asset('js/map.js')) . '" defer></script>',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }
}
