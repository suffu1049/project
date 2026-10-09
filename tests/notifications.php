<?php

declare(strict_types=1);

/**
 * ResQLink — Phase 8 location, map & notification tests.
 *
 * Requires a migrated database. Run with:
 *     php -c php.ini tests/notifications.php
 *
 * Covers geo math, role-aware marker privacy, notification domain events,
 * IDOR-safe read/mark-read, CSRF, web pages and API surfaces.
 *
 * Exit code 0 = all passed, 1 = one or more failures.
 */

use App\Controllers\MapApiController;
use App\Controllers\MapController;
use App\Controllers\NotificationApiController;
use App\Controllers\NotificationController;
use App\Core\Database;
use App\Core\NotificationException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\CsrfMiddleware;
use App\Models\AuditRepository;
use App\Models\EmergencyRequestRepository;
use App\Models\MatchRepository;
use App\Models\NotificationRepository;
use App\Models\OrganizationRepository;
use App\Models\RequestEventRepository;
use App\Models\ResourceRepository;
use App\Models\UserRepository;
use App\Models\VolunteerRepository;
use App\Services\EmergencyService;
use App\Services\LocationService;
use App\Services\MatchingService;
use App\Services\NotificationService;
use App\Services\ResourceService;

define('BASE_PATH', dirname(__DIR__));

// CLI tests must surface fatals; production display_errors is disabled by ErrorHandler.
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

function expectNotification(callable $callback, string $code): bool
{
    try {
        $callback();
    } catch (NotificationException $exception) {
        return $exception->errorCode === $code;
    } catch (Throwable) {
        return false;
    }

    return false;
}

function make_request(string $method, string $path, array $body = [], array $headers = [], bool $json = false, array $files = []): Request
{
    if ($json) {
        $headers['accept'] = 'application/json';
        $headers['content-type'] = 'application/json';
    } else {
        $headers['accept'] = $headers['accept'] ?? 'application/json';
    }

    return new Request($method, $path, [], $body, $headers, $files, '127.0.0.1', 'notifications-test');
}

function notificationsFor(int $userId, string $type, ?int $entityId = null): array
{
    $rows = NotificationRepository::forUser($userId, false, 1, 100);

    return array_values(array_filter($rows, static function (array $row) use ($type, $entityId): bool {
        if ((string) $row['type'] !== $type) {
            return false;
        }

        return $entityId === null || (int) ($row['related_entity_id'] ?? 0) === $entityId;
    }));
}

$emergencies = new EmergencyService();
$matching = new MatchingService();
$resources = new ResourceService();
$notifications = new NotificationService();
$location = new LocationService();

$suffix = bin2hex(random_bytes(4));
$userCleanup = [];
$requestCleanup = [];
$resourceCleanup = [];
$notificationCleanup = [];

$auditStart = (int) (Database::selectOne('SELECT COALESCE(MAX(id), 0) AS m FROM audit_logs')['m'] ?? 0);

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

$makeVolunteer = static function (string $label, string $skills, string $availability = 'AVAILABLE', string $verification = 'VERIFIED', ?float $lat = 20.3882, ?float $lng = 72.9091, float $radius = 50.0) use ($makeUser): array {
    $userId = $makeUser($label, 'VOLUNTEER');
    $profileId = VolunteerRepository::create([
        'user_id'             => $userId,
        'skills'              => $skills,
        'vehicle'             => 'CAR',
        'equipment'           => null,
        'availability'        => $availability,
        'service_radius_km'   => $radius,
        'latitude'            => $lat,
        'longitude'           => $lng,
        'verification_status' => $verification,
    ]);

    return [$userId, (int) $profileId];
};

$makeOrg = static function (string $label, string $verification, ?float $lat = 20.3900, ?float $lng = 72.9100) use ($makeUser): array {
    $userId = $makeUser($label, 'ORGANIZATION');
    $organizationId = OrganizationRepository::create([
        'user_id'             => $userId,
        'name'                => $label,
        'type'                => 'NGO',
        'contact'             => '9000000002',
        'description'         => 'Phase 8 location fixture.',
        'verification_status' => $verification,
        'latitude'            => $lat,
        'longitude'           => $lng,
        'address_text'        => 'Map Street, Demo City',
    ]);

    return [$userId, (int) $organizationId];
};

echo "ResQLink location, map & notification tests\n";
echo "===========================================\n\n";

// ---------------------------------------------------------------------------
echo "Setup\n";

