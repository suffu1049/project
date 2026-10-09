<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\ResourceException;
use App\Services\ResourceService;

/**
 * Browser inventory workflows (Phase 6).
 *
 * Create / edit / replenish / mark-unavailable / allocate resources for the
 * signed-in organization. Business rules live in ResourceService; forms
 * follow PRG. Routes are guarded by RoleMiddleware(['ORGANIZATION']).
 */
final class ResourceController extends Controller
{
    private readonly ResourceService $service;

    public function __construct()
    {
        $this->service = new ResourceService();
    }

    /**
     * POST /organization/resources — add a resource (dashboard form).
     */
    public function store(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $this->service->create($user, $request->all());
        } catch (ResourceException $exception) {
            $this->remember($exception);

            return $this->redirect(url('organization/dashboard'));
        }

        flash('success', 'Resource added to your inventory.');

        return $this->redirect(url('organization/dashboard'));
    }

    /**
     * GET /organization/resources/{id}/edit — edit / replenish form.
     */
    public function edit(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $resource = $this->service->findVisible($user, (int) $id);
        } catch (ResourceException $exception) {
            return $this->error($request, $exception);
        }

        return $this->view('organization/resource-edit', [
            'title'    => 'Edit resource',
            'user'     => $user,
            'resource' => $resource,
            'types'    => ResourceService::TYPES,
            'errors'   => pull_flash('form_errors') ?? [],
        ]);
    }

    /**
     * POST /organization/resources/{id} — save resource edits.
     */
    public function update(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $this->service->update($user, (int) $id, $request->all());
        } catch (ResourceException $exception) {
            $this->remember($exception);

            return $this->redirect(url('organization/resources/' . (int) $id . '/edit'));
        }

        flash('success', 'Resource updated.');

        return $this->redirect(url('organization/dashboard'));
    }

    /**
     * POST /organization/resources/{id}/replenish — restock both quantities.
     */
    public function replenish(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $units = $request->input('units', 0);

        try {
            $this->service->replenish($user, (int) $id, is_numeric($units) ? (int) $units : 0);
        } catch (ResourceException $exception) {
            $this->remember($exception);

            return $this->redirect(url('organization/resources/' . (int) $id . '/edit'));
        }

        flash('success', 'Resource replenished.');

        return $this->redirect(url('organization/resources/' . (int) $id . '/edit'));
    }

    /**
     * POST /organization/resources/{id}/allocate — allocate to a request.
     */
    public function allocate(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $this->service->allocate($user, (int) $id, [
                'request_id' => $request->input('request_id'),
                'quantity'   => $request->input('quantity'),
            ]);
        } catch (ResourceException $exception) {
            $this->remember($exception);

            return $this->redirect(url('organization/dashboard'));
        }

        flash('success', 'Resources allocated to the emergency request.');

        return $this->redirect(url('organization/dashboard'));
    }

    /**
     * Persist validation/authorization problems for the next PRG render.
     */
    private function remember(ResourceException $exception): void
    {
        if ($exception->errorCode === 'VALIDATION_ERROR') {
            flash('form_errors', $exception->errors !== [] ? $exception->errors : ['quantity' => [$exception->userMessage()]]);

            return;
        }

        flash('error', $exception->userMessage());
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }

    private function error(Request $request, ResourceException $exception): Response
    {
        if ($request->wantsJson()) {
            return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
        }

        if ($exception->status === 404) {
            return $this->view('errors/404', [
                'status'  => 404,
                'message' => 'Resource not found.',
            ], 'layouts/app', 404);
        }

        if ($exception->status === 403) {
            return $this->view('errors/403', [
                'status'  => 403,
                'message' => $exception->userMessage(),
            ], 'layouts/app', 403);
        }

        flash('error', $exception->userMessage());

        return $this->redirect(url('organization/dashboard'));
    }
}