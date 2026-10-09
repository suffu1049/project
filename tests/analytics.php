<?php

declare(strict_types=1);

/**
 * ResQLink — Phase 11 analytics tests.
 *
 * Requires a migrated database. Run with:
 *     php -c php.ini tests/analytics.php
 *
 * Verifies the analytics aggregates against raw SQL counts, period windowing,
 * the API.md §58 payload, resource/volunteer metrics, read-only behaviour
 * (no audit writes) and server-side ADMIN enforcement (web + API).
 *
 * Exit code 0 = all passed, 1 = one or more failures.
 */

use App\Controllers\AdminApiController;
use App\Controllers\AdminController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Middleware\RoleMiddleware;
use App\Models\EmergencyRequestRepository;
use App\Models\MatchRepository;
use App\Models\OrganizationRepository;
use App\Models\ResourceRepository;
use App\Models\UserRepository;
use App\Models\VolunteerRepository;
use App\Services\AnalyticsService;

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

function make_request(string $method, string $path, array $query = [], array $body = [], array $headers = []): Request
{
    $headers['accept'] = $headers['accept'] ?? 'text/html';

    return new Request($method, $path, $query, $body, $headers, [], '127.0.0.1', 'analytics-test');
}

$analytics = new AnalyticsService();

$suffix = bin2hex(random_bytes(4));
$userCleanup = [];
$requestCleanup = [];
$resourceCleanup = [];
$orgCleanup = [];

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

echo "ResQLink analytics tests\n";
echo "========================\n\n";

// ---------------------------------------------------------------------------
echo "Setup\n";

$adminId = $makeUser('Analytics Admin', 'ADMIN');
$citizenId = $makeUser('Analytics Citizen', 'CITIZEN');
$volunteerUserId = $makeUser('Analytics Volunteer', 'VOLUNTEER');
$orgUserId = $makeUser('Analytics Org', 'ORGANIZATION');

$volunteerProfileId = VolunteerRepository::create([
    'user_id'             => $volunteerUserId,
    'skills'              => 'MEDICAL',
    'vehicle'             => 'CAR',
    'equipment'           => null,
    'availability'        => 'AVAILABLE',
    'service_radius_km'   => 40.0,
    'latitude'            => 20.3882,
    'longitude'           => 72.9091,
    'verification_status' => 'VERIFIED',
]);

$organizationId = OrganizationRepository::create([
    'user_id'             => $orgUserId,
    'name'                => 'Analytics Org ' . $suffix,
    'type'                => 'NGO',
    'contact'             => '9000000011',
    'description'         => 'Phase 11 analytics fixture.',
    'verification_status' => 'VERIFIED',
    'latitude'            => 20.39,
    'longitude'           => 72.91,
    'address_text'        => 'Analytics Org Street',
]);
$orgCleanup[] = (int) $organizationId;

$resourceId = ResourceRepository::create([
    'organization_id'    => $organizationId,
    'resource_type'      => 'FOOD',
    'resource_name'      => 'Analytics Food ' . $suffix,
    'quantity'           => 200,
    'available_quantity' => 50,
    'unit'               => 'kits',
    'status'             => 'AVAILABLE',
    'latitude'           => 20.39,
    'longitude'          => 72.91,
]);
$resourceCleanup[] = (int) $resourceId;

$now = new DateTimeImmutable('now');
$threeDaysAgo = $now->modify('-3 days');

// A recent open request (inside every period window).
$recentRequestId = EmergencyRequestRepository::create([
    'user_id'         => $citizenId,
    'category'        => 'MEDICAL',
    'description'     => 'Phase 11 recent medical fixture ' . $suffix,
    'people_affected' => 3,
    'priority'        => 'CRITICAL',
    'priority_score'  => 90,
    'priority_reason' => 'Phase 11 fixture.',
    'status'          => 'CREATED',
    'latitude'        => 20.3882,
    'longitude'       => 72.9091,
    'address_text'    => 'Analytics Street',
    'created_at'      => $now->format('Y-m-d H:i:s'),
]);
$requestCleanup[] = (int) $recentRequestId;

// An older request resolved exactly two hours after creation. It is inside the
// week/month/year windows but outside the day window.
$resolvedCreatedAt = $threeDaysAgo;
$resolvedAt = $threeDaysAgo->modify('+2 hours');
$oldRequestId = EmergencyRequestRepository::create([
    'user_id'         => $citizenId,
    'category'        => 'FLOOD',
    'description'     => 'Phase 11 resolved flood fixture ' . $suffix,
    'people_affected' => 5,
    'priority'        => 'HIGH',
    'priority_score'  => 60,
    'priority_reason' => 'Phase 11 fixture.',
    'status'          => 'RESOLVED',
    'latitude'        => 20.39,
    'longitude'       => 72.91,
    'address_text'    => 'Analytics Flood Street',
    'created_at'      => $resolvedCreatedAt->format('Y-m-d H:i:s'),
    'resolved_at'     => $resolvedAt->format('Y-m-d H:i:s'),
]);
$requestCleanup[] = (int) $oldRequestId;