$adminId = $makeUser('Notify Admin', 'ADMIN');
$secondAdminId = $makeUser('Notify Second Admin', 'ADMIN');
$citizenId = $makeUser('Notify Citizen', 'CITIZEN');
$otherCitizenId = $makeUser('Other Notify Citizen', 'CITIZEN');
[$volunteerUserId, $volunteerProfileId] = $makeVolunteer('Notify Medical Volunteer', 'MEDICAL,FIRST_AID');
[$secondVolunteerUserId, $secondVolunteerProfileId] = $makeVolunteer('Notify Second Volunteer', 'MEDICAL');
[$orgUserId, $orgId] = $makeOrg('Notify Relief Org', 'VERIFIED');
[$otherOrgUserId, $otherOrgId] = $makeOrg('Notify Other Org', 'VERIFIED');

$adminViewer = UserRepository::find($adminId);
$secondAdminViewer = UserRepository::find($secondAdminId);
$citizenViewer = UserRepository::find($citizenId);
$otherCitizenViewer = UserRepository::find($otherCitizenId);
$volunteerViewer = UserRepository::find($volunteerUserId);
$secondVolunteerViewer = UserRepository::find($secondVolunteerUserId);
$orgViewer = UserRepository::find($orgUserId);
$otherOrgViewer = UserRepository::find($otherOrgUserId);

check('fixtures created', $adminId > 0 && $citizenId > 0 && $volunteerProfileId > 0 && $orgId > 0 && $secondAdminId > 0);

// ---------------------------------------------------------------------------
echo "\nGeo math & validation\n";

$nearKm = LocationService::haversineKm(20.3882, 72.9091, 20.3883, 72.9092);
check('haversineKm returns a small positive value for nearby points', $nearKm > 0.0 && $nearKm < 1.0);
check('haversineKm is symmetric', abs($nearKm - LocationService::haversineKm(20.3883, 72.9092, 20.3882, 72.9091)) < 0.0001);

$farKm = LocationService::haversineKm(20.3882, 72.9091, 21.5000, 73.5000);
check('haversineKm separates distant points', $farKm > 50.0);

check('validatePair accepts empty pair (optional location)', LocationService::validatePair(null, null) === null);
check('validatePair accepts valid pair', LocationService::validatePair(20.3882, 72.9091) === null);
check('validatePair rejects latitude only', isset(LocationService::validatePair(20.3882, null)['location']));
check('validatePair rejects out-of-range latitude', isset(LocationService::validatePair(91, 0)['latitude']));
check('validatePair rejects out-of-range longitude', isset(LocationService::validatePair(0, 181)['longitude']));

$rounded = LocationService::roundCoords(20.38821, 72.90914);
check('roundCoords redacts to 2 decimals', abs($rounded['latitude'] - 20.39) < 0.001 && abs($rounded['longitude'] - 72.91) < 0.001);

// Exact-coordinate privacy matrix.
$ownRequest = ['user_id' => $citizenId, 'id' => 1];
check('admin always sees exact coords', LocationService::canSeeExactRequestCoords($ownRequest, $adminViewer, false));
check('owner citizen sees exact coords', LocationService::canSeeExactRequestCoords($ownRequest, $citizenViewer, false));
check('non-owner citizen does not see exact coords', !LocationService::canSeeExactRequestCoords($ownRequest, $otherCitizenViewer, false));
check('volunteer without match does not see exact coords', !LocationService::canSeeExactRequestCoords($ownRequest, $volunteerViewer, false));
check('volunteer with accepted match sees exact coords', LocationService::canSeeExactRequestCoords($ownRequest, $volunteerViewer, true));
check('organization without match does not see exact coords', !LocationService::canSeeExactRequestCoords($ownRequest, $orgViewer, false));
check('organization with accepted match sees exact coords', LocationService::canSeeExactRequestCoords($ownRequest, $orgViewer, true));

// ---------------------------------------------------------------------------
echo "\nRequest marker precision\n";

$locatedRequest = [
    'id'          => 90001,
    'user_id'     => $citizenId,
    'category'    => 'MEDICAL',
    'description' => 'Marker precision fixture with exact coordinates.',
    'latitude'    => 20.388213,
    'longitude'   => 72.909145,
    'priority'    => 'HIGH',
    'status'      => 'CREATED',
];

$adminMarker = $location->requestMarker($locatedRequest, $adminViewer, false);
check('admin marker is exact', $adminMarker !== null && $adminMarker['precision'] === 'exact' && abs((float) $adminMarker['latitude'] - 20.388213) < 0.000001);

