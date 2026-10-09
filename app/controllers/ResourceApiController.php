<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\ResourceException;
use App\Services\ResourceService;

/**
 * JSON resource endpoints (API.md §39–§42).
 *
 * Visibility-scoped listing, verified-organization creation, owner/admin
 * updates and transactional allocation. CSRF via `GET /api/v1/csrf` →
 * `X-CSRF-Token` on state-changing calls.
 */
final class ResourceApiController extends Controller
{
    private readonly ResourceService $service;

    public function __construct()
    {
        $this->service = new ResourceService();
    }

    /**
     * GET /api/v1/resources
     */
    public function index(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $result = $this->service->list($user, [
                'resource_type'   => $request->query('resource_type'),
                'status'          => $request->query('status'),
                'organization_id' => $request->query('organization_id'),
                'page'            => $request->query('page'),
                'limit'           => $request->query('limit'),
            ]);
        } catch (ResourceException $exception) {
            return $this->failed($exception);
        }

        return $this->json([
            'items'      => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Resources retrieved.');
    }

    /**
     * POST /api/v1/resources — verified organization only.
     */
    public function store(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $resource = $this->service->create($user, $request->all());
        } catch (ResourceException $exception) {
            return $this->failed($exception);
        }

        return $this->json(
            ResourceService::presentResource($resource, true),
            'Resource created.',
            201
        );
    }

    /**
     * PATCH /api/v1/resources/{id} — owning organization or admin.
     */
    public function update(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $resource = $this->service->update($user, (int) $id, $request->all());
        } catch (ResourceException $exception) {
            return $this->failed($exception);
        }

        return $this->json(
            ResourceService::presentResource($resource, true),
            'Resource updated.'
        );
    }

    /**
     * POST /api/v1/resources/{id}/allocate — transactional allocation (§42).
     */
    public function allocate(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $result = $this->service->allocate($user, (int) $id, $request->all());
        } catch (ResourceException $exception) {
            return $this->failed($exception);
        }

        return $this->json($result, 'Resource allocated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }

    private function failed(ResourceException $exception): Response
    {
        return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
    }
}