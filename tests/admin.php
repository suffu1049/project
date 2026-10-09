<?php

declare(strict_types=1);

/**
 * ResQLink — Phase 9 admin control center tests.
 *
 * Requires a migrated database. Run with:
 *     php -c php.ini tests/admin.php
 *
 * Covers KPI aggregation, request queue filters + pagination, resource
 * overview, activity/audit reads, server-side ADMIN enforcement (web + API),
 * audited admin actions, workflow guardrails and map privacy.
 *
 * Exit code 0 = all passed, 1 = one or more failures.
 */

use App\Controllers\AdminApiController;
use App\Controllers\AdminController;
use App\Core\AdminException;
use App\Core\Database;
use App\Core\EmergencyException;
use App\Core\Request;
use App\Models\AuditRepository;
use App\Models\EmergencyRequestRepository;
use App\Models\OrganizationRepository;
use App\Models\ResourceRepository;
use App\Models\UserRepository;
use App\Models\VolunteerRepository;
use App\Services\AdminService;
use App\Services\EmergencyService;
use App\Services\LocationService;
use App\Services\NotificationService;

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

function make_request(string $method, string $path, array $body = [], array $headers = [], bool $json = false): Request
{
    if ($json) {
        $headers['accept'] = 'application/json';
        $headers['content-type'] = 'application/json';
    } else {
        $headers['accept'] = $headers['accept'] ?? 'text/html';
    }

    return new Request($method, $path, [], $body, $headers, [], '127.0.0.1', 'admin-test');
}

$admin = new AdminService();
$emergencies = new EmergencyService();

$suffix = bin2hex(random_bytes(4));
$userCleanup = [];
$requestCleanup = [];
$resourceCleanup = [];
$orgCleanup = [];
$profileCleanup = [];

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

$makeRequest = static function (int $userId, string $category, string $description, string $status, string $priority = 'HIGH') use (&$requestCleanup): int {
    $id = EmergencyRequestRepository::create([
        'user_id'         => $userId,
        'category'        => $category,
        'description'     => $description,
        'people_affected' => 2,
        'priority'        => $priority,
        'priority_score'  => $priority === 'CRITICAL' ? 90 : 55,
        'priority_reason' => 'Phase 9 admin fixture.',
        'status'          => $status,
        'latitude'        => 20.3882,
        'longitude'       => 72.9091,
        'address_text'    => 'Admin Test Street',
    ]);
    $requestCleanup[] = $id;

    return (int) $id;
};

echo "ResQLink admin control center tests\n";
echo "==================================\n\n";

// ---------------------------------------------------------------------------
echo "Setup\n";

$adminId = $makeUser('Admin Center Admin', 'ADMIN');
$citizenId = $makeUser('Admin Center Citizen', 'CITIZEN');
$volunteerUserId = $makeUser('Admin Center Volunteer', 'VOLUNTEER');
$orgUserId = $makeUser('Admin Center Org', 'ORGANIZATION');

$volunteerProfileId = VolunteerRepository::create([
    'user_id'             => $volunteerUserId,
    'skills'              => 'MEDICAL',
    'vehicle'             => 'CAR',
    'equipment'           => null,
    'availability'        => 'AVAILABLE',
    'service_radius_km'   => 40.0,
    'latitude'            => 20.3882,
    'longitude'           => 72.9091,
    'verification_status' => 'PENDING',
]);
$profileCleanup[] = (int) $volunteerProfileId;

$organizationId = OrganizationRepository::create([
    'user_id'             => $orgUserId,
    'name'                => 'Admin Center Org ' . $suffix,
    'type'                => 'NGO',
    'contact'             => '9000000009',
    'description'         => 'Phase 9 admin fixture.',
    'verification_status' => 'PENDING',
    'latitude'            => 20.39,
    'longitude'           => 72.91,
    'address_text'        => 'Admin Org Street',
]);
$orgCleanup[] = (int) $organizationId;

$resourceId = ResourceRepository::create([
    'organization_id'    => $organizationId,
    'resource_type'      => 'FOOD',
    'resource_name'      => 'Admin Test Food ' . $suffix,
    'quantity'           => 100,
    'available_quantity' => 5,
    'unit'               => 'kits',
    'status'             => 'LIMITED',
    'latitude'           => 20.39,
    'longitude'          => 72.91,
]);
$resourceCleanup[] = (int) $resourceId;