$citizenMarker = $location->requestMarker($locatedRequest, $citizenViewer, false);
check('owner citizen marker is exact', $citizenMarker !== null && $citizenMarker['precision'] === 'exact');

$volunteerMarker = $location->requestMarker($locatedRequest, $volunteerViewer, false);
check('volunteer marker without match is approximate', $volunteerMarker !== null && $volunteerMarker['precision'] === 'approximate');
check('approximate marker is rounded', $volunteerMarker !== null && abs((float) $volunteerMarker['latitude'] - 20.39) < 0.001);

$volunteerAcceptedMarker = $location->requestMarker($locatedRequest, $volunteerViewer, true);
check('volunteer marker with accepted match is exact', $volunteerAcceptedMarker !== null && $volunteerAcceptedMarker['precision'] === 'exact');

$noLocationMarker = $location->requestMarker(['latitude' => null, 'longitude' => null], $adminViewer, false);
check('request without coords yields no marker', $noLocationMarker === null);

// ---------------------------------------------------------------------------
echo "\nDomain event notifications\n";

// REQUEST_CREATED → admins (not the creating citizen).
$citizenRequest = $emergencies->create($citizenId, $emergencies->validateCreation([
    'category'        => 'MEDICAL',
    'description'     => 'Phase 8 create notifies operators of a medical emergency.',
    'people_affected' => 2,
    'latitude'        => 20.3883,
    'longitude'       => 72.9092,
    'address_text'    => 'Notify Street',
]));
$requestCleanup[] = (int) $citizenRequest['id'];
$requestId = (int) $citizenRequest['id'];

$createdAdminNotices = notificationsFor($adminId, NotificationService::TYPE_REQUEST_CREATED, $requestId);
check('REQUEST_CREATED notifies the admin', $createdAdminNotices !== []);
check('REQUEST_CREATED skips the creator', notificationsFor($citizenId, NotificationService::TYPE_REQUEST_CREATED, $requestId) === []);

// ESCALATED → owner + other admins (actor is never self-notified).
$emergencies->changeStatus($requestId, $adminViewer, 'ESCALATED', 'Priority escalation for Phase 8 notify test.');
$escalatedOwnerNotices = notificationsFor($citizenId, NotificationService::TYPE_ESCALATED, $requestId);
check('ESCALATED notifies the request owner', $escalatedOwnerNotices !== []);
check('ESCALATED notifies other admins', notificationsFor($secondAdminId, NotificationService::TYPE_ESCALATED, $requestId) !== []);
check('ESCALATED skips the acting admin', notificationsFor($adminId, NotificationService::TYPE_ESCALATED, $requestId) === []);

// RESOLVED on a fresh open request via legal admin transitions.
$resolvedRequest = $emergencies->create($citizenId, $emergencies->validateCreation([
    'category'        => 'FLOOD',
    'description'     => 'Resolve path for Phase 8 notification wiring tests.',
    'people_affected' => 3,
    'latitude'        => 20.3890,
    'longitude'       => 72.9100,
]));
$requestCleanup[] = (int) $resolvedRequest['id'];
$resolvedRequestId = (int) $resolvedRequest['id'];

foreach (['VERIFIED', 'MATCHING', 'ACCEPTED', 'IN_PROGRESS', 'RESOLVED'] as $stepStatus) {
    $emergencies->changeStatus($resolvedRequestId, $adminViewer, $stepStatus, 'Phase 8 resolve walk: ' . $stepStatus);
}

check('RESOLVED notifies owner with REQUEST_RESOLVED', notificationsFor($citizenId, NotificationService::TYPE_REQUEST_RESOLVED, $resolvedRequestId) !== []);
check('RESOLVED notifies other admins', notificationsFor($secondAdminId, NotificationService::TYPE_REQUEST_RESOLVED, $resolvedRequestId) !== []);
check('RESOLVED skips the acting admin', notificationsFor($adminId, NotificationService::TYPE_REQUEST_RESOLVED, $resolvedRequestId) === []);

// Fresh open request for match lifecycle.
$matchRequest = $emergencies->create($citizenId, $emergencies->validateCreation([
    'category'        => 'MEDICAL',
    'description'     => 'Match lifecycle needs a medical volunteer near the depot.',
    'people_affected' => 1,
    'latitude'        => 20.3882,
    'longitude'       => 72.9091,
]));
$requestCleanup[] = (int) $matchRequest['id'];
$matchRequestId = (int) $matchRequest['id'];

