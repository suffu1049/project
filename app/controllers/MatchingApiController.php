<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\MatchingException;
use App\Core\Request;
use App\Core\Response;
use App\Services\MatchingService;

/**
 * JSON matching endpoints (API.md §31–§35).
 *
 * Suggestion generation, match lifecycle (accept / reject / complete) and
 * role-scoped match lists. CSRF via `GET /api/v1/csrf` → `X-CSRF-Token` on
 * state-changing calls. Exact private coordinates are withheld from volunteer
 * suggestion payloads until a match is accepted.
 */
final class MatchingApiController extends Controller
{
    private readonly MatchingService $service;

    public function __construct()
    {
        $this->service = new MatchingService();
    }

    /**
     * GET /api/v1/requests/{id}/matches — stored matches for a request.
     */
    public function requestMatches(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $result = $this->service->matchesForRequest((int) $id, $user);
        } catch (MatchingException $exception) {
            return $this->failed($exception);
        }

        return $this->json($result, 'Matches retrieved.');
    }

    /**
     * POST /api/v1/requests/{id}/matches/suggest — compute + persist suggestions.
     */
    public function suggest(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $result = $this->service->suggestForRequest((int) $id, $user, true, [
                'type'  => $request->input('type', $request->query('type')),
                'limit' => $request->input('limit', $request->query('limit')),
            ]);
        } catch (MatchingException $exception) {
            return $this->failed($exception);
        }

        return $this->json($result, 'Match suggestions generated.');
    }

    /**
     * GET /api/v1/matches — role-scoped match list.
     */
    public function index(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $result = $this->service->listMatches($user, [
                'status' => $request->query('status'),
                'page'   => $request->query('page'),
                'limit'  => $request->query('limit'),
            ]);
        } catch (MatchingException $exception) {
            return $this->failed($exception);
        }

        return $this->json($result, 'Matches retrieved.');
    }

    /**
     * GET /api/v1/matches/{id}
     */
    public function show(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $match = $this->service->show((int) $id, $user);
        } catch (MatchingException $exception) {
            return $this->failed($exception);
        }

        return $this->json($match, 'Match retrieved.');
    }

    /**
     * POST /api/v1/matches/{id}/accept
     */
    public function accept(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $result = $this->service->accept((int) $id, $user);
        } catch (MatchingException $exception) {
            return $this->failed($exception);
        }

        return $this->json($result, 'Match accepted.');
    }

    /**
     * POST /api/v1/matches/{id}/reject
     */
    public function reject(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $reason = $request->input('reason');
        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

        try {
            $result = $this->service->reject((int) $id, $user, $reason);
        } catch (MatchingException $exception) {
            return $this->failed($exception);
        }

        return $this->json($result, 'Match rejected.');
    }

    /**
     * POST /api/v1/matches/{id}/complete
     */
    public function complete(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $result = $this->service->complete((int) $id, $user);
        } catch (MatchingException $exception) {
            return $this->failed($exception);
        }

        return $this->json($result, 'Match completed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }

    private function failed(MatchingException $exception): Response
    {
        return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
    }
}