$adminViewer = UserRepository::find($adminId);
$citizenViewer = UserRepository::find($citizenId);
$volunteerViewer = UserRepository::find($volunteerUserId);

$createdRequestId = $makeRequest($citizenId, 'MEDICAL', 'Phase 9 critical medical fixture ' . $suffix, 'CREATED', 'CRITICAL');
$escalatableRequestId = $makeRequest($citizenId, 'FLOOD', 'Phase 9 flood fixture ' . $suffix, 'VERIFIED', 'HIGH');

check('fixtures created', $adminId > 0 && $volunteerProfileId > 0 && $organizationId > 0 && $resourceId > 0 && $createdRequestId > 0);

// ---------------------------------------------------------------------------
echo "\nKPIs\n";

$kpis = $admin->kpis();
$expectedKeys = [
    'total_requests', 'active_emergencies', 'critical_requests', 'high_priority',
    'in_progress', 'resolved_requests', 'available_volunteers', 'available_resources',
    'verified_organizations', 'active_matches',
];
$missing = array_values(array_diff($expectedKeys, array_keys($kpis)));
check('kpis expose every required metric', $missing === []);

$totalRow = Database::selectOne('SELECT COUNT(*) AS c FROM emergency_requests');
check('total_requests matches the database count', (int) $kpis['total_requests'] === (int) $totalRow['c']);

$activeRow = Database::selectOne(
    "SELECT COUNT(*) AS c FROM emergency_requests WHERE status IN ('CREATED','VERIFIED','MATCHING','ACCEPTED','IN_PROGRESS','ESCALATED')"
);
check('active_emergencies matches the database count', (int) $kpis['active_emergencies'] === (int) $activeRow['c']);

$criticalRow = Database::selectOne(
    "SELECT COUNT(*) AS c FROM emergency_requests WHERE priority = 'CRITICAL' AND status NOT IN ('RESOLVED','CANCELLED')"
);
check('critical_requests matches the database count', (int) $kpis['critical_requests'] === (int) $criticalRow['c']);

$resolvedRow = Database::selectOne("SELECT COUNT(*) AS c FROM emergency_requests WHERE status = 'RESOLVED'");
check('resolved_requests matches the database count', (int) $kpis['resolved_requests'] === (int) $resolvedRow['c']);

$volunteerRow = Database::selectOne(
    "SELECT COUNT(*) AS c FROM volunteer_profiles WHERE availability = 'AVAILABLE' AND verification_status = 'VERIFIED'"
);
check('available_volunteers counts only verified + available', (int) $kpis['available_volunteers'] === (int) $volunteerRow['c']);

// ---------------------------------------------------------------------------
echo "\nRequest queue filters & pagination\n";

$queue = $admin->requestQueue([]);
check('queue returns items', $queue['items'] !== []);
check('queue pagination has page/limit/total/pages', isset($queue['pagination']['page'], $queue['pagination']['limit'], $queue['pagination']['total'], $queue['pagination']['pages']));

$criticalFiltered = $admin->requestQueue(['priority' => 'CRITICAL']);
$allCritical = array_reduce($criticalFiltered['items'], static fn (bool $carry, array $row): bool => $carry && (string) $row['priority'] === 'CRITICAL', true);
check('priority filter returns only CRITICAL rows', $criticalFiltered['items'] !== [] && $allCritical);

$statusFiltered = $admin->requestQueue(['status' => 'CREATED']);
$allCreated = array_reduce($statusFiltered['items'], static fn (bool $carry, array $row): bool => $carry && (string) $row['status'] === 'CREATED', true);
check('status filter returns only CREATED rows', $statusFiltered['items'] !== [] && $allCreated);

$categoryFiltered = $admin->requestQueue(['category' => 'MEDICAL']);
$allMedical = array_reduce($categoryFiltered['items'], static fn (bool $carry, array $row): bool => $carry && (string) $row['category'] === 'MEDICAL', true);
check('category filter returns only MEDICAL rows', $categoryFiltered['items'] !== [] && $allMedical);

$searchFiltered = $admin->requestQueue(['search' => 'Phase 9 flood fixture']);
check('search filter finds the matching request', array_reduce(
    $searchFiltered['items'],
    static fn (bool $carry, array $row): bool => $carry || (int) $row['id'] === $escalatableRequestId,
    false
));