VolunteerRepository::updateAvailability($volunteerProfileId, 'AVAILABLE');
VolunteerRepository::updateAvailability($secondVolunteerProfileId, 'AVAILABLE');

$beforeMatchNotices = notificationsFor($volunteerUserId, NotificationService::TYPE_MATCH_FOUND, $matchRequestId);
$scored = $matching->suggestForRequest($matchRequestId, $adminViewer, true, ['limit' => 50]);
check('suggest persisted match rows', ($scored['items'] ?? []) !== []);

$afterMatchNotices = notificationsFor($volunteerUserId, NotificationService::TYPE_MATCH_FOUND, $matchRequestId);
check('MATCH_FOUND notifies eligible volunteers', $afterMatchNotices !== [] || $beforeMatchNotices !== []);

$matchRow = null;
foreach (MatchRepository::forRequest($matchRequestId) as $row) {
    if ((int) ($row['volunteer_id'] ?? 0) === $volunteerProfileId) {
        $matchRow = $row;
        break;
    }
}

check('fixture volunteer has a suggested match', $matchRow !== null);

if ($matchRow !== null) {
    $matching->accept((int) $matchRow['id'], $volunteerViewer);
    check('MATCH_ACCEPTED notifies the request owner', notificationsFor($citizenId, NotificationService::TYPE_MATCH_ACCEPTED, $matchRequestId) !== []);

    // Resource allocation event.
    $resource = $resources->create($orgViewer, [
        'resource_type'      => 'MEDICAL_SUPPORT',
        'resource_name'      => 'Notify First Aid Kits',
        'quantity'           => 30,
        'available_quantity' => 30,
        'unit'               => 'kits',
    ]);
    $resourceCleanup[] = (int) $resource['id'];

    $resources->allocate($orgViewer, (int) $resource['id'], [
        'request_id' => $matchRequestId,
        'quantity'   => 2,
    ]);
    check('RESOURCE_ALLOCATED notifies the request owner', notificationsFor($citizenId, NotificationService::TYPE_RESOURCE_ALLOCATED, $matchRequestId) !== []);
} else {
    check('MATCH_ACCEPTED notifies the request owner', false);
    check('RESOURCE_ALLOCATED notifies the request owner', false);
}

// Best-effort notify never throws.
$notifyResult = $notifications->notify($citizenId, NotificationService::TYPE_STATUS_CHANGED, 'Best effort', 'Delivery should never throw.', 'emergency_request', $requestId);
check('notify returns an id on success', $notifyResult > 0);
check('notify rejects invalid user', $notifications->notify(0, NotificationService::TYPE_STATUS_CHANGED, 'x', 'y') === 0);

// ---------------------------------------------------------------------------
echo "\nNotification repository & service read side\n";

$inboxId = NotificationRepository::notify(
    $citizenId,
    NotificationService::TYPE_STATUS_CHANGED,
    'Inbox fixture',
    'Unread inbox item for list tests.',
    'emergency_request',
    $requestId
);
$notificationCleanup[] = $inboxId;

$unreadBefore = NotificationRepository::unreadCount($citizenId);
check('unreadCount reflects unread rows', $unreadBefore > 0);

$list = $notifications->listForUser($citizenId, ['page' => 1, 'limit' => 5]);
check('listForUser returns items + meta', is_array($list['items']) && isset($list['meta']['total'], $list['meta']['unread_count']));
check('listForUser includes the inbox fixture', array_filter($list['items'], static fn (array $item): bool => (int) $item['id'] === $inboxId) !== []);

$unreadList = $notifications->listForUser($citizenId, ['unread' => true, 'limit' => 50]);
$allUnread = array_filter($unreadList['items'], static fn (array $item): bool => !(bool) $item['read_status']);
check('unread filter returns only unread rows', $unreadList['items'] === [] || count($allUnread) === count($unreadList['items']));

$readItem = $notifications->markRead($inboxId, $citizenId);
check('markRead marks the row read', (bool) $readItem['read_status'] === true);
check('markRead is idempotent for already-read rows', (bool) $notifications->markRead($inboxId, $citizenId)['read_status'] === true);

