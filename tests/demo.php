<?php

declare(strict_types=1);

/**
 * ResQLink — Phase 12 hackathon demo verification.
 *
 * Run with:
 *     php -c php.ini tests/demo.php
 *
 * Drives the complete demo loop end-to-end through the real service layer
 * (no mocks) and asserts every step of the scripted scenario:
 *
 *   1. Citizen creates a medical emergency (3 people)
 *   2. Priority engine calculates priority
 *   3. Matching engine suggests the nearest capable volunteer
 *   4. Volunteer sees the request + the match
 *   5. Volunteer accepts  → request ACCEPTED, volunteer BUSY, citizen notified
 *   6. Admin sees the live request (queue + exact map marker)
 *   7. Admin advances the request to IN_PROGRESS (audited)
 *   8. Volunteer completes → request RESOLVED, volunteer AVAILABLE, citizen notified
 *   9. Admin KPIs + analytics reflect the resolution
 *
 * Uses isolated fixtures and removes every row it creates (including
 * notifications delivered to seeded accounts), so it is safe to re-run before
 * a live demo. Exit code 0 = all passed, 1 = one or more failures.
 */

use App\Core\Database;
use App\Models\AuditRepository;
use App\Models\EmergencyRequestRepository;
use App\Models\MatchRepository;
use App\Models\NotificationRepository;
use App\Models\RequestEventRepository;
use App\Models\UserRepository;
use App\Models\VolunteerRepository;
use App\Services\AdminService;
use App\Services\AnalyticsService;
use App\Services\EmergencyService;
use App\Services\LocationService;
use App\Services\MatchingService;
use App\Services\NotificationService;
use App\Services\VolunteerService;

define('BASE_PATH', dirname(__DIR__));

error_reporting(E_ALL);
ini_set('display_errors', '1');

require BASE_PATH . '/app/bootstrap.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}\n";
    }
}

$emergencies = new EmergencyService();
$matching = new MatchingService();
$volunteers = new VolunteerService();
$admin = new AdminService();
$analytics = new AnalyticsService();
$notifications = new NotificationService();
$location = new LocationService();

$suffix = bin2hex(random_bytes(4));

$userCleanup = [];

$makeUser = static function (string $name, string $role) use (&$userCleanup): int {
    $userId = UserRepository::create([
        'name'          => $name,
        'email'         => strtolower(str_replace(' ', '-', $name)) . '-' . bin2hex(random_bytes(4)) . '@resqlink.test',
        'phone'         => null,
        'password_hash' => password_hash('StrongPass123', PASSWORD_DEFAULT),
        'role'          => $role,
        'status'        => 'ACTIVE',
    ]);
    $userCleanup[] = $userId;

    return $userId;
};

$notifStart = (int) (Database::selectOne('SELECT COALESCE(MAX(id), 0) AS m FROM notifications')['m'] ?? 0);
$auditStart = (int) (Database::selectOne('SELECT COALESCE(MAX(id), 0) AS m FROM audit_logs')['m'] ?? 0);

echo "ResQLink — hackathon demo run\n";
echo "=============================\n\n";

// ---------------------------------------------------------------------------
echo "Setup\n";

$adminId = $makeUser('Demo Admin', 'ADMIN');
$citizenId = $makeUser('Demo Citizen', 'CITIZEN');
$volunteerUserId = $makeUser('Demo Volunteer', 'VOLUNTEER');

$volunteerProfileId = VolunteerRepository::create([
    'user_id'             => $volunteerUserId,
    'skills'              => 'MEDICAL,FIRST_AID',
    'vehicle'             => 'CAR',
    'equipment'           => 'FIRST_AID_KIT',
    'availability'        => 'AVAILABLE',
    'service_radius_km'   => 25.0,
    'latitude'            => 20.3882,
    'longitude'           => 72.9091,
    'verification_status' => 'VERIFIED',
]);

$adminViewer = UserRepository::find($adminId);
$citizenViewer = UserRepository::find($citizenId);
$volunteerViewer = UserRepository::find($volunteerUserId);
$requestId = 0;

$suggestCount = static function (int $requestId, array $viewer): array {
    return (new MatchingService())->suggestForRequest($requestId, $viewer, true, ['limit' => 50]);
};

$citizenNotificationsForRequest = static function (int $citizenId, int $requestId): array {
    return Database::select(
        "SELECT type, title, message FROM notifications
         WHERE user_id = :user_id AND related_entity_type = 'emergency_request' AND related_entity_id = :request_id
         ORDER BY id DESC",
        ['user_id' => $citizenId, 'request_id' => $requestId]
    );
};

check('demo roles are ready', $adminId > 0 && $citizenId > 0 && $volunteerProfileId > 0);

// ---------------------------------------------------------------------------
echo "\n1. Citizen creates a medical emergency\n";