$completedMatchId = MatchRepository::createMatch([
    'request_id'    => $recentRequestId,
    'volunteer_id'  => $volunteerProfileId,
    'resource_id'   => null,
    'distance_km'   => 2.4,
    'match_score'   => 88,
    'status'        => 'COMPLETED',
]);
$acceptedMatchId = MatchRepository::createMatch([
    'request_id'    => $oldRequestId,
    'volunteer_id'  => $volunteerProfileId,
    'resource_id'   => null,
    'distance_km'   => 4.1,
    'match_score'   => 70,
    'status'        => 'ACCEPTED',
]);

$adminViewer = UserRepository::find($adminId);
$citizenViewer = UserRepository::find($citizenId);

check('fixtures created', $adminId > 0 && $volunteerProfileId > 0 && $organizationId > 0 && $resourceId > 0 && $recentRequestId > 0 && $oldRequestId > 0);

// ---------------------------------------------------------------------------
echo "\nPeriod validation\n";

check('default period is week', $analytics->normalisePeriod('') === 'week');
check('unknown period degrades to week', $analytics->normalisePeriod('nonsense') === 'week');
check('period is case-insensitive', $analytics->normalisePeriod('DAY') === 'day');
check('documented periods are exposed', AnalyticsService::PERIODS === ['week', 'day', 'month', 'year']);

$bogus = $analytics->overview('not-a-period');
check('overview with an invalid period falls back to week', $bogus['period'] === 'week');

// ---------------------------------------------------------------------------
echo "\nRequest aggregates match raw SQL\n";

$week = $analytics->overview('week');
$windowFrom = (string) $week['window']['from'];

$rawTotal = (int) (Database::selectOne(
    'SELECT COUNT(*) AS c FROM emergency_requests WHERE created_at >= :from',
    ['from' => $windowFrom]
)['c'] ?? 0);
check('total matches the windowed SQL count', (int) $week['requests']['total'] === $rawTotal);

$statusSum = array_sum($week['requests']['by_status']);
check('by_status sums to the window total', $statusSum === (int) $week['requests']['total']);

$rawByCategory = [];
foreach (Database::select(
    'SELECT category AS v, COUNT(*) AS c FROM emergency_requests WHERE created_at >= :from GROUP BY category',
    ['from' => $windowFrom]
) as $row) {
    $rawByCategory[(string) $row['v']] = (int) $row['c'];
}
$serviceByCategory = $week['requests']['by_category'];
ksort($rawByCategory);
ksort($serviceByCategory);
check('by_category matches raw SQL grouping', $rawByCategory == $serviceByCategory);

$rawByPriority = [];
foreach (Database::select(
    'SELECT priority AS v, COUNT(*) AS c FROM emergency_requests WHERE created_at >= :from GROUP BY priority',
    ['from' => $windowFrom]
) as $row) {
    $rawByPriority[(string) $row['v']] = (int) $row['c'];
}
$serviceByPriority = $week['requests']['by_priority'];
ksort($rawByPriority);
ksort($serviceByPriority);
check('by_priority matches raw SQL grouping', $rawByPriority == $serviceByPriority);

$rawActive = (int) (Database::selectOne(
    "SELECT COUNT(*) AS c FROM emergency_requests
     WHERE created_at >= :from AND status IN ('CREATED','VERIFIED','MATCHING','ACCEPTED','IN_PROGRESS','ESCALATED')",
    ['from' => $windowFrom]
)['c'] ?? 0);
check('active matches the windowed SQL count', (int) $week['requests']['active'] === $rawActive);

$overTime = $week['requests']['over_time'];
check('over_time buckets equal the series total', array_sum($overTime['values']) === (int) $week['requests']['total']);
check('over_time exposes a label per bucket', count($overTime['labels']) === count($overTime['values']));

// ---------------------------------------------------------------------------
echo "\nPeriod windowing\n";

$day = $analytics->overview('day');
$dayFrom = (string) $day['window']['from'];
check('day window starts later than the week window', $dayFrom > $windowFrom);

$recentInDay = (int) (Database::selectOne(
    'SELECT COUNT(*) AS c FROM emergency_requests WHERE id = :id AND created_at >= :from',
    ['id' => $recentRequestId, 'from' => $dayFrom]
)['c'] ?? 0);
check('recent request is inside the day window', $recentInDay === 1);