check('invalid enum filter is ignored safely', $admin->requestQueue(['priority' => 'NOT_A_PRIORITY'])['filters']['priority'] === null);
check('invalid date filter is ignored safely', $admin->requestQueue(['date_from' => 'yesterday'])['filters']['date_from'] === null);

$paged = $admin->requestQueue(['limit' => 1, 'page' => 1]);
check('limit is honoured', count($paged['items']) === 1);
check('pagination computes pages', (int) $paged['pagination']['pages'] >= 1);
$page2 = $admin->requestQueue(['limit' => 1, 'page' => 2]);
check('page 2 differs from page 1', $paged['items'][0]['id'] !== $page2['items'][0]['id']);

// ---------------------------------------------------------------------------
echo "\nResource overview & activity\n";

$overview = $admin->resourceOverview();
check('resource overview includes summary', isset($overview['summary']['FOOD']));
check('resource overview stats expose availability', array_key_exists('available_quantity', $overview['stats']));

$foodRow = $overview['summary']['FOOD'] ?? null;
check('resource summary reports FOOD totals', $foodRow !== null && (int) $foodRow['total'] > 0 && (int) $foodRow['available'] >= 0);

$shortageIds = array_map(static fn (array $row): int => (int) $row['id'], $overview['shortages']);
check('shortage detection includes the low-stock fixture', in_array($resourceId, $shortageIds, true));

$activity = $admin->activity(20);
check('activity feed returns timeline events', $activity !== []);
check('activity rows carry request context', isset($activity[0]['request_id'], $activity[0]['event_type']));

$auditPage = $admin->auditLogs(['limit' => 5]);
check('audit page returns pagination', isset($auditPage['pagination']['total']));

// ---------------------------------------------------------------------------
echo "\nServer-side ADMIN enforcement\n";

check('non-admin cannot verify a request', (static function () use ($admin, $createdRequestId, $citizenViewer): bool {
    try {
        $admin->verifyRequest($createdRequestId, $citizenViewer);
    } catch (AdminException $exception) {
        return $exception->errorCode === 'FORBIDDEN' && $exception->status === 403;
    } catch (Throwable) {
        return false;
    }

    return false;
})());

check('non-admin cannot read the dashboard via API', (static function (): bool {
    $_SESSION = [];
    $controller = new AdminApiController();
    $response = $controller->dashboard(make_request('GET', '/api/v1/admin/dashboard', [], ['accept' => 'application/json'], true));

    return $response->status() === 401;
})());

check('citizen API access is rejected with 403', (static function () use ($citizenViewer): bool {
    $_SESSION = ['user' => $citizenViewer];
    $controller = new AdminApiController();
    $request = make_request('GET', '/api/v1/admin/dashboard', [], ['accept' => 'application/json'], true);
    $request->setAttribute('user', $citizenViewer);
    $response = $controller->dashboard($request);

    return $response->status() === 403;
})());

check('volunteer API access is rejected with 403', (static function () use ($volunteerViewer): bool {
    $_SESSION = ['user' => $volunteerViewer];
    $controller = new AdminApiController();
    $request = make_request('GET', '/api/v1/admin/requests', [], ['accept' => 'application/json'], true);
    $request->setAttribute('user', $volunteerViewer);
    $response = $controller->requests($request);

    return $response->status() === 403;
})());

// ---------------------------------------------------------------------------
echo "\nAudited admin actions\n";

$verified = $admin->verifyRequest($createdRequestId, $adminViewer);
check('admin verifies a CREATED request', (string) $verified['status'] === 'VERIFIED');

$verifyAudit = Database::selectOne(
    "SELECT * FROM audit_logs WHERE action = 'VERIFY_REQUEST' AND entity_id = :id ORDER BY id DESC LIMIT 1",
    ['id' => $createdRequestId]
);
check('verify writes an audit row', $verifyAudit !== null);
check('audit row records the acting admin', $verifyAudit !== null && (int) $verifyAudit['actor_id'] === $adminId);

$escalated = $admin->escalateRequest($escalatableRequestId, $adminViewer, 'Phase 9 escalation reason.');
check('admin escalates a request', (string) $escalated['status'] === 'ESCALATED');