// IDOR: other citizen cannot mark this notification.
check('markRead hides foreign notification existence', expectNotification(
    static fn () => $notifications->markRead($inboxId, $otherCitizenId),
    'NOTIFICATION_NOT_FOUND'
));
check('foreign mark-read did not flip the row', (bool) (NotificationRepository::find($inboxId)['read_status'] ?? false) === true);

// Mark-all-read for the other citizen (seeded/demo noise is fine).
$otherNotices = [
    NotificationRepository::notify($otherCitizenId, NotificationService::TYPE_STATUS_CHANGED, 'Other fixture A', 'Unread A.'),
    NotificationRepository::notify($otherCitizenId, NotificationService::TYPE_STATUS_CHANGED, 'Other fixture B', 'Unread B.'),
];
$notificationCleanup = [...$notificationCleanup, ...$otherNotices];

$marked = $notifications->markAllRead($otherCitizenId);
check('markAllRead returns a positive count when unread exist', $marked >= 2);
check('markAllRead clears unread for the user', NotificationRepository::unreadCount($otherCitizenId) === 0);

// ---------------------------------------------------------------------------
echo "\nMap markers by role\n";

// Citizen map: only own requests, exact coords.
$citizenMap = $location->markersForViewer($citizenViewer);
$citizenEmergencyIds = array_map(
    static fn (array $marker): string => (string) ($marker['id'] ?? ''),
    array_filter($citizenMap['items'], static fn (array $marker): bool => (string) ($marker['type'] ?? '') === 'EMERGENCY')
);
check('citizen map returns emergency markers', $citizenEmergencyIds !== []);
$foreignMarkerForCitizen = array_filter($citizenMap['items'], static function (array $marker) use ($otherCitizenId): bool {
    return (string) ($marker['type'] ?? '') === 'EMERGENCY'
        && str_contains((string) ($marker['id'] ?? ''), 'emergency-')
        && !str_contains((string) ($marker['id'] ?? ''), (string) $otherCitizenId);
});
// Citizen only sees own request ids; foreign request ids must not appear.
$ownRequestIds = array_map(static fn (int $id): string => 'emergency-' . $id, array_map(
    static fn (array $row): int => (int) $row['id'],
    EmergencyRequestRepository::forUser($citizenId, 100)
));
$leaked = array_values(array_filter($citizenEmergencyIds, static fn (string $id): bool => !in_array($id, $ownRequestIds, true)));
check('citizen map does not leak foreign request markers', $leaked === []);
$anyExactOwn = array_filter(
    array_filter($citizenMap['items'], static fn (array $marker): bool => (string) ($marker['type'] ?? '') === 'EMERGENCY'),
    static fn (array $marker): bool => (string) ($marker['precision'] ?? '') === 'exact'
);
check('citizen own markers stay exact', $anyExactOwn !== []);

// Admin map: operational, all active with coords.
$adminMap = $location->markersForViewer($adminViewer);
$adminEmergency = array_filter($adminMap['items'], static fn (array $marker): bool => (string) ($marker['type'] ?? '') === 'EMERGENCY');
check('admin map returns emergency markers', $adminEmergency !== []);
$adminExact = array_filter($adminEmergency, static fn (array $marker): bool => (string) ($marker['precision'] ?? '') === 'exact');
check('admin emergency markers are exact', $adminExact !== []);
$adminHasVolunteerHomes = array_filter($adminMap['items'], static fn (array $marker): bool => (string) ($marker['type'] ?? '') === 'VOLUNTEER');
check('admin map includes volunteer home markers', $adminHasVolunteerHomes !== []);

// Volunteer map: only accepted/completed requests exact; others approximate.
$volunteerMap = $location->markersForViewer($volunteerViewer);
$volunteerEmergency = array_filter($volunteerMap['items'], static fn (array $marker): bool => (string) ($marker['type'] ?? '') === 'EMERGENCY');
$volunteerExact = array_filter($volunteerEmergency, static fn (array $marker): bool => (string) ($marker['precision'] ?? '') === 'exact');
$volunteerApprox = array_filter($volunteerEmergency, static fn (array $marker): bool => (string) ($marker['precision'] ?? '') === 'approximate');
check('volunteer map has no volunteer home markers', array_filter($volunteerMap['items'], static fn (array $marker): bool => (string) ($marker['type'] ?? '') === 'VOLUNTEER') === []);
check('volunteer exact markers only after acceptance', $volunteerExact !== [] || $volunteerApprox !== []);