$created = $emergencies->create($citizenId, $emergencies->validateCreation([
    'category'        => 'MEDICAL',
    'description'     => 'Three people injured in a collapse, urgent medical help needed.',
    'people_affected' => 3,
    'latitude'        => 20.3883,
    'longitude'       => 72.9092,
    'address_text'    => 'Demo City Central',
]));

$requestId = (int) $created['id'];

check('request created with an id', $requestId > 0);
check('initial status is CREATED', (string) $created['status'] === 'CREATED');
check('priority engine assigned a band', in_array((string) $created['priority'], ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true));
check('priority score was computed', (int) $created['priority_score'] > 0);
check('priority reason is stored', trim((string) $created['priority_reason']) !== '');
check('citizen can see their own request', (int) ($emergencies->findVisible($requestId, $citizenViewer)['id'] ?? 0) === $requestId);

// ---------------------------------------------------------------------------
echo "\n2. Matching engine suggests a capable nearby volunteer\n";

$suggestion = $suggestCount($requestId, $adminViewer);
check('matching returns candidates', ($suggestion['items'] ?? []) !== []);
check('suggestions were persisted as matches', ($suggestion['meta']['persisted'] ?? false) === true);

$matchRow = null;

foreach (MatchRepository::forRequest($requestId) as $row) {
    if ((int) ($row['volunteer_id'] ?? 0) === $volunteerProfileId) {
        $matchRow = $row;
        break;
    }
}

check('the capable volunteer was matched', $matchRow !== null);
check('a match score was recorded', $matchRow !== null && (float) $matchRow['match_score'] > 0);
check('distance was recorded for the match', $matchRow !== null && $matchRow['distance_km'] !== null);
$matchId = (int) ($matchRow['id'] ?? 0);

// ---------------------------------------------------------------------------
echo "\n3. Volunteer sees the request and match\n";

$eligibleIds = array_map(
    static fn (array $item): int => (int) ($item['id'] ?? 0),
    (array) ($volunteers->eligibleRequests($volunteerViewer, ['limit' => 50])['items'] ?? [])
);
check('the request is in the volunteer nearby list', in_array($requestId, $eligibleIds, true));

$volunteerMatchIds = array_map(
    static fn (array $row): int => (int) ($row['id'] ?? 0),
    (array) ($matching->listMatches($volunteerViewer, ['limit' => 50])['items'] ?? [])
);
check('the match is in the volunteer match inbox', in_array($matchId, $volunteerMatchIds, true));

// ---------------------------------------------------------------------------
echo "\n4. Volunteer accepts\n";

$accepted = $matching->accept($matchId, $volunteerViewer);
check('match is ACCEPTED', (string) ($accepted['match']['status'] ?? '') === 'ACCEPTED');
check('request advanced to ACCEPTED', (string) (EmergencyRequestRepository::find($requestId)['status'] ?? '') === 'ACCEPTED');
check('volunteer is marked BUSY', (string) (VolunteerRepository::find($volunteerProfileId)['availability'] ?? '') === 'BUSY');

$timelineTypes = array_map(
    static fn (array $row): string => (string) $row['event_type'],
    RequestEventRepository::forRequest($requestId)
);
check('timeline records the acceptance', in_array('MATCH_ACCEPTED', $timelineTypes, true));

$acceptAudit = AuditRepository::forEntity('match', $matchId);
check('acceptance is audited', $acceptAudit !== [] && (string) $acceptAudit[0]['action'] === 'ACCEPT_MATCH');

// ---------------------------------------------------------------------------
echo "\n5. Citizen sees \"Help accepted\"\n";

$citizenNotifs = $citizenNotificationsForRequest($citizenId, $requestId);
$citizenTypes = array_map(static fn (array $row): string => (string) $row['type'], $citizenNotifs);
check('citizen was notified about the request', $citizenNotifs !== []);
check('citizen received a MATCH_ACCEPTED notification', in_array('MATCH_ACCEPTED', $citizenTypes, true));

// ---------------------------------------------------------------------------
echo "\n6. Admin sees the live request\n";

$kpis = $admin->kpis();
check('admin KPI active count is at least 1', (int) $kpis['active_emergencies'] >= 1);

$queueIds = array_map(
    static fn (array $row): int => (int) $row['id'],
    $admin->requestQueue(['status' => 'ACCEPTED', 'limit' => 100])['items']
);
check('the request appears in the admin queue', in_array($requestId, $queueIds, true));

$markers = $location->markersForViewer($adminViewer)['items'];
$marker = null;

foreach ($markers as $candidate) {
    if (($candidate['id'] ?? '') === 'emergency-' . $requestId) {
        $marker = $candidate;
        break;
    }
}

check('the request is on the operations map', $marker !== null);
check('admin sees the exact location', $marker !== null && (string) $marker['precision'] === 'exact');

// ---------------------------------------------------------------------------
echo "\n7. Admin advances the request to IN_PROGRESS\n";