$escalateAudit = Database::selectOne(
    "SELECT * FROM audit_logs WHERE action = 'ESCALATE_REQUEST' AND entity_id = :id ORDER BY id DESC LIMIT 1",
    ['id' => $escalatableRequestId]
);
check('escalate writes an audit row with the reason', $escalateAudit !== null && str_contains((string) $escalateAudit['metadata'], 'Phase 9 escalation reason.'));

$timeline = Database::select('SELECT * FROM request_events WHERE request_id = :id', ['id' => $escalatableRequestId]);
check('escalation appends a request timeline event', $timeline !== []);

check('invalid status transition fails safely', (static function () use ($admin, $createdRequestId, $adminViewer): bool {
    try {
        $admin->changeRequestStatus($createdRequestId, $adminViewer, 'IN_PROGRESS');
    } catch (EmergencyException $exception) {
        return $exception->errorCode === 'INVALID_REQUEST_STATUS';
    } catch (Throwable) {
        return false;
    }

    return false;
})());

check('unknown status is rejected as validation', (static function () use ($admin, $escalatableRequestId, $adminViewer): bool {
    try {
        $admin->changeRequestStatus($escalatableRequestId, $adminViewer, 'NOPE');
    } catch (AdminException $exception) {
        return $exception->errorCode === 'VALIDATION_ERROR';
    } catch (Throwable) {
        return false;
    }

    return false;
})());

// Volunteer + organization verification (delegated + audited).
$verifiedVolunteer = $admin->verifyVolunteer($volunteerProfileId, $adminViewer, 'VERIFIED');
check('admin verifies a volunteer profile', (string) $verifiedVolunteer['verification_status'] === 'VERIFIED');

$verifiedOrg = $admin->verifyOrganization($organizationId, $adminViewer, 'VERIFIED', 'Approved in Phase 9 tests.');
check('admin verifies an organization', (string) $verifiedOrg['verification_status'] === 'VERIFIED');

$orgAudit = Database::selectOne(
    "SELECT * FROM audit_logs WHERE entity_type = 'organization' AND entity_id = :id ORDER BY id DESC LIMIT 1",
    ['id' => $organizationId]
);
check('organization verification is audited', $orgAudit !== null);

// ---------------------------------------------------------------------------
echo "\nWeb controllers & pages\n";

$_SESSION = ['user' => $adminViewer];

$web = new AdminController();

$dashRequest = make_request('GET', '/admin');
$dashRequest->setAttribute('user', $adminViewer);
$dashResponse = $web->dashboard($dashRequest);
$dashHtml = (string) $dashResponse->body();
check('admin dashboard renders', $dashResponse->status() === 200);
check('dashboard shows the control center heading', str_contains($dashHtml, 'ResQLink control center'));
check('dashboard shows KPI cards', str_contains($dashHtml, 'Active emergencies'));

$queueRequest = make_request('GET', '/admin/requests');
$queueRequest->setAttribute('user', $adminViewer);
$queueResponse = $web->requests($queueRequest);
$queueHtml = (string) $queueResponse->body();
check('request queue page renders', $queueResponse->status() === 200);
check('request queue page has filters', str_contains($queueHtml, 'name="priority"') && str_contains($queueHtml, 'name="status"'));

$resourcesRequest = make_request('GET', '/admin/resources');
$resourcesRequest->setAttribute('user', $adminViewer);
$resourcesResponse = $web->resources($resourcesRequest);
check('resource overview page renders', $resourcesResponse->status() === 200);
check('resource page shows totals', str_contains((string) $resourcesResponse->body(), 'Available units'));

$activityRequest = make_request('GET', '/admin/activity');
$activityRequest->setAttribute('user', $adminViewer);
$activityResponse = $web->activity($activityRequest);
$activityHtml = (string) $activityResponse->body();
check('activity page renders', $activityResponse->status() === 200);
check('activity page shows the audit trail', str_contains($activityHtml, 'Audit trail'));

// API happy path for an admin.
$_SESSION = ['user' => $adminViewer];
$api = new AdminApiController();

$apiDash = make_request('GET', '/api/v1/admin/dashboard', [], ['accept' => 'application/json'], true);
$apiDash->setAttribute('user', $adminViewer);
$apiDashResponse = $api->dashboard($apiDash);
$apiDashPayload = json_decode((string) $apiDashResponse->body(), true);
check('API dashboard succeeds for admin', $apiDashResponse->status() === 200 && ($apiDashPayload['success'] ?? false) === true);
check('API dashboard returns KPI data', isset($apiDashPayload['data']['kpis']['active_emergencies']));