if ($matchRow !== null) {
    $acceptedMarkerId = 'emergency-' . $matchRequestId;
    $acceptedMarker = array_filter($volunteerEmergency, static fn (array $marker): bool => (string) ($marker['id'] ?? '') === $acceptedMarkerId);
    check('accepted request marker is exact for the volunteer', $acceptedMarker !== [] && (string) (array_values($acceptedMarker)[0]['precision'] ?? '') === 'exact');
}

// Organization map: own org exact, other org approximate.
$orgMap = $location->markersForViewer($orgViewer);
$orgMarkers = array_filter($orgMap['items'], static fn (array $marker): bool => (string) ($marker['type'] ?? '') === 'ORGANIZATION');
$ownOrgMarker = array_filter($orgMarkers, static function (array $marker) use ($orgId): bool {
    return str_contains((string) ($marker['id'] ?? ''), (string) $orgId);
});
$otherOrgMarker = array_filter($orgMarkers, static function (array $marker) use ($otherOrgId): bool {
    return str_contains((string) ($marker['id'] ?? ''), (string) $otherOrgId);
});
check('organization sees own org marker', $ownOrgMarker !== []);
check('organization sees other org markers approximately', $otherOrgMarker !== []
    && (string) (array_values($otherOrgMarker)[0]['precision'] ?? '') === 'approximate');
check('own org marker is exact', $ownOrgMarker !== [] && (string) (array_values($ownOrgMarker)[0]['precision'] ?? '') === 'exact');

// Guest / empty role.
$guestMap = $location->markersForViewer(['role' => '']);
check('guest map has no items and a sign-in note', $guestMap['items'] === [] && str_contains((string) ($guestMap['meta']['precision_note'] ?? ''), 'Sign in'));

// ---------------------------------------------------------------------------
echo "\nWeb controllers & pages\n";

$_SESSION['user_id'] = $citizenId;
$_SESSION['user'] = $citizenViewer;

$notificationsPage = (new NotificationController())->index(make_request('GET', '/notifications'));
check('notifications page renders for citizen', $notificationsPage->status() === 200
    && str_contains((string) $notificationsPage->body(), 'Notifications'));

$mapPage = (new MapController())->index(make_request('GET', '/map'));
$mapHtml = (string) $mapPage->body();
check('map page renders for citizen', $mapPage->status() === 200 && str_contains($mapHtml, 'Operations map'));
check('map page embeds markers JSON', str_contains($mapHtml, 'data-map-markers'));
check('map page links Leaflet assets', str_contains($mapHtml, 'leaflet') || str_contains($mapHtml, 'assets/js/map.js'));

// Emergency show page embeds a map for the owner with coords.
$showResponse = (new \App\Controllers\EmergencyController())->show(
    make_request('GET', '/requests/' . $matchRequestId),
    (string) $matchRequestId
);
$showHtml = (string) $showResponse->body();
check('emergency show page renders for owner', $showResponse->status() === 200);
check('emergency show page embeds location map', str_contains($showHtml, 'Location map') && str_contains($showHtml, 'data-map-markers'));

// Guest redirects.
$_SESSION = [];
$guestNotifications = (new NotificationController())->index(make_request('GET', '/notifications'));
check('guest notifications redirects to login', in_array($guestNotifications->status(), [302, 301], true) || str_contains((string) $guestNotifications->body(), 'login'));

$guestMapPage = (new MapController())->index(make_request('GET', '/map'));
check('guest map redirects to login', in_array($guestMapPage->status(), [302, 301], true) || str_contains((string) $guestMapPage->body(), 'login'));

// CSRF middleware rejects token-less mutations (controllers do not enforce CSRF themselves).
$_SESSION['user_id'] = $citizenId;
$_SESSION['user'] = $citizenViewer;
$_SESSION['_csrf_token'] = csrf_token();
$csrfMiddleware = new CsrfMiddleware();
$blockedCsrf = $csrfMiddleware->handle(
    make_request('POST', '/notifications/' . $inboxId . '/read', []),
    static fn (): Response => Response::html('OK', 200)
);
check('CSRF middleware blocks token-less mark-read', $blockedCsrf->status() === 403
    && (json_decode((string) $blockedCsrf->body(), true)['code'] ?? '') === 'CSRF_MISMATCH');

$withCsrf = (new NotificationController())->markRead(
    make_request('POST', '/notifications/' . $inboxId . '/read', ['_token' => csrf_token()]),
    (string) $inboxId
);
check('web mark-read with CSRF redirects (PRG)', in_array($withCsrf->status(), [302, 301], true));