$oldInDay = (int) (Database::selectOne(
    'SELECT COUNT(*) AS c FROM emergency_requests WHERE id = :id AND created_at >= :from',
    ['id' => $oldRequestId, 'from' => $dayFrom]
)['c'] ?? 0);
check('three-day-old request is outside the day window', $oldInDay === 0);

check('day over_time has 24 hourly buckets', count($day['requests']['over_time']['values']) === 24);
check('week over_time has 7 daily buckets', count($week['requests']['over_time']['values']) === 7);
check('month over_time has 30 daily buckets', count($analytics->overview('month')['requests']['over_time']['values']) === 30);
check('year over_time has 12 monthly buckets', count($analytics->overview('year')['requests']['over_time']['values']) === 12);

// ---------------------------------------------------------------------------
echo "\nResolution & responder metrics\n";

$rawResolution = Database::selectOne(
    "SELECT COUNT(*) AS resolved,
            AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) AS avg_minutes
     FROM emergency_requests
     WHERE status = 'RESOLVED' AND resolved_at IS NOT NULL"
);
$expectedAvg = $rawResolution['avg_minutes'] !== null ? round((float) $rawResolution['avg_minutes'] / 60, 1) : null;
$reported = $week['requests']['resolution'];
check('resolution resolved count matches SQL', (int) $reported['resolved'] === (int) $rawResolution['resolved']);
check('average resolution time matches SQL', $expectedAvg === null
    ? $reported['average_hours'] === null
    : abs((float) $reported['average_hours'] - $expectedAvg) < 0.05);
check('the two-hour fixture is reflected in the average', (int) $reported['resolved'] >= 1 && $reported['fastest_hours'] !== null);

$rawAvailable = (int) (Database::selectOne(
    "SELECT COUNT(*) AS c FROM volunteer_profiles WHERE availability = 'AVAILABLE' AND verification_status = 'VERIFIED'"
)['c'] ?? 0);
check('available volunteers match SQL', (int) $week['volunteers']['available'] === $rawAvailable);

$rawMatches = Database::selectOne(
    "SELECT COALESCE(SUM(status = 'ACCEPTED'), 0) AS accepted,
            COALESCE(SUM(status = 'COMPLETED'), 0) AS completed
     FROM matches WHERE volunteer_id IS NOT NULL"
);
check('accepted tasks match SQL', (int) $week['volunteers']['accepted'] === (int) $rawMatches['accepted']);
check('completed tasks match SQL', (int) $week['volunteers']['completed'] === (int) $rawMatches['completed']);
check('the completed fixture match is counted', (int) $rawMatches['completed'] >= 1);

// ---------------------------------------------------------------------------
echo "\nResource metrics\n";

$stats = $week['resources']['stats'];
$expectedAllocated = max(0, (int) $stats['total_quantity'] - (int) $stats['available_quantity']);
check('allocated units equal total minus available', (int) $week['resources']['allocated'] === $expectedAllocated);

$rawTypeCount = (int) (Database::selectOne('SELECT COUNT(DISTINCT resource_type) AS c FROM resources')['c'] ?? 0);
check('availability series has one bar per resource type', count($week['resources']['availability']['labels']) === $rawTypeCount);
check('availability series lengths align', count($week['resources']['availability']['labels'])
    === count($week['resources']['availability']['available'])
    && count($week['resources']['availability']['available']) === count($week['resources']['availability']['total']));

// ---------------------------------------------------------------------------
echo "\nAPI.md §58 payload\n";

$payload = $analytics->apiPayload($week);
$requiredKeys = [
    'period', 'window', 'requests_by_category', 'requests_by_priority',
    'requests_by_status', 'requests_over_time', 'resources', 'volunteers',
];
$missing = array_values(array_diff($requiredKeys, array_keys($payload)));
check('API payload exposes every documented key', $missing === []);
check('API payload over_time carries labels + values', isset($payload['requests_over_time']['labels'], $payload['requests_over_time']['values']));

// ---------------------------------------------------------------------------
echo "\nServer-side ADMIN enforcement\n";

check('analytics writes no audit rows (read-only)', (int) (Database::selectOne(
    'SELECT COUNT(*) AS c FROM audit_logs WHERE id > :id',
    ['id' => $auditStart]
)['c'] ?? 0) === 0);

check('guest analytics API returns 401', (static function (): bool {
    $_SESSION = [];
    $controller = new AdminApiController();
    $response = $controller->analytics(make_request('GET', '/api/v1/admin/analytics', [], [], ['accept' => 'application/json']));

    return $response->status() === 401;
})());

