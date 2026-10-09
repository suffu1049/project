<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\MatchingException;
use App\Core\Service;
use App\Models\AuditRepository;
use App\Models\EmergencyRequestRepository;
use App\Models\MatchRepository;
use App\Models\OrganizationRepository;
use App\Models\RequestEventRepository;
use App\Models\ResourceRepository;
use App\Models\UserRepository;
use App\Models\VolunteerRepository;

/**
 * Smart matching engine (Phase 7).
 *
 * Deterministic, explainable scoring of emergency requests against volunteer
 * and resource candidates. Business rules live here — controllers stay thin
 * and repositories stay pure persistence.
 *
 * Scoring (config/matching.php — configurable):
 *   capability 50% · availability 20% · distance 20% · verification 10%
 *
 * Rules:
 *   - Candidates must be capability-compatible with the emergency category.
 *   - Only VERIFIED + AVAILABLE volunteers are suggested.
 *   - Only VERIFIED organizations with stock (status ≠ UNAVAILABLE) are suggested.
 *   - Service radius filters volunteers when both sides have coordinates.
 *   - Exact private coordinates are never exposed on suggestions.
 *   - Accept is transactional: re-check request + provider, prevent conflicts,
 *     advance request status, set provider BUSY (volunteers), record events.
 *   - Duplicate active assignments are rejected.
 */
final class MatchingService extends Service
{
    public const TYPES = ['VOLUNTEER', 'RESOURCE'];

    public const STATUSES = ['SUGGESTED', 'NOTIFIED', 'ACCEPTED', 'REJECTED', 'EXPIRED', 'COMPLETED'];

    /** Request statuses that may still receive a match acceptance. */
    public const ACCEPTABLE_REQUEST_STATUSES = ['CREATED', 'VERIFIED', 'MATCHING', 'ESCALATED'];

    public function __construct(
        private readonly NotificationService $notifications = new NotificationService(),
    ) {
    }

    /**
     * Emergency category → volunteer capability heuristic is delegated to
     * VolunteerService::SKILL_CATEGORIES; resource compatibility lives in config.
     */

    /**
     * Compute (and optionally persist) ranked match suggestions for a request.
     *
     * @param array<string, mixed> $query optional: type=VOLUNTEER|RESOURCE|both, limit
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function suggestForRequest(int $requestId, array $viewer, bool $persist = true, array $query = []): array
    {
        $request = $this->loadRequestForViewer($requestId, $viewer);

        $requestStatus = (string) $request['status'];

        if (!in_array($requestStatus, self::ACCEPTABLE_REQUEST_STATUSES, true)) {
            throw MatchingException::requestClosed(sprintf(
                'Request #%d is %s; suggestions are only generated for open requests.',
                $requestId,
                $requestStatus
            ));
        }

        $accepted = MatchRepository::acceptedForRequest($requestId);
        $typeFilter = strtoupper(trim((string) ($query['type'] ?? 'BOTH')));
        if ($typeFilter === '' || $typeFilter === 'ALL') {
            $typeFilter = 'BOTH';
        }

        if (!in_array($typeFilter, ['BOTH', 'VOLUNTEER', 'RESOURCE'], true)) {
            throw MatchingException::validation(
                ['type' => ['Match type must be BOTH, VOLUNTEER or RESOURCE.']],
                'Validation failed.'
            );
        }

        $limit = isset($query['limit']) && is_numeric($query['limit'])
            ? max(1, min(50, (int) $query['limit']))
            : (int) Config::get('matching.max_suggestions', 10);

        $volunteerItems = [];
        $resourceItems = [];

        if ($accepted === null || in_array($typeFilter, ['BOTH', 'VOLUNTEER'], true)) {
            if ($typeFilter !== 'RESOURCE') {
                $volunteerItems = $this->scoreVolunteerCandidates($request);
            }
        }

        if ($accepted === null || in_array($typeFilter, ['BOTH', 'RESOURCE'], true)) {
            if ($typeFilter !== 'VOLUNTEER') {
                $resourceItems = $this->scoreResourceCandidates($request);
            }
        }

        // Rank: higher score first, then closer distance, then lower id.
        $rank = static function (array $a, array $b): int {
            $scoreA = (float) ($a['match_score'] ?? 0);
            $scoreB = (float) ($b['match_score'] ?? 0);

            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            $da = $a['distance_km'] ?? null;
            $db = $b['distance_km'] ?? null;

            if ($da !== null && $db !== null && (float) $da !== (float) $db) {
                return (float) $da <=> (float) $db;
            }

            if ($da !== null && $db === null) {
                return -1;
            }

            if ($da === null && $db !== null) {
                return 1;
            }

            return 0;
        };

        usort($volunteerItems, $rank);
        usort($resourceItems, $rank);

        // Persist only when the request has no accepted match yet.
        if ($persist && $accepted === null) {
            $volunteerItems = $this->persistSuggestions($requestId, $volunteerItems, 'VOLUNTEER', $limit, $request);
            $resourceItems = $this->persistSuggestions($requestId, $resourceItems, 'RESOURCE', $limit, $request);
        }

        $items = array_merge(
            array_slice($volunteerItems, 0, $limit),
            array_slice($resourceItems, 0, $limit)
        );

        return [
            'items' => array_map(
                fn (array $row): array => $this->presentSuggestion($row, $request, $viewer),
                $items
            ),
            'meta'  => [
                'request_id'          => $requestId,
                'request_status'      => $requestStatus,
                'type_filter'         => $typeFilter,
                'has_accepted_match'  => $accepted !== null,
                'volunteer_candidates'=> count($volunteerItems),
                'resource_candidates' => count($resourceItems),
                'weights'             => $this->weights(),
                'persisted'           => $persist && $accepted === null,
                'count'               => count($items),
            ],
        ];
    }

    /**
     * Stored matches for a request (owner / eligible volunteer / admin).
     *
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function matchesForRequest(int $requestId, array $viewer): array
    {
        $request = $this->loadRequestForViewer($requestId, $viewer);
        $rows = MatchRepository::forRequest($requestId);

        $items = [];

        foreach ($rows as $row) {
            $items[] = $this->presentMatch($row, $viewer, $request);
        }

        return [
            'items' => $items,
            'meta'  => [
                'request_id'     => $requestId,
                'request_status' => (string) $request['status'],
                'count'          => count($items),
            ],
        ];
    }

    /**
     * Matches visible to the caller (volunteer / organization / admin).
     *
     * @return array{items: array<int, array<string, mixed>>, pagination: array{page:int,limit:int,total:int,pages:int}}
     */
    public function listMatches(array $viewer, array $query = []): array
    {
        $role = (string) ($viewer['role'] ?? '');
        $status = isset($query['status']) && is_string($query['status']) && $query['status'] !== ''
            ? strtoupper(trim($query['status']))
            : null;

        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw MatchingException::invalidStatus(
                'Status must be one of: ' . implode(', ', self::STATUSES) . '.'
            );
        }