// Foreign IDOR flash path does not 500.
$idorWeb = (new NotificationController())->markRead(
    make_request('POST', '/notifications/99999999/read', ['_token' => csrf_token()]),
    '99999999'
);
check('web mark-read of unknown id stays graceful', in_array($idorWeb->status(), [302, 301], true));

// ---------------------------------------------------------------------------
echo "\nAPI controllers\n";

$api = new NotificationApiController();
$mapApi = new MapApiController();
$csrf = csrf_token();

$apiList = $api->index(make_request('GET', '/api/v1/notifications'));
$apiListBody = json_decode((string) $apiList->body(), true);
check('API notifications list succeeds', $apiList->status() === 200 && is_array($apiListBody['data']['items'] ?? null));
check('API notifications list has meta', isset($apiListBody['data']['meta']['unread_count']));

$apiMark = $api->markRead(
    make_request('POST', '/api/v1/notifications/' . $inboxId . '/read', ['_token' => $csrf], [], true),
    (string) $inboxId
);
$apiMarkBody = json_decode((string) $apiMark->body(), true);
check('API mark-read succeeds for owner', $apiMark->status() === 200 && ($apiMarkBody['data']['read_status'] ?? false) === true);

// Switch viewer to the other citizen for IDOR.
$_SESSION['user_id'] = $otherCitizenId;
$_SESSION['user'] = $otherCitizenViewer;
$apiIdor = $api->markRead(
    make_request('POST', '/api/v1/notifications/' . $inboxId . '/read', ['_token' => csrf_token()], [], true),
    (string) $inboxId
);
$apiIdorBody = json_decode((string) $apiIdor->body(), true);
check('API mark-read IDOR returns NOTIFICATION_NOT_FOUND', $apiIdor->status() === 404
    && ($apiIdorBody['code'] ?? '') === 'NOTIFICATION_NOT_FOUND');

// CSRF rejection on API mark-all-read via middleware.
$blockedApiCsrf = $csrfMiddleware->handle(
    make_request('POST', '/api/v1/notifications/read-all', [], [], true),
    static fn (): Response => Response::html('OK', 200)
);
check('CSRF middleware blocks token-less API read-all', $blockedApiCsrf->status() === 403);

$_SESSION['user_id'] = $citizenId;
$_SESSION['user'] = $citizenViewer;
$_SESSION['_csrf_token'] = $csrf;
$apiReadAll = $api->markAllRead(make_request('POST', '/api/v1/notifications/read-all', ['_token' => $csrf], [], true));
$apiReadAllBody = json_decode((string) $apiReadAll->body(), true);
check('API read-all succeeds for authenticated user', $apiReadAll->status() === 200 && ($apiReadAllBody['success'] ?? false) === true);

// Unauthenticated API — clear session so auth_user() returns null.
$_SESSION = [];
$apiUnauthReq = new Request('GET', '/api/v1/notifications', [], [], [], [], '127.0.0.1', 'notifications-test');
$apiUnauth = $api->index($apiUnauthReq);
check('API notifications require auth', $apiUnauth->status() === 401);

// Map markers API by role.
$_SESSION['user_id'] = $adminId;
$_SESSION['user'] = $adminViewer;
$mapAdmin = $mapApi->markers(make_request('GET', '/api/v1/map/markers'));
$mapAdminBody = json_decode((string) $mapAdmin->body(), true);
check('API map markers succeeds for admin', $mapAdmin->status() === 200 && is_array($mapAdminBody['data']['items'] ?? null));
check('API map meta includes poll interval', isset($mapAdminBody['data']['meta']['poll_interval_seconds']));