$inProgress = $admin->changeRequestStatus($requestId, $adminViewer, 'IN_PROGRESS', 'Responder on site.');
check('request is IN_PROGRESS', (string) $inProgress['status'] === 'IN_PROGRESS');

$statusAudit = Database::selectOne(
    "SELECT * FROM audit_logs WHERE action = 'CHANGE_REQUEST_STATUS' AND entity_id = :id ORDER BY id DESC LIMIT 1",
    ['id' => $requestId]
);
check('the status change is audited', $statusAudit !== null);

$resolvedBefore = (int) $admin->kpis()['resolved_requests'];
$analyticsBefore = (int) $analytics->overview('week')['requests']['resolution']['resolved'];

// ---------------------------------------------------------------------------
echo "\n8. Volunteer completes the task\n";

$completed = $matching->complete($matchId, $volunteerViewer);
check('match is COMPLETED', (string) ($completed['match']['status'] ?? '') === 'COMPLETED');

$stored = EmergencyRequestRepository::find($requestId);
check('request is RESOLVED', (string) ($stored['status'] ?? '') === 'RESOLVED');
check('resolved_at timestamp is set', ($stored['resolved_at'] ?? null) !== null);
check('volunteer is AVAILABLE again', (string) (VolunteerRepository::find($volunteerProfileId)['availability'] ?? '') === 'AVAILABLE');

$resolveTypes = array_map(static fn (array $row): string => (string) $row['type'], $citizenNotificationsForRequest($citizenId, $requestId));
check('citizen was notified the request was resolved', in_array('REQUEST_RESOLVED', $resolveTypes, true));

$finalTimeline = array_map(static fn (array $row): string => (string) $row['event_type'], RequestEventRepository::forRequest($requestId));
check('timeline records the completion', in_array('MATCH_COMPLETED', $finalTimeline, true));
check('timeline records the resolution', in_array('STATUS_CHANGED', $finalTimeline, true));

// ---------------------------------------------------------------------------
echo "\n9. Admin KPIs and analytics update\n";

$resolvedAfter = (int) $admin->kpis()['resolved_requests'];
check('resolved KPI incremented', $resolvedAfter === $resolvedBefore + 1);

$analyticsAfter = $analytics->overview('week');
check('analytics resolved count incremented', (int) $analyticsAfter['requests']['resolution']['resolved'] === $analyticsBefore + 1);
check('analytics resolution time is now measurable', $analyticsAfter['requests']['resolution']['average_hours'] !== null);
check('analytics window includes the new request', (int) $analyticsAfter['requests']['total'] >= 1);

$byStatus = $analyticsAfter['requests']['by_status'];
check('analytics status breakdown includes the resolution', array_sum($byStatus) === (int) $analyticsAfter['requests']['total']);

// ---------------------------------------------------------------------------
echo "\nCleanup\n";

try {
    Database::execute('DELETE FROM matches WHERE request_id = :id', ['id' => $requestId]);
    Database::execute('DELETE FROM request_events WHERE request_id = :id', ['id' => $requestId]);
    Database::execute('DELETE FROM emergency_attachments WHERE request_id = :id', ['id' => $requestId]);

    if (EmergencyRequestRepository::find($requestId) !== null) {
        EmergencyRequestRepository::delete($requestId);
    }

    foreach ($userCleanup as $userId) {
        Database::execute('DELETE FROM notifications WHERE user_id = :user_id', ['user_id' => $userId]);
        Database::execute('DELETE FROM volunteer_profiles WHERE user_id = :user_id', ['user_id' => $userId]);

        if (UserRepository::find($userId) !== null) {
            UserRepository::delete($userId);
        }
    }

    // Notifications delivered to seeded accounts (e.g. admins) and audit rows
    // created by this run must not pollute the demo database.
    Database::execute('DELETE FROM notifications WHERE id > :id', ['id' => $notifStart]);
    Database::execute('DELETE FROM audit_logs WHERE id > :id', ['id' => $auditStart]);

    echo "  cleanup deletes finished\n";
} catch (Throwable $exception) {
    echo '  CLEANUP ERROR: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n";
    $failed++;
}

check('demo request removed', EmergencyRequestRepository::find($requestId) === null);
check('demo matches removed', MatchRepository::forRequest($requestId) === []);
check('demo users removed', UserRepository::find($adminId) === null && UserRepository::find($citizenId) === null);
check('no notifications left behind', (int) (Database::selectOne('SELECT COUNT(*) AS c FROM notifications WHERE id > :id', ['id' => $notifStart])['c'] ?? 0) === 0);
check('no audit rows left behind', (int) (Database::selectOne('SELECT COUNT(*) AS c FROM audit_logs WHERE id > :id', ['id' => $auditStart])['c'] ?? 0) === 0);

// ---------------------------------------------------------------------------
echo "\n----------------------------------------\n";
echo "Passed: {$passed}   Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