        $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : 1;
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? min(100, max(1, (int) $query['limit'])) : 20;

        if ($role === 'ADMIN') {
            $sql = 'SELECT m.* FROM matches m';
            $bindings = [];
            $where = [];

            if ($status !== null) {
                $where[] = 'm.status = :status';
                $bindings['status'] = $status;
            }

            $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
            $total = (int) (Database::selectOne('SELECT COUNT(*) AS aggregate FROM matches m' . $clause, $bindings)['aggregate'] ?? 0);
            $offset = ($page - 1) * $limit;
            $rows = Database::select(
                'SELECT m.* FROM matches m' . $clause . ' ORDER BY m.updated_at DESC, m.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
                $bindings
            );
        } elseif ($role === 'VOLUNTEER') {
            $profile = VolunteerRepository::findByUserId((int) ($viewer['id'] ?? 0));

            if ($profile === null) {
                return [
                    'items'      => [],
                    'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'pages' => 0],
                ];
            }

            $rows = MatchRepository::forVolunteer((int) $profile['id'], $status, $limit * 50);
            $total = count($rows);
            $rows = array_slice($rows, ($page - 1) * $limit, $limit);
        } elseif ($role === 'ORGANIZATION') {
            $org = OrganizationRepository::findByUserId((int) ($viewer['id'] ?? 0));

            if ($org === null) {
                return [
                    'items'      => [],
                    'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'pages' => 0],
                ];
            }

            $rows = MatchRepository::forOrganizationResources((int) $org['id'], $status, $limit * 50);
            $total = count($rows);
            $rows = array_slice($rows, ($page - 1) * $limit, $limit);
        } else {
            throw MatchingException::forbidden();
        }