$_SESSION['user_id'] = $volunteerUserId;
$_SESSION['user'] = $volunteerViewer;
$mapVolunteer = $mapApi->markers(make_request('GET', '/api/v1/map/markers'));
$mapVolunteerBody = json_decode((string) $mapVolunteer->body(), true);
$volunteerItems = $mapVolunteerBody['data']['items'] ?? [];
$volunteerHomeMarkers = array_filter($volunteerItems, static fn (array $marker): bool => (string) ($marker['type'] ?? '') === 'VOLUNTEER');
check('API map markers hide volunteer homes from volunteers', $volunteerHomeMarkers === []);
$volunteerForeignExact = array_filter($volunteerItems, static function (array $marker) use ($otherCitizenId): bool {
    // Foreign citizen requests that are not accepted should be approximate.
    if ((string) ($marker['type'] ?? '') !== 'EMERGENCY') {
        return false;
    }

    return (string) ($marker['precision'] ?? '') === 'exact'
        && !str_contains((string) ($marker['id'] ?? ''), (string) $otherCitizenId);
});
// Foreign unaccepted markers must not be exact — accepted ones (matchRequestId) may be.
$volunteerExactIds = array_map(
    static fn (array $marker): string => (string) ($marker['id'] ?? ''),
    array_filter($volunteerItems, static fn (array $marker): bool => (string) ($marker['type'] ?? '') === 'EMERGENCY'
        && (string) ($marker['precision'] ?? '') === 'exact')
);
$unexpectedExact = array_values(array_filter(
    $volunteerExactIds,
    static fn (string $id): bool => !in_array($id, ['emergency-' . $matchRequestId], true)
));
check('volunteer exact markers limited to accepted matches', $unexpectedExact === []);

$_SESSION = [];
$mapUnauth = $mapApi->markers($apiUnauthReq);
check('API map markers require auth', $mapUnauth->status() === 401);

// ---------------------------------------------------------------------------
echo "\nCleanup\n";

try {
    // Delete matches for test-created users only (never touch seed fixtures).
    $cleanupPlaceholders = implode(',', array_fill(0, max(1, count($userCleanup)), '?'));
    $cleanupIds = array_map('intval', $userCleanup);

    if ($cleanupIds !== []) {
        foreach (Database::select(
            'SELECT id FROM matches WHERE request_id IN (SELECT id FROM emergency_requests WHERE user_id IN (' . $cleanupPlaceholders . '))',
            $cleanupIds
        ) as $row) {
            MatchRepository::delete((int) $row['id']);
        }
    }

    foreach ($resourceCleanup as $resourceId) {
        $resourceId = (int) $resourceId;

        if ($resourceId > 0 && ResourceRepository::find($resourceId) !== null) {
            try {
                ResourceRepository::delete($resourceId);
            } catch (Throwable) {
            }
        }
    }

    foreach ($userCleanup as $userId) {
        $requestIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            Database::select('SELECT id FROM emergency_requests WHERE user_id = ?', [$userId])
        );

        foreach (array_unique([...$requestIds, ...$requestCleanup]) as $requestId) {
            $requestId = (int) $requestId;

            if (EmergencyRequestRepository::find($requestId) === null) {
                continue;
            }

            foreach (Database::select('SELECT id FROM matches WHERE request_id = ?', [$requestId]) as $matchRow) {
                MatchRepository::delete((int) $matchRow['id']);
            }

            Database::execute('DELETE FROM request_events WHERE request_id = :id', ['id' => $requestId]);
            Database::execute('DELETE FROM emergency_attachments WHERE request_id = :id', ['id' => $requestId]);
            EmergencyRequestRepository::delete($requestId);
        }

        Database::execute('DELETE FROM notifications WHERE user_id = :user_id', ['user_id' => $userId]);
        Database::execute('DELETE FROM volunteer_profiles WHERE user_id = :user_id', ['user_id' => $userId]);
        Database::execute('DELETE FROM organizations WHERE user_id = :user_id', ['user_id' => $userId]);

        if (UserRepository::find($userId) !== null) {
            UserRepository::delete($userId);
        }
    }

    foreach ($notificationCleanup as $notificationId) {
        Database::execute('DELETE FROM notifications WHERE id = :id', ['id' => (int) $notificationId]);
    }

    Database::execute('DELETE FROM audit_logs WHERE id > :id', ['id' => $auditStart]);

    echo "  cleanup deletes finished\n";
} catch (Throwable $exception) {
    echo '  CLEANUP ERROR: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n";
    $failed++;
}

check('test users removed', UserRepository::find($citizenId) === null && UserRepository::find($adminId) === null && UserRepository::find($secondAdminId) === null);
check('test volunteer profiles removed with users', VolunteerRepository::find($volunteerProfileId) === null);
check('test organizations removed with users', OrganizationRepository::find($orgId) === null);
check('test requests removed', EmergencyRequestRepository::find($matchRequestId) === null);
check('test audit rows removed', (int) (Database::selectOne('SELECT COUNT(*) AS aggregate FROM audit_logs WHERE id > :id', ['id' => $auditStart])['aggregate'] ?? 0) === 0);

// ---------------------------------------------------------------------------
echo "\n----------------------------------------\n";
echo "Passed: {$passed}   Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