check('citizen analytics API returns 403', (static function () use ($citizenViewer): bool {
    $_SESSION = ['user' => $citizenViewer];
    $controller = new AdminApiController();
    $request = make_request('GET', '/api/v1/admin/analytics', [], [], ['accept' => 'application/json']);
    $request->setAttribute('user', $citizenViewer);
    $response = $controller->analytics($request);

    return $response->status() === 403;
})());

check('admin analytics API returns the payload', (static function () use ($adminViewer): bool {
    $_SESSION = ['user' => $adminViewer];
    $controller = new AdminApiController();
    $request = make_request('GET', '/api/v1/admin/analytics', ['period' => 'week'], [], ['accept' => 'application/json']);
    $request->setAttribute('user', $adminViewer);
    $response = $controller->analytics($request);
    $decoded = json_decode((string) $response->body(), true);

    return $response->status() === 200
        && ($decoded['success'] ?? false) === true
        && isset($decoded['data']['requests_by_status'], $decoded['data']['requests_over_time']);
})());

// Web route guard: the /admin/analytics route is wrapped in RoleMiddleware.
$router = new Router();
$router->get('/admin/analytics', static fn (): \App\Core\Response => \App\Core\Response::html('rendered'), [new RoleMiddleware(['ADMIN'])]);

check('guest web analytics redirects to login', (static function () use ($router): bool {
    $_SESSION = [];

    return $router->dispatch(make_request('GET', '/admin/analytics'))->status() === 302;
})());

check('citizen web analytics is forbidden', (static function () use ($router, $citizenId): bool {
    $_SESSION = ['user_id' => $citizenId];

    return $router->dispatch(make_request('GET', '/admin/analytics'))->status() === 403;
})());

check('admin web analytics is allowed', (static function () use ($router, $adminId): bool {
    $_SESSION = ['user_id' => $adminId];

    return $router->dispatch(make_request('GET', '/admin/analytics'))->status() === 200;
})());

// ---------------------------------------------------------------------------
echo "\nAnalytics page rendering\n";

$_SESSION = ['user' => $adminViewer];

$controller = new AdminController();
$request = make_request('GET', '/admin/analytics', ['period' => 'week']);
$request->setAttribute('user', $adminViewer);
$response = $controller->analytics($request);
$html = (string) $response->body();

check('analytics page renders', $response->status() === 200);
check('analytics page shows the heading', str_contains($html, 'Operational analytics'));
check('analytics page sizes offer every period', str_contains($html, 'period=day') && str_contains($html, 'period=year'));
check('analytics page includes the chart payload', str_contains($html, 'data-analytics-charts'));
check('analytics page includes the accessible fallback table', str_contains($html, 'Resource availability by type'));

$extracted = null;

if (preg_match('/<script type="application\/json" data-analytics-charts>(.*?)<\/script>/s', $html, $matches) === 1) {
    $extracted = json_decode(trim($matches[1]), true);
}

check('embedded chart payload parses as JSON', is_array($extracted));
check('chart payload carries every series', is_array($extracted)
    && isset($extracted['over_time'], $extracted['by_category'], $extracted['by_status'], $extracted['resources']));
check('chart payload over_time matches the service series', is_array($extracted)
    && $extracted['over_time']['values'] === $week['requests']['over_time']['values']);

$invalidRequest = make_request('GET', '/admin/analytics', ['period' => 'nope']);
$invalidRequest->setAttribute('user', $adminViewer);
$invalidResponse = $controller->analytics($invalidRequest);
check('an invalid period still renders the default page', $invalidResponse->status() === 200
    && str_contains((string) $invalidResponse->body(), 'Operational analytics'));

// ---------------------------------------------------------------------------
echo "\nCleanup\n";

$_SESSION = [];

try {
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

check('test requests removed', EmergencyRequestRepository::find($recentRequestId) === null && EmergencyRequestRepository::find($oldRequestId) === null);
check('test matches removed', MatchRepository::find($completedMatchId) === null && MatchRepository::find($acceptedMatchId) === null);
check('test resources removed', ResourceRepository::find($resourceId) === null);
check('test organizations removed', OrganizationRepository::find($organizationId) === null);
check('test users removed', UserRepository::find($adminId) === null && UserRepository::find($citizenId) === null);
check('no audit rows were written', (int) (Database::selectOne('SELECT COUNT(*) AS c FROM audit_logs WHERE id > :id', ['id' => $auditStart])['c'] ?? 0) === 0);

// ---------------------------------------------------------------------------
echo "\n----------------------------------------\n";
echo "Passed: {$passed}   Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