        return [
            'items'      => array_map(fn (array $row): array => $this->presentMatch($row, $viewer), $rows),
            'pagination' => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $total === 0 ? 0 : (int) ceil($total / $limit),
            ],
        ];
    }

    /**
     * Single match read (owner of the volunteer/resource / admin).
     *
     * @return array<string, mixed>
     */
    public function show(int $matchId, array $viewer): array
    {
        $match = MatchRepository::find($matchId);

        if ($match === null) {
            throw MatchingException::notFound();
        }

        $this->authorizeMatchAccess($match, $viewer);

        $request = EmergencyRequestRepository::find((int) $match['request_id']);

        return $this->presentMatch($match, $viewer, $request);
    }

    /**
     * Accept a suggested/notified match — one atomic unit of work.
     *
     * @return array<string, mixed>
     */
    public function accept(int $matchId, array $viewer): array
    {
        $match = MatchRepository::find($matchId);

        if ($match === null) {
            throw MatchingException::notFound();
        }

        $this->authorizeMatchAction($match, $viewer, ['SUGGESTED', 'NOTIFIED']);

        $requestId = (int) $match['request_id'];
        $request = EmergencyRequestRepository::find($requestId);

        if ($request === null) {
            throw MatchingException::requestNotFound();
        }

        $requestStatus = (string) $request['status'];

        if (!in_array($requestStatus, self::ACCEPTABLE_REQUEST_STATUSES, true)) {
            throw MatchingException::requestClosed(sprintf(
                'Request #%d is %s and can no longer be accepted.',
                $requestId,
                $requestStatus
            ));
        }

        $existingAccepted = MatchRepository::acceptedForRequest($requestId);

        if ($existingAccepted !== null && (int) $existingAccepted['id'] !== $matchId) {
            throw MatchingException::conflict('This request already has an accepted match.');
        }

        $actorId = (int) ($viewer['id'] ?? 0);

        $result = Database::transaction(function () use ($match, $matchId, $request, $requestId, $requestStatus, $actorId, $viewer): array {
            if (!empty($match['volunteer_id'])) {
                $this->assertVolunteerCanAccept((int) $match['volunteer_id'], $matchId, $viewer);
            } else {
                $this->assertResourceCanAccept((int) $match['resource_id']);
            }

            MatchRepository::updateStatus($matchId, 'ACCEPTED');

            // Advance the request through legal intermediate states to ACCEPTED.
            $this->advanceRequestToAccepted($requestId, $requestStatus, $actorId, $match);

            if (!empty($match['volunteer_id'])) {
                VolunteerRepository::updateAvailability((int) $match['volunteer_id'], 'BUSY');
            }

            RequestEventRepository::record(
                $requestId,
                'MATCH_ACCEPTED',
                $actorId,
                $requestStatus,
                'ACCEPTED',
                $this->acceptNote($match)
            );

            AuditRepository::record(
                $actorId,
                'ACCEPT_MATCH',
                'match',
                $matchId,
                [
                    'request_id'    => $requestId,
                    'type'          => !empty($match['volunteer_id']) ? 'VOLUNTEER' : 'RESOURCE',
                    'from_status'   => (string) $match['status'],
                    'match_score'   => (float) ($match['match_score'] ?? 0),
                ]
            );

            $updated = MatchRepository::find($matchId);

            if ($updated === null) {
                throw MatchingException::notFound();
            }

            $updatedRequest = EmergencyRequestRepository::find($requestId);

            return [
                'match'   => $this->presentMatch($updated, $viewer, $updatedRequest),
                'request' => $updatedRequest !== null ? $this->presentRequestSummary($updatedRequest, $viewer) : null,
            ];
        });

        // Phase 8: notify parties after the accept transaction commits.
        $this->notifications->matchAccepted($result['match'] ?? [], EmergencyRequestRepository::find((int) ($result['match']['request_id'] ?? 0)), $actorId);

        return $result;
    }

    /**
     * Reject a suggested/notified match.
     *
     * @return array<string, mixed>
     */
    public function reject(int $matchId, array $viewer, ?string $reason = null): array
    {
        $match = MatchRepository::find($matchId);

        if ($match === null) {
            throw MatchingException::notFound();
        }

        $this->authorizeMatchAction($match, $viewer, ['SUGGESTED', 'NOTIFIED']);

        $requestId = (int) $match['request_id'];
        $actorId = (int) ($viewer['id'] ?? 0);
        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;

        return Database::transaction(function () use ($match, $matchId, $requestId, $actorId, $reason, $viewer): array {
            MatchRepository::updateStatus($matchId, 'REJECTED');

            RequestEventRepository::record(
                $requestId,
                'MATCH_REJECTED',
                $actorId,
                (string) $match['status'],
                'REJECTED',
                $reason ?? $this->rejectNote($match)
            );

            AuditRepository::record(
                $actorId,
                'REJECT_MATCH',
                'match',
                $matchId,
                [
                    'request_id'  => $requestId,
                    'from_status' => (string) $match['status'],
                    'reason'      => $reason,
                ]
            );

            $updated = MatchRepository::find($matchId);

            if ($updated === null) {
                throw MatchingException::notFound();
            }

            return [
                'match'   => $this->presentMatch($updated, $viewer),
                'request' => null,
            ];
        });
    }

    /**
     * Mark an accepted match completed and resolve the request when appropriate.
     *
     * @return array<string, mixed>
     */
    public function complete(int $matchId, array $viewer): array
    {
        $match = MatchRepository::find($matchId);

        if ($match === null) {
            throw MatchingException::notFound();
        }

        $this->authorizeMatchAction($match, $viewer, ['ACCEPTED']);

        $requestId = (int) $match['request_id'];
        $request = EmergencyRequestRepository::find($requestId);

        if ($request === null) {
            throw MatchingException::requestNotFound();
        }

        $requestStatus = (string) $request['status'];

        if (!in_array($requestStatus, ['ACCEPTED', 'IN_PROGRESS'], true)) {
            throw MatchingException::requestClosed(sprintf(
                'Request #%d is %s; only ACCEPTED or IN_PROGRESS requests can be completed via a match.',
                $requestId,
                $requestStatus
            ));
        }

        $actorId = (int) ($viewer['id'] ?? 0);

        $result = Database::transaction(function () use ($match, $matchId, $requestId, $requestStatus, $actorId, $viewer): array {
            MatchRepository::updateStatus($matchId, 'COMPLETED');

            // ACCEPTED → IN_PROGRESS → RESOLVED when still on ACCEPTED.
            if ($requestStatus === 'ACCEPTED') {
                EmergencyRequestRepository::updateStatus($requestId, 'IN_PROGRESS');
                RequestEventRepository::record(
                    $requestId,
                    'STATUS_CHANGED',
                    $actorId,
                    'ACCEPTED',
                    'IN_PROGRESS',
                    'Match completed; work started.'
                );
            }

            EmergencyRequestRepository::updateStatus($requestId, 'RESOLVED', date('Y-m-d H:i:s'));
            RequestEventRepository::record(
                $requestId,
                'STATUS_CHANGED',
                $actorId,
                $requestStatus === 'ACCEPTED' ? 'IN_PROGRESS' : 'IN_PROGRESS',
                'RESOLVED',
                'Match completed; request resolved.'
            );

            RequestEventRepository::record(
                $requestId,
                'MATCH_COMPLETED',
                $actorId,
                'ACCEPTED',
                'COMPLETED',
                $this->completeNote($match)
            );

            // Free the volunteer for new work once the assignment ends.
            if (!empty($match['volunteer_id'])) {
                VolunteerRepository::updateAvailability((int) $match['volunteer_id'], 'AVAILABLE');
            }

            AuditRepository::record(
                $actorId,
                'COMPLETE_MATCH',
                'match',
                $matchId,
                ['request_id' => $requestId]
            );

            $updated = MatchRepository::find($matchId);
            $updatedRequest = EmergencyRequestRepository::find($requestId);

            if ($updated === null) {
                throw MatchingException::notFound();
            }

            return [
                'match'   => $this->presentMatch($updated, $viewer, $updatedRequest),
                'request' => $updatedRequest !== null ? $this->presentRequestSummary($updatedRequest, $viewer) : null,
            ];
        });

        // Phase 8: request resolution notification after commit.
        $completedRequest = EmergencyRequestRepository::find($requestId);

        if ($completedRequest !== null && (string) $completedRequest['status'] === 'RESOLVED') {
            $this->notifications->statusChanged($completedRequest, $requestStatus, 'RESOLVED', $actorId);
        }

        return $result;
    }

    // ---------------------------------------------------------------------
    // Scoring
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $request
     * @return array<int, array<string, mixed>>
     */
    private function scoreVolunteerCandidates(array $request): array
    {
        $volunteers = VolunteerRepository::allWithUsers(null, 500);
        $category = (string) $request['category'];
        $weights = $this->weights();
        $unknownDist = (float) Config::get('matching.distance_unknown_score', 0.5);

        $items = [];

        foreach ($volunteers as $profile) {
            if ((string) ($profile['verification_status'] ?? '') !== 'VERIFIED') {
                continue;
            }

            if ((string) ($profile['availability'] ?? '') !== 'AVAILABLE') {
                continue;
            }

            if ((string) ($profile['user_status'] ?? '') !== 'ACTIVE') {
                continue;
            }

            $skills = VolunteerService::skillTokens($profile['skills'] ?? null);
            [$capable, $capScore, $capReasons] = $this->volunteerCapability($skills, $category);

            if (!$capable) {
                continue;
            }

            $distance = null;
            $distScore = $unknownDist;
            $profileLat = $profile['latitude'] !== null ? (float) $profile['latitude'] : null;
            $profileLng = $profile['longitude'] !== null ? (float) $profile['longitude'] : null;
            $requestLat = $request['latitude'] !== null ? (float) $request['latitude'] : null;
            $requestLng = $request['longitude'] !== null ? (float) $request['longitude'] : null;
            $radius = $profile['service_radius_km'] !== null ? (float) $profile['service_radius_km'] : null;

            if ($profileLat !== null && $profileLng !== null && $requestLat !== null && $requestLng !== null) {
                $distance = VolunteerService::distanceKm($profileLat, $profileLng, $requestLat, $requestLng);

                if ($radius !== null && $radius > 0 && $distance > $radius) {
                    continue;
                }

                $distScore = $radius !== null && $radius > 0
                    ? max(0.0, 1.0 - ($distance / $radius))
                    : ($distance <= 5.0 ? 1.0 : max(0.0, 1.0 - ($distance / 50.0)));
            }

            $matchScore = 100.0 * (
                $weights['capability'] * $capScore
                + $weights['availability'] * 1.0
                + $weights['distance'] * $distScore
                + $weights['verification'] * 1.0
            );

            $reasons = $capReasons;
            $reasons[] = 'AVAILABLE';

            if ($distance !== null) {
                $reasons[] = sprintf(
                    '%s km away%s',
                    number_format($distance, 1),
                    $radius !== null ? sprintf(' (within %s km)', number_format($radius, 1)) : ''
                );
            } else {
                $reasons[] = 'Distance unknown (location not shared)';
            }

            $reasons[] = 'VERIFIED';

            $items[] = [
                'type'              => 'VOLUNTEER',
                'volunteer_id'      => (int) $profile['id'],
                'resource_id'       => null,
                'organization_id'   => null,
                'name'              => (string) ($profile['name'] ?? $profile['user_name'] ?? 'Volunteer'),
                'skills'            => $skills,
                'vehicle'           => $profile['vehicle'] !== null ? (string) $profile['vehicle'] : null,
                'availability'      => (string) ($profile['availability'] ?? ''),
                'distance_km'       => $distance !== null ? round($distance, 3) : null,
                'capability_score'  => round($capScore * 100, 2),
                'match_score'       => round($matchScore, 2),
                'reasons'           => $reasons,
                'resource_summary'  => null,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<int, array<string, mixed>>
     */
    private function scoreResourceCandidates(array $request): array
    {
        $category = (string) $request['category'];
        $compatibility = (array) Config::get('matching.resource_compatibility', []);
        $allowedTypes = $compatibility[$category] ?? null;
        $weights = $this->weights();
        $unknownDist = (float) Config::get('matching.distance_unknown_score', 0.5);

        $resources = Database::select(
            "SELECT r.*, o.name AS organization_name, o.verification_status AS organization_verification_status,
                    o.latitude AS organization_latitude, o.longitude AS organization_longitude
             FROM resources r
             INNER JOIN organizations o ON o.id = r.organization_id
             WHERE r.status <> 'UNAVAILABLE'
               AND r.available_quantity > 0
               AND o.verification_status = 'VERIFIED'
             ORDER BY r.id ASC"
        );

        $items = [];

        foreach ($resources as $resource) {
            $type = (string) $resource['resource_type'];

            if ($allowedTypes === null) {
                continue;
            }

            // Empty compatibility list for a category means "any type".
            if ($allowedTypes !== [] && !in_array($type, $allowedTypes, true)) {
                continue;
            }

            $capScore = $allowedTypes === [] ? 0.7 : 1.0;
            $distance = null;
            $distScore = $unknownDist;

            $orgLat = $resource['organization_latitude'] !== null ? (float) $resource['organization_latitude'] : null;
            $orgLng = $resource['organization_longitude'] !== null ? (float) $resource['organization_longitude'] : null;
            $requestLat = $request['latitude'] !== null ? (float) $request['latitude'] : null;
            $requestLng = $request['longitude'] !== null ? (float) $request['longitude'] : null;

            if ($orgLat !== null && $orgLng !== null && $requestLat !== null && $requestLng !== null) {
                $distance = VolunteerService::distanceKm($orgLat, $orgLng, $requestLat, $requestLng);
                $distScore = $distance <= 5.0 ? 1.0 : max(0.0, 1.0 - ($distance / 50.0));
            }

            $matchScore = 100.0 * (
                $weights['capability'] * $capScore
                + $weights['availability'] * 1.0
                + $weights['distance'] * $distScore
                + $weights['verification'] * 1.0
            );

            $reasons = [
                sprintf(
                    '%s resource available (%s %s)',
                    ucwords(strtolower(str_replace('_', ' ', $type))),
                    number_format((int) $resource['available_quantity']),
                    (string) ($resource['unit'] ?? 'units')
                ),
                'AVAILABLE',
            ];

            if ($distance !== null) {
                $reasons[] = sprintf('%s km away', number_format($distance, 1));
            } else {
                $reasons[] = 'Distance unknown (location not shared)';
            }

            $reasons[] = 'VERIFIED organization';

            $items[] = [
                'type'              => 'RESOURCE',
                'volunteer_id'      => null,
                'resource_id'       => (int) $resource['id'],
                'organization_id'   => (int) $resource['organization_id'],
                'name'              => (string) $resource['resource_name'],
                'skills'            => [],
                'vehicle'           => null,
                'availability'      => 'AVAILABLE',
                'distance_km'       => $distance !== null ? round($distance, 3) : null,
                'capability_score'  => round($capScore * 100, 2),
                'match_score'       => round($matchScore, 2),
                'reasons'           => $reasons,
                'resource_summary'  => [
                    'resource_type'      => $type,
                    'resource_name'      => (string) $resource['resource_name'],
                    'available_quantity' => (int) $resource['available_quantity'],
                    'quantity'           => (int) $resource['quantity'],
                    'unit'               => $resource['unit'] !== null ? (string) $resource['unit'] : null,
                    'organization_name'  => (string) ($resource['organization_name'] ?? ''),
                    'status'             => (string) $resource['status'],
                ],
            ];
        }

        return $items;
    }

    /**
     * @param array<int, string> $skills
     * @return array{0: bool, 1: float, 2: array<int, string>}
     */
    private function volunteerCapability(array $skills, string $category): array
    {
        $volunteer = new VolunteerService();

        if ($skills === []) {
            if (in_array($category, ['OTHER', 'SHELTER', 'ACCIDENT'], true)) {
                return [true, 0.5, ['General assistance (no declared skills yet)']];
            }

            return [false, 0.0, []];
        }

        if (in_array('GENERAL', $skills, true)) {
            return [true, 0.85, ['GENERAL skill covers this category']];
        }

        $matchedSkills = [];

        foreach ($skills as $skill) {
            $categories = VolunteerService::SKILL_CATEGORIES[$skill] ?? [];

            if (in_array($category, $categories, true)) {
                $matchedSkills[] = $skill;
            }
        }

        if ($matchedSkills === []) {
            return [false, 0.0, []];
        }

        $reasons = [implode(' + ', $matchedSkills) . ' skill matches ' . $category];

        return [true, 1.0, $reasons];
    }

    /**
     * Upsert scored candidates as SUGGESTED match rows (no duplicates).
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function persistSuggestions(int $requestId, array $candidates, string $type, int $limit, ?array $request = null): array
    {
        $out = [];

        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            $isNew = false;

            if ($type === 'VOLUNTEER') {
                $existing = MatchRepository::openVolunteerMatch($requestId, (int) $candidate['volunteer_id']);

                if ($existing !== null) {
                    if ((string) $existing['status'] === 'ACCEPTED') {
                        continue;
                    }

                    MatchRepository::updateScores((int) $existing['id'], [
                        'match_score'      => (float) $candidate['match_score'],
                        'capability_score' => (float) $candidate['capability_score'],
                        'distance_km'      => $candidate['distance_km'],
                        'status'           => 'SUGGESTED',
                    ]);

                    $candidate['id'] = (int) $existing['id'];
                    $candidate['status'] = 'SUGGESTED';
                    $out[] = $candidate;

                    continue;
                }

                if (MatchRepository::volunteerHasOtherAcceptance((int) $candidate['volunteer_id'])) {
                    continue;
                }

                $id = MatchRepository::createMatch([
                    'request_id'       => $requestId,
                    'volunteer_id'     => (int) $candidate['volunteer_id'],
                    'resource_id'      => null,
                    'distance_km'      => $candidate['distance_km'],
                    'capability_score' => (float) $candidate['capability_score'],
                    'match_score'      => (float) $candidate['match_score'],
                    'status'           => 'SUGGESTED',
                ]);
                $isNew = true;
            } else {
                $existing = MatchRepository::openResourceMatch($requestId, (int) $candidate['resource_id']);

                if ($existing !== null) {
                    if ((string) $existing['status'] === 'ACCEPTED') {
                        continue;
                    }

                    MatchRepository::updateScores((int) $existing['id'], [
                        'match_score'      => (float) $candidate['match_score'],
                        'capability_score' => (float) $candidate['capability_score'],
                        'distance_km'      => $candidate['distance_km'],
                        'status'           => 'SUGGESTED',
                    ]);

                    $candidate['id'] = (int) $existing['id'];
                    $candidate['status'] = 'SUGGESTED';
                    $out[] = $candidate;

                    continue;
                }

                $id = MatchRepository::createMatch([
                    'request_id'       => $requestId,
                    'volunteer_id'     => null,
                    'resource_id'      => (int) $candidate['resource_id'],
                    'distance_km'      => $candidate['distance_km'],
                    'capability_score' => (float) $candidate['capability_score'],
                    'match_score'      => (float) $candidate['match_score'],
                    'status'           => 'SUGGESTED',
                ]);
                $isNew = true;
            }

            $candidate['id'] = $id;
            $candidate['status'] = 'SUGGESTED';
            $out[] = $candidate;

            // Phase 8: notify newly suggested providers (best-effort).
            if ($isNew && $request !== null) {
                $this->notifications->matchSuggested($candidate, $request);
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Authorization / lifecycle helpers
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function loadRequestForViewer(int $requestId, array $viewer): array
    {
        $request = EmergencyRequestRepository::find($requestId);

        if ($request === null) {
            throw MatchingException::requestNotFound();
        }

        $role = (string) ($viewer['role'] ?? '');
        $userId = (int) ($viewer['id'] ?? 0);

        if ($role === 'ADMIN') {
            return $request;
        }

        if ((int) ($request['user_id'] ?? 0) === $userId) {
            return $request;
        }

        // Volunteers may read matches for requests they are eligible for.
        if ($role === 'VOLUNTEER') {
            $profile = VolunteerRepository::findByUserId($userId);

            if ($profile !== null && in_array((string) $profile['verification_status'], ['VERIFIED', 'PENDING'], true)) {
                return $request;
            }
        }

        // Organizations may read matches that reference their resources.
        if ($role === 'ORGANIZATION') {
            $org = OrganizationRepository::findByUserId($userId);

            if ($org !== null) {
                foreach (MatchRepository::forRequest($requestId) as $row) {
                    if (!empty($row['resource_id'])) {
                        $resource = ResourceRepository::find((int) $row['resource_id']);

                        if ($resource !== null && (int) $resource['organization_id'] === (int) $org['id']) {
                            return $request;
                        }
                    }
                }
            }
        }

        throw MatchingException::forbidden();
    }

    /**
     * @param array<string, mixed> $match
     */
    private function authorizeMatchAccess(array $match, array $viewer): void
    {
        $role = (string) ($viewer['role'] ?? '');
        $userId = (int) ($viewer['id'] ?? 0);

        if ($role === 'ADMIN') {
            return;
        }

        if (!empty($match['volunteer_id']) && $role === 'VOLUNTEER') {
            $profile = VolunteerRepository::findByUserId($userId);

            if ($profile !== null && (int) $profile['id'] === (int) $match['volunteer_id']) {
                return;
            }
        }

        if (!empty($match['resource_id']) && $role === 'ORGANIZATION') {
            $org = OrganizationRepository::findByUserId($userId);
            $resource = ResourceRepository::find((int) $match['resource_id']);

            if ($org !== null && $resource !== null && (int) $resource['organization_id'] === (int) $org['id']) {
                return;
            }
        }

        // Request owners can view matches on their own requests.
        $request = EmergencyRequestRepository::find((int) $match['request_id']);

        if ($request !== null && (int) ($request['user_id'] ?? 0) === $userId) {
            return;
        }

        throw MatchingException::forbidden();
    }

    /**
     * @param array<string, mixed> $match
     * @param array<int, string> $allowedStatuses
     */
    private function authorizeMatchAction(array $match, array $viewer, array $allowedStatuses): void
    {
        $this->authorizeMatchAccess($match, $viewer);

        $status = (string) ($match['status'] ?? '');

        if (!in_array($status, $allowedStatuses, true)) {
            throw MatchingException::invalidStatus(sprintf(
                'Match is %s; this action requires status %s.',
                $status,
                implode(' or ', $allowedStatuses)
            ));
        }

        $role = (string) ($viewer['role'] ?? '');

        if ($role === 'ADMIN') {
            return;
        }

        // The acting volunteer / organization must own the match target.
        if (!empty($match['volunteer_id'])) {
            if ($role !== 'VOLUNTEER') {
                throw MatchingException::forbidden();
            }

            $profile = VolunteerRepository::findByUserId((int) ($viewer['id'] ?? 0));

            if ($profile === null || (int) $profile['id'] !== (int) $match['volunteer_id']) {
                throw MatchingException::forbidden();
            }

            return;
        }

        if (!empty($match['resource_id'])) {
            if ($role !== 'ORGANIZATION') {
                throw MatchingException::forbidden();
            }

            $org = OrganizationRepository::findByUserId((int) ($viewer['id'] ?? 0));
            $resource = ResourceRepository::find((int) $match['resource_id']);

            if ($org === null || $resource === null || (int) $resource['organization_id'] !== (int) $org['id']) {
                throw MatchingException::forbidden();
            }

            return;
        }

        throw MatchingException::forbidden();
    }

    private function assertVolunteerCanAccept(int $volunteerId, int $matchId, array $viewer): void
    {
        $profile = VolunteerRepository::find($volunteerId);

        if ($profile === null) {
            throw MatchingException::notEligible('Volunteer profile not found.');
        }

        $role = (string) ($viewer['role'] ?? '');

        // Admin may accept on behalf of operations; volunteers must be eligible.
        if ($role !== 'ADMIN') {
            if ((string) $profile['verification_status'] !== 'VERIFIED') {
                throw MatchingException::notEligible('Volunteer must be verified before accepting a match.');
            }

            if ((string) $profile['availability'] !== 'AVAILABLE') {
                throw MatchingException::notEligible('Volunteer must be AVAILABLE to accept a match.');
            }
        } elseif ((string) $profile['verification_status'] !== 'VERIFIED') {
            throw MatchingException::notEligible('Volunteer must be verified before accepting a match.');
        }

        if (MatchRepository::volunteerHasOtherAcceptance($volunteerId, $matchId)) {
            throw MatchingException::conflict('Volunteer already has an active accepted assignment.');
        }
    }

    private function assertResourceCanAccept(int $resourceId): void
    {
        $resource = ResourceRepository::find($resourceId);

        if ($resource === null) {
            throw MatchingException::notEligible('Resource not found.');
        }

        if ((string) $resource['status'] === 'UNAVAILABLE' || (int) $resource['available_quantity'] <= 0) {
            throw MatchingException::notEligible('Resource has no available stock to commit.');
        }

        $organization = OrganizationRepository::find((int) $resource['organization_id']);

        if ($organization === null || (string) $organization['verification_status'] !== 'VERIFIED') {
            throw MatchingException::notEligible('Resource organization must be verified.');
        }
    }

    /**
     * Advance CREATED/VERIFIED/ESCALATED requests to ACCEPTED with timeline events.
     *
     * @param array<string, mixed> $match
     */
    private function advanceRequestToAccepted(int $requestId, string $fromStatus, int $actorId, array $match): void
    {
        $path = match ($fromStatus) {
            'CREATED'   => ['VERIFIED', 'MATCHING', 'ACCEPTED'],
            'VERIFIED'  => ['MATCHING', 'ACCEPTED'],
            'MATCHING'  => ['ACCEPTED'],
            'ESCALATED' => ['MATCHING', 'ACCEPTED'],
            default     => [],
        };

        $current = $fromStatus;

        foreach ($path as $next) {
            EmergencyRequestRepository::updateStatus($requestId, $next);
            RequestEventRepository::record(
                $requestId,
                'STATUS_CHANGED',
                $actorId,
                $current,
                $next,
                sprintf('Automatic transition on match acceptance (%s).', $this->targetLabel($match))
            );
            $current = $next;
        }
    }

    /**
     * @param array<string, mixed> $match
     */
    private function acceptNote(array $match): string
    {
        return sprintf(
            'Match accepted: %s (score %s).',
            $this->targetLabel($match),
            number_format((float) ($match['match_score'] ?? 0), 1)
        );
    }

    /**
     * @param array<string, mixed> $match
     */
    private function rejectNote(array $match): string
    {
        return sprintf('Match rejected: %s.', $this->targetLabel($match));
    }

    /**
     * @param array<string, mixed> $match
     */
    private function completeNote(array $match): string
    {
        return sprintf('Match completed: %s.', $this->targetLabel($match));
    }

    /**
     * @param array<string, mixed> $match
     */
    private function targetLabel(array $match): string
    {
        if (!empty($match['volunteer_id'])) {
            $profile = VolunteerRepository::find((int) $match['volunteer_id']);

            if ($profile !== null) {
                $user = UserRepository::find((int) ($profile['user_id'] ?? 0));

                if ($user !== null) {
                    return 'volunteer ' . (string) $user['name'];
                }

                return 'volunteer #' . (int) $profile['id'];
            }

            return 'volunteer #' . (int) $match['volunteer_id'];
        }

        if (!empty($match['resource_id'])) {
            $resource = ResourceRepository::find((int) $match['resource_id']);

            if ($resource !== null) {
                return sprintf(
                    'resource %s (#%d)',
                    (string) $resource['resource_name'],
                    (int) $resource['id']
                );
            }

            return 'resource #' . (int) $match['resource_id'];
        }

        return 'match #' . (int) ($match['id'] ?? 0);
    }

    /**
     * @return array{capability: float, availability: float, distance: float, verification: float}
     */
    private function weights(): array
    {
        $raw = (array) Config::get('matching.weights', []);

        return [
            'capability'   => (float) ($raw['capability'] ?? 0.50),
            'availability' => (float) ($raw['availability'] ?? 0.20),
            'distance'     => (float) ($raw['distance'] ?? 0.20),
            'verification' => (float) ($raw['verification'] ?? 0.10),
        ];
    }

    /**
     * Persisted-row presentation for owner/admin/volunteer views.
     *
     * @param array<string, mixed> $match
     * @param array<string, mixed>|null $request
     * @return array<string, mixed>
     */
    private function presentMatch(array $match, array $viewer, ?array $request = null): array
    {
        $request = $request ?? EmergencyRequestRepository::find((int) $match['request_id']);
        $type = !empty($match['volunteer_id']) ? 'VOLUNTEER' : 'RESOURCE';
        $canSeePrivate = (string) ($viewer['role'] ?? '') === 'ADMIN'
            || (int) ($request['user_id'] ?? 0) === (int) ($viewer['id'] ?? 0);

        $item = [
            'id'                => (int) $match['id'],
            'type'              => $type,
            'request_id'        => (int) $match['request_id'],
            'volunteer_id'      => $match['volunteer_id'] !== null ? (int) $match['volunteer_id'] : null,
            'resource_id'       => $match['resource_id'] !== null ? (int) $match['resource_id'] : null,
            'distance_km'       => $match['distance_km'] !== null ? (float) $match['distance_km'] : null,
            'capability_score'  => (float) ($match['capability_score'] ?? 0),
            'match_score'       => (float) ($match['match_score'] ?? 0),
            'status'            => (string) $match['status'],
            'reasons'           => $this->reconstructReasons($match, $type),
            'created_at'        => (string) ($match['created_at'] ?? ''),
            'updated_at'        => (string) ($match['updated_at'] ?? ''),
            'request'           => $request !== null ? $this->presentRequestSummary($request, $viewer) : null,
        ];

        if ($type === 'VOLUNTEER') {
            $profile = VolunteerRepository::find((int) $match['volunteer_id']);
            $item['provider'] = null;

            if ($profile !== null) {
                $user = UserRepository::find((int) ($profile['user_id'] ?? 0));
                $item['provider'] = [
                    'name'                => (string) ($user['name'] ?? 'Volunteer'),
                    'skills'              => VolunteerService::skillTokens($profile['skills'] ?? null),
                    'vehicle'             => $profile['vehicle'] !== null ? (string) $profile['vehicle'] : null,
                    'availability'        => (string) ($profile['availability'] ?? ''),
                    'verification_status' => (string) ($profile['verification_status'] ?? ''),
                    // Never expose phone/email on match payloads.
                    'has_location'        => $profile['latitude'] !== null && $profile['longitude'] !== null,
                ];
            }
        } else {
            $resource = ResourceRepository::find((int) $match['resource_id']);
            $item['provider'] = null;

            if ($resource !== null) {
                $org = OrganizationRepository::find((int) $resource['organization_id']);
                $item['provider'] = [
                    'resource_type'      => (string) $resource['resource_type'],
                    'resource_name'      => (string) $resource['resource_name'],
                    'available_quantity' => (int) $resource['available_quantity'],
                    'quantity'           => (int) $resource['quantity'],
                    'unit'               => $resource['unit'] !== null ? (string) $resource['unit'] : null,
                    'status'             => (string) $resource['status'],
                    'organization_name'  => (string) ($org['name'] ?? ''),
                    'organization_verification_status' => (string) ($org['verification_status'] ?? ''),
                ];
            }
        }

        // Owners/admins keep full request context; volunteers keep the summary
        // without exact coordinates until the match is accepted.
        if ($request !== null && !$canSeePrivate && (string) $match['status'] !== 'ACCEPTED' && (string) $match['status'] !== 'COMPLETED') {
            $item['request']['latitude'] = null;
            $item['request']['longitude'] = null;
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $row candidate before persistence
     * @param array<string, mixed> $request
     * @param array<string, mixed> $viewer
     * @return array<string, mixed>
     */
    private function presentSuggestion(array $row, array $request, array $viewer): array
    {
        $item = [
            'id'               => $row['id'] ?? null,
            'type'             => (string) $row['type'],
            'request_id'       => (int) $request['id'],
            'volunteer_id'     => $row['volunteer_id'] ?? null,
            'resource_id'      => $row['resource_id'] ?? null,
            'organization_id'  => $row['organization_id'] ?? null,
            'name'             => (string) ($row['name'] ?? ''),
            'skills'           => (array) ($row['skills'] ?? []),
            'vehicle'          => $row['vehicle'] ?? null,
            'availability'     => (string) ($row['availability'] ?? ''),
            'distance_km'      => $row['distance_km'] ?? null,
            'capability_score' => (float) ($row['capability_score'] ?? 0),
            'match_score'      => (float) ($row['match_score'] ?? 0),
            'status'           => (string) ($row['status'] ?? 'SUGGESTED'),
            'reasons'          => (array) ($row['reasons'] ?? []),
            'resource_summary' => $row['resource_summary'] ?? null,
            'created_at'       => $row['created_at'] ?? null,
            'updated_at'       => $row['updated_at'] ?? null,
        ];

        $role = (string) ($viewer['role'] ?? '');
        $isOwner = (int) ($request['user_id'] ?? 0) === (int) ($viewer['id'] ?? 0);

        if ($role !== 'ADMIN' && !$isOwner && $role !== 'VOLUNTEER' && $role !== 'ORGANIZATION') {
            $item['request'] = null;
        } else {
            $item['request'] = $this->presentRequestSummary($request, $viewer);
        }

        // Volunteers never see exact request coordinates on suggestions.
        if ($role === 'VOLUNTEER' && isset($item['request']['latitude'])) {
            $item['request']['latitude'] = null;
            $item['request']['longitude'] = null;
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $viewer
     * @return array<string, mixed>
     */
    private function presentRequestSummary(array $request, array $viewer): array
    {
        $role = (string) ($viewer['role'] ?? '');
        $includeCoords = $role === 'ADMIN' || (int) ($request['user_id'] ?? 0) === (int) ($viewer['id'] ?? 0);

        return [
            'id'              => (int) $request['id'],
            'category'        => (string) $request['category'],
            'description'     => (string) $request['description'],
            'people_affected' => (int) $request['people_affected'],
            'priority'        => (string) $request['priority'],
            'priority_score'  => (int) $request['priority_score'],
            'status'          => (string) $request['status'],
            'address_text'    => $request['address_text'] !== null ? (string) $request['address_text'] : null,
            'latitude'        => $includeCoords && $request['latitude'] !== null ? (float) $request['latitude'] : null,
            'longitude'       => $includeCoords && $request['longitude'] !== null ? (float) $request['longitude'] : null,
            'created_at'      => (string) ($request['created_at'] ?? ''),
        ];
    }

    /**
     * Best-effort human reasons when presenting a stored match row.
     *
     * @param array<string, mixed> $match
     * @return array<int, string>
     */
    private function reconstructReasons(array $match, string $type): array
    {
        $reasons = [];

        if ($type === 'VOLUNTEER' && !empty($match['volunteer_id'])) {
            $profile = VolunteerRepository::find((int) $match['volunteer_id']);

            if ($profile !== null) {
                $skills = VolunteerService::skillTokens($profile['skills'] ?? null);
                $reasons[] = $skills === [] ? 'General assistance' : implode(' + ', $skills) . ' skills';
                $reasons[] = (string) ($profile['availability'] ?? 'AVAILABLE');
            }
        }

        if ($type === 'RESOURCE' && !empty($match['resource_id'])) {
            $resource = ResourceRepository::find((int) $match['resource_id']);

            if ($resource !== null) {
                $reasons[] = sprintf(
                    '%s available (%s)',
                    (string) $resource['resource_name'],
                    number_format((int) $resource['available_quantity'])
                );
            }
        }

        if ($match['distance_km'] !== null) {
            $reasons[] = number_format((float) $match['distance_km'], 1) . ' km away';
        }

        if ((float) ($match['capability_score'] ?? 0) > 0) {
            $reasons[] = sprintf('Capability score %s/100', number_format((float) $match['capability_score'], 0));
        }

        $reasons[] = 'Match score ' . number_format((float) ($match['match_score'] ?? 0), 1);

        return $reasons;
    }
}
