<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\EmergencyException;
use App\Core\Request;
use App\Core\Response;
use App\Services\EmergencyService;
use App\Services\LocationService;

/**
 * Browser emergency-request workflows (Phase 4).
 *
 * Web forms follow Post/Redirect/Get. Business rules live in EmergencyService;
 * this controller only authorizes, validates via the service, and renders.
 */
final class EmergencyController extends Controller
{
    private readonly EmergencyService $service;

    public function __construct()
    {
        $this->service = new EmergencyService();
    }

    /**
     * GET /requests — citizen history (admins see all operational requests).
     */
    public function index(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $items = $this->service->listForViewer($user);
        } catch (EmergencyException $exception) {
            return $this->error($request, $exception);
        }

        return $this->view('emergency/index', [
            'title' => 'My emergency requests',
            'user'  => $user,
            'items' => $items,
        ]);
    }

    /**
     * GET /requests/create — create form (Citizen only).
     */
    public function create(Request $request): Response
    {
        $user = $this->viewer($request);

        return $this->view('emergency/create', [
            'title'   => 'Request help',
            'user'    => $user,
            'errors'  => pull_flash('form_errors') ?? [],
            'scripts' => '<script src="' . e(asset('js/ai.js')) . '" defer></script>',
        ]);
    }

    /**
     * POST /requests — create a new emergency request.
     */
    public function store(Request $request): Response
    {
        $user = $this->viewer($request);

        if ((string) ($user['role'] ?? '') !== 'CITIZEN') {
            flash('error', 'Only citizens can create emergency requests.');

            return $this->redirect(url('requests/create'));
        }

        try {
            $data = $this->service->validateCreation($request->all());
            $created = $this->service->create((int) $user['id'], $data);
        } catch (EmergencyException $exception) {
            if ($exception->errorCode === 'VALIDATION_ERROR') {
                flash('form_errors', $exception->errors);
                flash('_old_input', $request->all());
            } else {
                flash('error', $exception->userMessage());
            }

            return $this->redirect(url('requests/create'));
        }

        pull_flash('_old_input');
        flash('success', 'Emergency request created. Priority: ' . $created['priority'] . '.');

        return $this->redirect(url('requests/' . (int) $created['id']));
    }

    /**
     * GET /requests/{id} — detail page with timeline.
     */
    public function show(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $requestId = (int) $id;

        try {
            $item = $this->service->findVisible($requestId, $user);
            $events = $this->service->events($requestId, $user);
            $attachments = $this->service->attachments($requestId, $user);
        } catch (EmergencyException $exception) {
            return $this->error($request, $exception);
        }

        // Phase 8: role-aware map marker for the detail page.
        $marker = (new LocationService())->requestMarker($item, $user, false);
        $mapMarkers = $marker !== null ? [$marker] : [];
        $headExtra = null;
        $scripts = null;

        if ($mapMarkers !== []) {
            $leafletCss = (string) config('services.maps.leaflet_css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
            $leafletJs = (string) config('services.maps.leaflet_js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js');
            $headExtra = '<link rel="stylesheet" href="' . e($leafletCss) . '">';
            $scripts = '<script src="' . e($leafletJs) . '" defer></script>'
                . '<script src="' . e(asset('js/map.js')) . '" defer></script>';
        }

        return $this->view('emergency/show', [
            'title'       => 'Request #' . $requestId,
            'user'        => $user,
            'item'        => $item,
            'events'      => $events,
            'attachments' => $attachments,
            'mapMarkers'  => $mapMarkers,
            'headExtra'   => $headExtra,
            'scripts'     => $scripts,
            'editable'    => in_array((string) $item['status'], ['CREATED', 'VERIFIED', 'MATCHING'], true)
                && ((string) ($user['role'] ?? '') === 'ADMIN' || (int) $item['user_id'] === (int) $user['id']),
            'cancellable' => in_array((string) $item['status'], ['CREATED', 'VERIFIED', 'MATCHING'], true),
        ]);
    }

    /**
     * POST /requests/{id}/update — owner edit of open-request fields.
     */
    public function update(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $requestId = (int) $id;

        try {
            $data = $this->service->validateUpdate($request->all());
            $this->service->update($requestId, $user, $data);
        } catch (EmergencyException $exception) {
            if ($exception->errorCode === 'VALIDATION_ERROR') {
                flash('form_errors', $exception->errors);
                flash('_old_input', $request->all());
            } else {
                flash('error', $exception->userMessage());
            }

            return $this->redirect(url('requests/' . $requestId));
        }

        pull_flash('_old_input');
        flash('success', 'Request updated.');

        return $this->redirect(url('requests/' . $requestId));
    }

    /**
     * POST /requests/{id}/cancel — cancel an open request.
     */
    public function cancel(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $requestId = (int) $id;
        $note = trim((string) $request->input('note', ''));

        try {
            $this->service->cancel($requestId, $user, $note !== '' ? $note : null);
        } catch (EmergencyException $exception) {
            flash('error', $exception->userMessage());

            return $this->redirect(url('requests/' . $requestId));
        }

        flash('success', 'Request cancelled.');

        return $this->redirect(url('requests/' . $requestId));
    }

    /**
     * POST /requests/{id}/attachment — upload an image to a request.
     */
    public function attachment(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $requestId = (int) $id;
        $file = $request->file('file');

        try {
            if ($file === null) {
                throw EmergencyException::attachmentRejected('Choose an image to upload.');
            }

            $this->service->attach($requestId, $user, $file);
        } catch (EmergencyException $exception) {
            flash('error', $exception->userMessage());

            return $this->redirect(url('requests/' . $requestId));
        }

        flash('success', 'Attachment uploaded.');

        return $this->redirect(url('requests/' . $requestId));
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }

    private function error(Request $request, EmergencyException $exception): Response
    {
        if ($request->wantsJson()) {
            return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
        }

        if ($exception->status === 404) {
            return $this->view('errors/404', [
                'status'  => 404,
                'message' => 'Emergency request not found.',
            ], 'layouts/app', 404);
        }

        if ($exception->status === 403) {
            return $this->view('errors/403', [
                'status'  => 403,
                'message' => $exception->userMessage(),
            ], 'layouts/app', 403);
        }

        flash('error', $exception->userMessage());

        return $this->redirect(url('requests'));
    }
}