$apiRequests = make_request('GET', '/api/v1/admin/requests', [], ['accept' => 'application/json'], true);
$apiRequests->setAttribute('user', $adminViewer);
$apiRequestsResponse = $api->requests($apiRequests);
check('API request queue succeeds for admin', $apiRequestsResponse->status() === 200);

$apiAudit = make_request('GET', '/api/v1/admin/audit-logs', [], ['accept' => 'application/json'], true);
$apiAudit->setAttribute('user', $adminViewer);
$apiAuditResponse = $api->auditLogs($apiAudit);
check('API audit logs succeed for admin', $apiAuditResponse->status() === 200);

// ---------------------------------------------------------------------------
echo "\nMap privacy remains intact\n";

$location = new LocationService();
$adminMarkers = $location->markersForViewer($adminViewer);
$approxAdmin = array_filter($adminMarkers['items'], static fn (array $marker): bool => (string) $marker['precision'] !== 'exact');
check('admin map markers are all exact', $approxAdmin === []);

$citizenMarkers = $location->markersForViewer($citizenViewer);
$volunteerHomeLeak = array_filter($citizenMarkers['items'], static fn (array $marker): bool => (string) $marker['type'] === 'VOLUNTEER');
check('citizen map never exposes volunteer home markers', $volunteerHomeLeak === []);

$volunteerMarkers = $location->markersForViewer($volunteerViewer);
$volunteerHomeLeakV = array_filter($volunteerMarkers['items'], static fn (array $marker): bool => (string) $marker['type'] === 'VOLUNTEER');
check('volunteer map never exposes volunteer home markers', $volunteerHomeLeakV === []);

$_SESSION = [];

// ---------------------------------------------------------------------------
echo "\nCleanup\n";

try {
    $ids = array_map('intval', $userCleanup);
    $placeholders = implode(',', array_fill(0, max(1, count($ids)), '?'));

    foreach ($requestCleanup as $requestId) {
        $requestId = (int) $requestId;

        if (EmergencyRequestRepository::find($requestId) === null) {
            continue;
        }

        Database::execute('DELETE FROM matches WHERE request_id = :id', ['id' => $requestId]);
        Database::execute('DELETE FROM request_events WHERE request_id = :id', ['id' => $requestId]);
        Database::execute('DELETE FROM emergency_attachments WHERE request_id = :id', ['id' => $requestId]);
        EmergencyRequestRepository::delete($requestId);
    }

    foreach ($resourceCleanup as $id) {
        if (ResourceRepository::find((int) $id) !== null) {
            ResourceRepository::delete((int) $id);
        }
    }

    foreach ($orgCleanup as $id) {
        if (OrganizationRepository::find((int) $id) !== null) {
            OrganizationRepository::delete((int) $id);
        }
    }

    foreach ($userCleanup as $userId) {
        Database::execute('DELETE FROM notifications WHERE user_id = :user_id', ['user_id' => $userId]);
        Database::execute('DELETE FROM volunteer_profiles WHERE user_id = :user_id', ['user_id' => $userId]);
        Database::execute('DELETE FROM organizations WHERE user_id = :user_id', ['user_id' => $userId]);

        if (UserRepository::find($userId) !== null) {
            UserRepository::delete($userId);
        }
    }

    Database::execute('DELETE FROM audit_logs WHERE id > :id', ['id' => $auditStart]);

    echo "  cleanup deletes finished\n";
} catch (Throwable $exception) {
    echo '  CLEANUP ERROR: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n";
    $failed++;
}

check('test users removed', UserRepository::find($adminId) === null && UserRepository::find($citizenId) === null);
check('test requests removed', EmergencyRequestRepository::find($createdRequestId) === null);
check('test resources removed', ResourceRepository::find($resourceId) === null);
check('test organizations removed', OrganizationRepository::find($organizationId) === null);
check('test audit rows removed', (int) (Database::selectOne('SELECT COUNT(*) AS aggregate FROM audit_logs WHERE id > :id', ['id' => $auditStart])['aggregate'] ?? 0) === 0);

// ---------------------------------------------------------------------------
echo "\n----------------------------------------\n";
echo "Passed: {$passed}   Failed: {$failed}\n";
