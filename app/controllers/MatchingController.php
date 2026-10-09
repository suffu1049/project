<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\MatchingException;
use App\Core\Request;
use App\Core\Response;
use App\Services\MatchingService;

/**
 * Browser matching workflows (Phase 7).
 *
 * Volunteer match inbox (accept / reject / complete), request match panel for
 * owners/admins, and suggestion generation. Business rules live in
 * MatchingService; forms follow PRG.
 */
final class MatchingController extends Controller
{
    private readonly MatchingService $service;

    public function __construct()
    {
        $this->service = new MatchingService();
    }

    /**
     * GET /volunteer/matches — matches for the signed-in volunteer.
     */
    public function volunteerMatches(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $result = $this->service->listMatches($user, [
                'status' => $request->query('status'),
            ]);
        } catch (MatchingException $exception) {
            return $this->error($request, $exception, 'volunteer/dashboard');
        }

        return $this->view('matching/volunteer', [
            'title'  => 'My matches',
            'user'   => $user,
            'items'  => $result['items'],
            'filter' => $request->query('status'),
        ]);
    }

    /**
     * POST /volunteer/matches/{id}/accept
     */
    public function acceptVolunteerMatch(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $this->service->accept((int) $id, $user);
            flash('success', 'Match accepted. You are now marked BUSY for this assignment.');
        } catch (MatchingException $exception) {
            flash('error', $exception->userMessage());
        }

        return $this->redirect(url('volunteer/matches'));
    }

    /**
     * POST /volunteer/matches/{id}/reject
     */
    public function rejectVolunteerMatch(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $reason = $request->input('reason');
        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

        try {
            $this->service->reject((int) $id, $user, $reason);
            flash('success', 'Match rejected.');
        } catch (MatchingException $exception) {
            flash('error', $exception->userMessage());
        }

        return $this->redirect(url('volunteer/matches'));
    }

    /**
     * POST /volunteer/matches/{id}/complete
     */
    public function completeVolunteerMatch(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $this->service->complete((int) $id, $user);
            flash('success', 'Match completed. Request marked resolved.');
        } catch (MatchingException $exception) {
            flash('error', $exception->userMessage());
        }

        return $this->redirect(url('volunteer/matches'));
    }

    /**
     * GET /requests/{id}/matches — owner / admin match panel.
     */
    public function requestMatches(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $matches = $this->service->matchesForRequest((int) $id, $user);
            $suggestions = null;

            // Auto-refresh suggestions when the request is still open.
            $status = (string) ($matches['meta']['request_status'] ?? '');

            if (in_array($status, MatchingService::ACCEPTABLE_REQUEST_STATUSES, true)
                && in_array((string) ($user['role'] ?? ''), ['CITIZEN', 'ADMIN'], true)) {
                try {
                    $suggestions = $this->service->suggestForRequest((int) $id, $user, true);
                } catch (MatchingException) {
                    $suggestions = null;
                }
            }
        } catch (MatchingException $exception) {
            return $this->error($request, $exception, 'requests');
        }

        return $this->view('matching/request', [
            'title'       => 'Request matches',
            'user'        => $user,
            'request_id'  => (int) $id,
            'items'       => $matches['items'],
            'meta'        => $matches['meta'],
            'suggestions' => $suggestions['items'] ?? [],
            'suggest_meta'=> $suggestions['meta'] ?? null,
        ]);
    }

    /**
     * POST /requests/{id}/matches/suggest — regenerate suggestions.
     */
    public function suggest(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $result = $this->service->suggestForRequest((int) $id, $user, true);
            flash('success', sprintf('Found %d match suggestion(s).', $result['meta']['count'] ?? 0));
        } catch (MatchingException $exception) {
            flash('error', $exception->userMessage());
        }

        return $this->redirect(url('requests/' . (int) $id . '/matches'));
    }

    /**
     * POST /requests/{id}/matches/{matchId}/accept — admin/owner path.
     */
    public function acceptRequestMatch(Request $request, string $id, string $matchId): Response
    {
        $user = $this->viewer($request);

        try {
            $this->service->accept((int) $matchId, $user);
            flash('success', 'Match accepted.');
        } catch (MatchingException $exception) {
            flash('error', $exception->userMessage());
        }

        return $this->redirect(url('requests/' . (int) $id . '/matches'));
    }

    /**
     * POST /requests/{id}/matches/{matchId}/reject
     */
    public function rejectRequestMatch(Request $request, string $id, string $matchId): Response
    {
        $user = $this->viewer($request);
        $reason = $request->input('reason');
        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

        try {
            $this->service->reject((int) $matchId, $user, $reason);
            flash('success', 'Match rejected.');
        } catch (MatchingException $exception) {
            flash('error', $exception->userMessage());
        }

        return $this->redirect(url('requests/' . (int) $id . '/matches'));
    }

    /**
     * POST /requests/{id}/matches/{matchId}/complete
     */
    public function completeRequestMatch(Request $request, string $id, string $matchId): Response
    {
        $user = $this->viewer($request);

        try {
            $this->service->complete((int) $matchId, $user);
            flash('success', 'Match completed.');
        } catch (MatchingException $exception) {
            flash('error', $exception->userMessage());
        }

        return $this->redirect(url('requests/' . (int) $id . '/matches'));
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }

    private function error(Request $request, MatchingException $exception, string $fallback): Response
    {
        if ($request->wantsJson()) {
            return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
        }

        if ($exception->status === 404) {
            return $this->view('errors/404', [
                'status'  => 404,
                'message' => 'Match not found.',
            ], 'layouts/app', 404);
        }

        if ($exception->status === 403) {
            return $this->view('errors/403', [
                'status'  => 403,
                'message' => $exception->userMessage(),
            ], 'layouts/app', 403);
        }

        flash('error', $exception->userMessage());

        return $this->redirect(url($fallback));
    }
}
