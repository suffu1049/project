<?php

declare(strict_types=1);

/**
 * ResQLink — Phase 6 organization & resource tests.
 *
 * Requires a migrated database. Run with:
 *     php -c php.ini tests/organization.php
 *
 * Covers organization profile management, admin verification, resource CRUD
 * + quantity validation, transactional allocation (including rollback),
 * visibility/IDOR guards, the web PRG flows and the JSON API.
 *
 * Exit code 0 = all passed, 1 = one or more failures.
 */

use App\Controllers\AdminOrganizationController;
use App\Controllers\OrganizationApiController;
use App\Controllers\OrganizationController;
use App\Controllers\ResourceApiController;
use App\Controllers\ResourceController;
use App\Core\Database;
use App\Core\OrganizationException;
use App\Core\Request;
use App\Core\ResourceException;
use App\Core\Response;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\AttachmentRepository;
use App\Models\AuditRepository;
use App\Models\EmergencyRequestRepository;
use App\Models\OrganizationRepository;
use App\Models\RequestEventRepository;
use App\Models\ResourceRepository;
use App\Models\UserRepository;
use App\Services\EmergencyService;
use App\Services\OrganizationService;
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

function expectOrganization(callable $callback, string $code): bool
{
    try {
        $callback();
    } catch (OrganizationException $exception) {
        return $exception->errorCode === $code;
    } catch (Throwable) {
        return false;
    }

    return false;
}

function expectResource(callable $callback, string $code): bool
{
    try {
        $callback();
    } catch (ResourceException $exception) {
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

    return new Request($method, $path, [], $body, $headers, $files, '127.0.0.1', 'organization-test');
}

$organizations = new OrganizationService();
$resources = new ResourceService();
$emergencies = new EmergencyService();
$suffix = bin2hex(random_bytes(4));

$userCleanup = [];
$requestCleanup = [];
$resourceCleanup = [];

echo "ResQLink organization & resource tests\n";
echo "======================================\n\n";

// ---------------------------------------------------------------------------
echo "Setup\n";

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

$makeOrg = static function (string $label, string $verification) use ($makeUser): array {
    $userId = $makeUser($label, 'ORGANIZATION');

    $organizationId = OrganizationRepository::create([
        'user_id'             => $userId,
        'name'                => $label,
        'type'                => 'NGO',
        'contact'             => '9000000001',
        'description'         => 'Test organization for Phase 6.',
        'verification_status' => $verification,
        'latitude'            => 20.3900,
        'longitude'           => 72.9100,
        'address_text'        => 'Test Street, Demo City',
    ]);

    return [$userId, $organizationId];
};

$adminId = $makeUser('Phase6 Admin', 'ADMIN');
$citizenId = $makeUser('Phase6 Citizen', 'CITIZEN');
$volunteerId = $makeUser('Phase6 Volunteer', 'VOLUNTEER');

[$org1User, $org1Id] = $makeOrg('Phase Six Relief', 'VERIFIED');
[$org2User, $org2Id] = $makeOrg('Rival Relief Group', 'VERIFIED');
[$org3User, $org3Id] = $makeOrg('Pending Shelter Service', 'PENDING');

$adminViewer = UserRepository::find($adminId);
$citizenViewer = UserRepository::find($citizenId);
$volunteerViewer = UserRepository::find($volunteerId);
$org1Viewer = UserRepository::find($org1User);
$org2Viewer = UserRepository::find($org2User);
$org3Viewer = UserRepository::find($org3User);

check('admin fixture created', is_int($adminId) && $adminId > 0);
check('verified organization fixtures created', $org1Id > 0 && $org2Id > 0);
check('pending organization fixture created', $org3Id > 0);
check('organization rows linked to accounts', OrganizationRepository::findByUserId($org1User) !== null);

// Emergency request fixtures: one active allocation target, one closed.
$activeRequest = $emergencies->create($citizenId, $emergencies->validateCreation([
    'category'        => 'FOOD_WATER',
    'description'     => 'Families stranded without food or clean water for two days.',
    'people_affected' => 6,
    'latitude'        => 20.3882,
    'longitude'       => 72.9091,
    'address_text'    => 'Near the market gate',
]));
$requestCleanup[] = (int) $activeRequest['id'];

$closedRequest = $emergencies->create($citizenId, $emergencies->validateCreation([
    'category'        => 'MEDICAL',
    'description'     => 'Medical assistance already delivered to the affected household.',
    'people_affected' => 2,
    'latitude'        => 20.3885,
    'longitude'       => 72.9094,
]));
$requestCleanup[] = (int) $closedRequest['id'];
EmergencyRequestRepository::updateStatus((int) $closedRequest['id'], 'RESOLVED', date('Y-m-d H:i:s'));

check('active allocation target exists', EmergencyRequestRepository::find((int) $activeRequest['id']) !== null);
check('closed allocation target exists', (string) EmergencyRequestRepository::find((int) $closedRequest['id'])['status'] === 'RESOLVED');

// ---------------------------------------------------------------------------
echo "\nOrganization profile\n";

$profile = $organizations->profileFor($org1User, $org1Viewer);
check('profileFor returns the org row', (int) ($profile['id'] ?? 0) === $org1Id);
check('profile defaults verification to stored status', (string) $profile['verification_status'] === 'VERIFIED');

check('citizen cannot open an organization profile', expectOrganization(
    static fn () => $organizations->profileFor($citizenId, $citizenViewer),
    'FORBIDDEN'
));
check('organization cannot read another organization profile', expectOrganization(
    static fn () => $organizations->profileFor($org2User, $org1Viewer),
    'FORBIDDEN'
));

$updatedProfile = $organizations->updateProfile($org1Viewer, [
    'name'         => 'Phase Six Relief HQ',
    'type'         => 'FOOD_PROVIDER',
    'contact'      => '9123456789',
    'description'  => 'Community kitchen supporting emergency shelters.',
    'address_text' => 'Sector 4, Demo City',
    'latitude'     => 20.3921,
    'longitude'    => 72.9134,
]);
check('profile update persists name', (string) $updatedProfile['name'] === 'Phase Six Relief HQ');
check('profile update persists type', (string) $updatedProfile['type'] === 'FOOD_PROVIDER');
check('profile update persists contact', (string) $updatedProfile['contact'] === '9123456789');
check('profile update persists description', str_contains((string) $updatedProfile['description'], 'Community kitchen'));
check('profile update persists address', (string) $updatedProfile['address_text'] === 'Sector 4, Demo City');
check('profile update persists location', (float) $updatedProfile['latitude'] === 20.3921 && (float) $updatedProfile['longitude'] === 72.9134);
check('profile update never flips verification', (string) $updatedProfile['verification_status'] === 'VERIFIED');

check('invalid organization type rejected', expectOrganization(
    static fn () => $organizations->updateProfile($org1Viewer, ['type' => 'SUPERMARKET']),
    'VALIDATION_ERROR'
));
check('latitude without longitude rejected', expectOrganization(
    static fn () => $organizations->updateProfile($org1Viewer, ['latitude' => 20.4]),
    'VALIDATION_ERROR'
));
check('out-of-range latitude rejected', expectOrganization(
    static fn () => $organizations->updateProfile($org1Viewer, ['latitude' => 999, 'longitude' => 72.9]),
    'VALIDATION_ERROR'
));
check('citizen cannot update an organization profile', expectOrganization(
    static fn () => $organizations->updateProfile($citizenViewer, ['name' => 'Hijacked']),
    'FORBIDDEN'
));

// Client-supplied verification status must never be applied.
$organizations->updateProfile($org1Viewer, ['verification_status' => 'REJECTED', 'name' => 'Phase Six Relief HQ']);
check('verification status is never client-writable', (string) OrganizationRepository::find($org1Id)['verification_status'] === 'VERIFIED');

// ---------------------------------------------------------------------------
echo "\nAdmin verification\n";

$adminItems = $organizations->adminList();
check('admin list returns organizations', count($adminItems) >= 3);
check('admin list carries inventory counts', array_key_exists('resource_count', $adminItems[0]) && array_key_exists('available_quantity', $adminItems[0]));
check('admin list never exposes account email', !array_key_exists('email', $adminItems[0]) && !array_key_exists('user_email', $adminItems[0]));
check('admin list never exposes account phone', !array_key_exists('phone', $adminItems[0]) && !array_key_exists('user_phone', $adminItems[0]));

$pendingOnly = $organizations->adminList('PENDING');
check('admin list filters by verification status', in_array($org3Id, array_column($pendingOnly, 'id'), true)
    && !in_array($org1Id, array_column($pendingOnly, 'id'), true));
check('unknown status filter rejected', expectOrganization(
    static fn () => $organizations->adminList('MAYBE'),
    'VALIDATION_ERROR'
));

check('volunteer cannot verify organizations', expectOrganization(
    static fn () => $organizations->adminVerify($org3Id, $volunteerViewer, 'VERIFIED'),
    'FORBIDDEN'
));
check('invalid verification status rejected', expectOrganization(
    static fn () => $organizations->adminVerify($org3Id, $adminViewer, 'PENDING'),
    'VALIDATION_ERROR'
));
check('missing organization rejected', expectOrganization(
    static fn () => $organizations->adminVerify(999999999, $adminViewer, 'VERIFIED'),
    'ORGANIZATION_NOT_FOUND'
));

$verifiedOrg3 = $organizations->adminVerify($org3Id, $adminViewer, 'VERIFIED');
check('admin verification sets VERIFIED', (string) $verifiedOrg3['verification_status'] === 'VERIFIED');
check('verification persisted', (string) OrganizationRepository::find($org3Id)['verification_status'] === 'VERIFIED');

$verifyAudit = AuditRepository::forEntity('organization', $org3Id);
check('verification recorded in audit log', count($verifyAudit) === 1 && (string) $verifyAudit[0]['action'] === 'VERIFY_ORGANIZATION');

$rejectedOrg3 = $organizations->adminVerify($org3Id, $adminViewer, 'REJECTED', 'Verification information incomplete.');
check('admin rejection sets REJECTED', (string) $rejectedOrg3['verification_status'] === 'REJECTED');
$rejectAudit = AuditRepository::forEntity('organization', $org3Id);
check('rejection recorded with reason', count($rejectAudit) === 2 && (string) $rejectAudit[0]['action'] === 'REJECT_ORGANIZATION'
    && str_contains((string) $rejectAudit[0]['metadata'], 'Verification information incomplete.'));

$org3AdminView = $organizations->adminShow($org3Id);
check('admin single read works', (int) $org3AdminView['id'] === $org3Id && array_key_exists('resource_count', $org3AdminView));
check('admin single read hides email', !array_key_exists('email', $org3AdminView));

// Restore org3 to PENDING so later verification flow tests stay meaningful.
OrganizationRepository::setVerificationStatus($org3Id, 'PENDING');
$verifyOrg3 = $organizations->adminVerify($org3Id, $adminViewer, 'VERIFIED');
check('verified organization can be re-verified', (string) $verifyOrg3['verification_status'] === 'VERIFIED');

// Leave org3 PENDING: the resource tests below exercise the not-verified gate.
OrganizationRepository::setVerificationStatus($org3Id, 'PENDING');

// ---------------------------------------------------------------------------
echo "\nResource creation\n";

check('pending organization cannot create resources', expectResource(
    static fn () => $resources->create($org3Viewer, [
        'resource_type'      => 'SHELTER',
        'resource_name'      => 'Tents',
        'quantity'           => 10,
        'available_quantity' => 10,
    ]),
    'ORGANIZATION_NOT_VERIFIED'
));
check('citizen cannot create resources', expectResource(
    static fn () => $resources->create($citizenViewer, [
        'resource_type'      => 'FOOD',
        'resource_name'      => 'Sneaky Food',
        'quantity'           => 1,
        'available_quantity' => 1,
    ]),
    'FORBIDDEN'
));
check('admin cannot create resources without an org', expectResource(
    static fn () => $resources->create($adminViewer, [
        'resource_type'      => 'FOOD',
        'resource_name'      => 'Admin Food',
        'quantity'           => 1,
        'available_quantity' => 1,
    ]),
    'FORBIDDEN'
));

$r1 = $resources->create($org1Viewer, [
    'resource_type'      => 'FOOD',
    'resource_name'      => 'Rice Sacks',
    'quantity'           => 500,
    'available_quantity' => 500,
    'unit'               => 'sacks',
    'latitude'           => 20.3921,
    'longitude'          => 72.9134,
]);
$resourceCleanup[] = (int) $r1['id'];

$r2 = $resources->create($org2Viewer, [
    'resource_type'      => 'WATER',
    'resource_name'      => 'Bottled Water',
    'quantity'           => 100,
    'available_quantity' => 100,
    'unit'               => 'bottles',
]);
$resourceCleanup[] = (int) $r2['id'];

check('resource created with stored quantities', (int) $r1['quantity'] === 500 && (int) $r1['available_quantity'] === 500);
check('resource derives AVAILABLE status', (string) $r1['status'] === 'AVAILABLE');
check('resource linked to owning organization', (int) $r1['organization_id'] === $org1Id);
check('resource unit persisted', (string) $r1['unit'] === 'sacks');
check('creation recorded in audit log', count(AuditRepository::forEntity('resource', (int) $r1['id'])) === 1);

check('available greater than quantity rejected', expectResource(
    static fn () => $resources->create($org1Viewer, [
        'resource_type'      => 'FOOD',
        'resource_name'      => 'Impossible Stock',
        'quantity'           => 10,
        'available_quantity' => 999,
    ]),
    'VALIDATION_ERROR'
));
check('negative quantity rejected', expectResource(
    static fn () => $resources->create($org1Viewer, [
        'resource_type'      => 'FOOD',
        'resource_name'      => 'Negative Stock',
        'quantity'           => -5,
        'available_quantity' => 0,
    ]),
    'VALIDATION_ERROR'
));
check('invalid resource type rejected', expectResource(
    static fn () => $resources->create($org1Viewer, [
        'resource_type'      => 'ROCKETS',
        'resource_name'      => 'Wrong Type',
        'quantity'           => 1,
        'available_quantity' => 1,
    ]),
    'VALIDATION_ERROR'
));
check('missing required field rejected', expectResource(
    static fn () => $resources->create($org1Viewer, [
        'resource_type'      => 'FOOD',
        'quantity'           => 1,
        'available_quantity' => 1,
    ]),
    'VALIDATION_ERROR'
));

$rZero = $resources->create($org1Viewer, [
    'resource_type'      => 'BLANKETS',
    'resource_name'      => 'Empty Blanket Pile',
    'quantity'           => 0,
    'available_quantity' => 0,
]);
$resourceCleanup[] = (int) $rZero['id'];
check('zero quantity is allowed and marked UNAVAILABLE', (string) $rZero['status'] === 'UNAVAILABLE');

// ---------------------------------------------------------------------------
echo "\nResource updates & replenish\n";

$r1Id = (int) $r1['id'];
$updated = $resources->update($org1Viewer, $r1Id, [
    'resource_name'      => 'Rice Sacks (Premium)',
    'quantity'           => 400,
    'available_quantity' => 40,
]);
check('owner update persists name', (string) $updated['resource_name'] === 'Rice Sacks (Premium)');
check('owner update persists quantities', (int) $updated['quantity'] === 400 && (int) $updated['available_quantity'] === 40);
check('status re-derived as LIMITED for low stock', (string) $updated['status'] === 'LIMITED');

check('update with available > quantity rejected', expectResource(
    static fn () => $resources->update($org1Viewer, $r1Id, ['quantity' => 100, 'available_quantity' => 500]),
    'VALIDATION_ERROR'
));

$markedDown = $resources->update($org1Viewer, $r1Id, ['status' => 'UNAVAILABLE']);
check('explicit status marks resource unavailable with stock', (string) $markedDown['status'] === 'UNAVAILABLE');

$restored = $resources->update($org1Viewer, $r1Id, ['status' => '']);
check('empty status restores derived status', (string) $restored['status'] === 'LIMITED');

check('other organization cannot update resource (IDOR)', expectResource(
    static fn () => $resources->update($org2Viewer, $r1Id, ['resource_name' => 'Stolen']),
    'FORBIDDEN'
));
check('citizen cannot update resources', expectResource(
    static fn () => $resources->update($citizenViewer, $r1Id, ['resource_name' => 'Stolen']),
    'FORBIDDEN'
));
check('missing resource update rejected', expectResource(
    static fn () => $resources->update($org1Viewer, 999999999, ['resource_name' => 'Ghost']),
    'RESOURCE_NOT_FOUND'
));

$adminUpdate = $resources->update($adminViewer, $r1Id, ['unit' => 'sacks revised']);
check('admin can update any resource', (string) $adminUpdate['unit'] === 'sacks revised');

$quantityBefore = (int) $updated['quantity'];
$availableBefore = (int) $updated['available_quantity'];
$replenished = $resources->replenish($org1Viewer, $r1Id, 50);
check('replenish increases total quantity', (int) $replenished['quantity'] === $quantityBefore + 50);
check('replenish increases available quantity', (int) $replenished['available_quantity'] === $availableBefore + 50);
check('replenish recalculates status', in_array((string) $replenished['status'], ['AVAILABLE', 'LIMITED'], true));

check('replenish with zero units rejected', expectResource(
    static fn () => $resources->replenish($org1Viewer, $r1Id, 0),
    'VALIDATION_ERROR'
));
check('other organization cannot replenish (IDOR)', expectResource(
    static fn () => $resources->replenish($org2Viewer, $r1Id, 10),
    'FORBIDDEN'
));

// ---------------------------------------------------------------------------
echo "\nAllocation (transactional)\n";

$targets = $resources->allocationTargets($org1Viewer);
$targetIds = array_column($targets, 'id');
check('allocation targets list active requests', in_array((int) $activeRequest['id'], $targetIds, true));
check('allocation targets exclude closed requests', !in_array((int) $closedRequest['id'], $targetIds, true));
check('allocation targets expose no requester details', !isset($targets[0]['user_id']) && !isset($targets[0]['email']));
check('citizen cannot list allocation targets', expectResource(
    static fn () => $resources->allocationTargets($citizenViewer),
    'FORBIDDEN'
));

$eventsBefore = count(RequestEventRepository::forRequest((int) $activeRequest['id']));
$auditsBefore = count(AuditRepository::forEntity('resource', $r1Id));

$allocation = $resources->allocate($org1Viewer, $r1Id, [
    'request_id' => (int) $activeRequest['id'],
    'quantity'   => 50,
]);
check('allocation decrements available quantity', (int) $allocation['resource']['available_quantity'] === 40);
check('allocation reports before/after', (int) $allocation['allocation']['available_before'] === 90
    && (int) $allocation['allocation']['available_after'] === 40);
check('allocation persists on the resource row', (int) ResourceRepository::find($r1Id)['available_quantity'] === 40);
check('allocation keeps total quantity unchanged', (int) ResourceRepository::find($r1Id)['quantity'] === 450);

$events = RequestEventRepository::forRequest((int) $activeRequest['id']);
$allocEvents = array_values(array_filter($events, static fn (array $row): bool => (string) $row['event_type'] === 'RESOURCE_ALLOCATED'));
check('allocation recorded on the request timeline', count($allocEvents) === 1);
check('timeline event carries the allocating actor', (int) $allocEvents[0]['actor_id'] === $org1User);
check('timeline event note names the resource', str_contains((string) $allocEvents[0]['note'], 'Rice Sacks'));

$allocAudits = array_values(array_filter(
    AuditRepository::forEntity('resource', $r1Id),
    static fn (array $row): bool => (string) $row['action'] === 'ALLOCATE_RESOURCE'
));
check('allocation recorded in audit log', count($allocAudits) === 1);

check('over-allocation rejected with INSUFFICIENT_RESOURCE', expectResource(
    static fn () => $resources->allocate($org1Viewer, $r1Id, [
        'request_id' => (int) $activeRequest['id'],
        'quantity'   => 1000,
    ]),
    'INSUFFICIENT_RESOURCE'
));
check('failed allocation leaves quantity unchanged', (int) ResourceRepository::find($r1Id)['available_quantity'] === 40);
check('failed allocation adds no timeline event', count(RequestEventRepository::forRequest((int) $activeRequest['id'])) === $eventsBefore + 1);
check('failed allocation adds no audit entry', count(AuditRepository::forEntity('resource', $r1Id)) === $auditsBefore + 1);

check('allocation quantity must be positive', expectResource(
    static fn () => $resources->allocate($org1Viewer, $r1Id, [
        'request_id' => (int) $activeRequest['id'],
        'quantity'   => 0,
    ]),
    'VALIDATION_ERROR'
));
check('allocation to missing request rejected', expectResource(
    static fn () => $resources->allocate($org1Viewer, $r1Id, ['request_id' => 999999999, 'quantity' => 5]),
    'REQUEST_NOT_FOUND'
));
check('allocation to closed request rejected', expectResource(
    static fn () => $resources->allocate($org1Viewer, $r1Id, [
        'request_id' => (int) $closedRequest['id'],
        'quantity'   => 5,
    ]),
    'INVALID_REQUEST_STATUS'
));
check('other organization cannot allocate (IDOR)', expectResource(
    static fn () => $resources->allocate($org2Viewer, $r1Id, [
        'request_id' => (int) $activeRequest['id'],
        'quantity'   => 5,
    ]),
    'FORBIDDEN'
));
check('unavailable resource cannot be allocated', expectResource(
    static fn () => $resources->allocate($org1Viewer, (int) $rZero['id'], [
        'request_id' => (int) $activeRequest['id'],
        'quantity'   => 1,
    ]),
    'RESOURCE_NOT_AVAILABLE'
));

// Atomicity: the whole allocation unit (decrement + event + audit) must roll
// back together when the surrounding transaction fails.
$rollbackAvailable = (int) ResourceRepository::find($r1Id)['available_quantity'];
$rollbackEvents = count(RequestEventRepository::forRequest((int) $activeRequest['id']));
$rollbackAudits = count(AuditRepository::forEntity('resource', $r1Id));
$rollbackCaught = false;

try {
    Database::transaction(static function () use ($resources, $org1Viewer, $r1Id, $activeRequest): void {
        $resources->allocate($org1Viewer, $r1Id, [
            'request_id' => (int) $activeRequest['id'],
            'quantity'   => 10,
        ]);

        throw new RuntimeException('force rollback after nested allocation');
    });
} catch (RuntimeException $exception) {
    $rollbackCaught = str_contains($exception->getMessage(), 'force rollback');
}

check('outer transaction failure propagates', $rollbackCaught);
check('nested allocation joined outer transaction', (int) ResourceRepository::find($r1Id)['available_quantity'] === $rollbackAvailable);
check('allocation event rolled back with the transaction', count(RequestEventRepository::forRequest((int) $activeRequest['id'])) === $rollbackEvents);
check('allocation audit rolled back with the transaction', count(AuditRepository::forEntity('resource', $r1Id)) === $rollbackAudits);

// Final small allocation keeps stock for the web/API sections.
$finalAllocation = $resources->allocate($org1Viewer, $r1Id, [
    'request_id' => (int) $activeRequest['id'],
    'quantity'   => 10,
]);
check('subsequent allocation succeeds after rollback test', (int) $finalAllocation['resource']['available_quantity'] === 30);

// ---------------------------------------------------------------------------
echo "\nVisibility & scoping\n";

$publicList = $resources->list($citizenViewer, ['limit' => 100]);
check('public list returns items + pagination', isset($publicList['items'][0], $publicList['pagination']));
$publicOrgNames = array_column($publicList['items'], 'organization_name');
check('public list hides pending organizations', !in_array('Pending Shelter Service', $publicOrgNames, true)
    && !in_array('Safe Haven Shelter', $publicOrgNames, true));
check('public list hides unavailable resources', array_filter(
    $publicList['items'],
    static fn (array $row): bool => (string) $row['status'] === 'UNAVAILABLE'
) === []);
check('public list hides private coordinates', !isset($publicList['items'][0]['latitude']));
check('public list hides the zero-stock resource', !in_array(
    (int) $rZero['id'],
    array_column($publicList['items'], 'id'),
    true
));

$org2List = $resources->list($org2Viewer, ['limit' => 100]);
check('organization sees its own inventory', in_array((int) $r2['id'], array_column($org2List['items'], 'id'), true));
check('organization own list includes private coordinates', array_key_exists('latitude', $org2List['items'][0]));
check('organization own list includes null-stock rows', in_array(
    (int) $rZero['id'],
    array_column($resources->list($org1Viewer, ['limit' => 100])['items'], 'id'),
    true
));

check('organization cannot list another organization (IDOR)', expectResource(
    static fn () => $resources->list($org1Viewer, ['organization_id' => $org2Id]),
    'FORBIDDEN'
));

$foodOnly = $resources->list($org1Viewer, ['resource_type' => 'food', 'limit' => 100]);
check('type filter applies case-insensitively', array_filter(
    $foodOnly['items'],
    static fn (array $row): bool => (string) $row['resource_type'] !== 'FOOD'
) === []);

check('invalid type filter rejected', expectResource(
    static fn () => $resources->list($citizenViewer, ['resource_type' => 'WEAPONS']),
    'VALIDATION_ERROR'
));

$paged = $resources->list($citizenViewer, ['page' => 1, 'limit' => 2]);
check('pagination limits page size', count($paged['items']) <= 2);
check('pagination reports totals', $paged['pagination']['total'] >= count($publicList['items'])
    && $paged['pagination']['pages'] >= 1 && $paged['pagination']['limit'] === 2);

$ownRead = $resources->findVisible($org1Viewer, $r1Id);
check('owner single read exposes coordinates', array_key_exists('latitude', $ownRead));
check('public single read hides coordinates', !isset($resources->findVisible($citizenViewer, $r1Id)['latitude']));
check('public single read of unavailable resource 404s', expectResource(
    static fn () => $resources->findVisible($citizenViewer, (int) $rZero['id']),
    'RESOURCE_NOT_FOUND'
));
check('other organization single read forbidden', expectResource(
    static fn () => $resources->findVisible($org2Viewer, $r1Id),
    'FORBIDDEN'
));

// ---------------------------------------------------------------------------
echo "\nWeb controllers\n";

$_SESSION['user_id'] = $org1User;
$_SESSION['user'] = $org1Viewer;

$orgWeb = new OrganizationController();
$resWeb = new ResourceController();

$dashboardPage = $orgWeb->dashboard(make_request('GET', '/organization/dashboard'));
$dashboardHtml = (string) $dashboardPage->body();
check('organization dashboard renders', $dashboardPage->status() === 200);
check('dashboard shows inventory stats', str_contains($dashboardHtml, 'Available quantity'));
check('dashboard lists own resources', str_contains($dashboardHtml, 'Rice Sacks'));
check('dashboard offers allocation targets', str_contains($dashboardHtml, 'Allocate to request'));

$profilePage = $orgWeb->profile(make_request('GET', '/organization/profile'));
check('organization profile page renders', $profilePage->status() === 200
    && str_contains((string) $profilePage->body(), 'Edit your organization profile'));

$profilePost = $orgWeb->updateProfile(make_request('POST', '/organization/profile', [
    'name' => 'Phase Six Relief Centre',
    'type' => 'NGO',
]));
check('profile POST redirects (PRG)', $profilePost->status() === 302);
check('profile POST persists', (string) OrganizationRepository::find($org1Id)['name'] === 'Phase Six Relief Centre');

$orgWeb->updateProfile(make_request('POST', '/organization/profile', [
    'name' => 'Phase Six Relief Centre',
    'type' => 'NOT_A_TYPE',
]));
$profileErrors = pull_flash('form_errors');
check('invalid profile POST stores field errors', is_array($profileErrors) && $profileErrors !== []);
pull_flash('_old_input');
check('invalid profile POST keeps stored name', (string) OrganizationRepository::find($org1Id)['name'] === 'Phase Six Relief Centre');

$storeResponse = $resWeb->store(make_request('POST', '/organization/resources', [
    'resource_type'      => 'WATER',
    'resource_name'      => 'Web Created Water',
    'quantity'           => 25,
    'available_quantity' => 20,
    'unit'               => 'bottles',
]));
check('web resource create redirects (PRG)', $storeResponse->status() === 302);
$webResource = Database::selectOne('SELECT * FROM resources WHERE resource_name = :name LIMIT 1', ['name' => 'Web Created Water']);
check('web resource create persists', is_array($webResource) && (int) $webResource['organization_id'] === $org1Id);
$webResourceId = (int) ($webResource['id'] ?? 0);
$resourceCleanup[] = $webResourceId;
check('web resource status derived', $webResourceId > 0 && (string) $webResource['status'] === 'AVAILABLE');

$editPage = $resWeb->edit(make_request('GET', '/organization/resources/' . $webResourceId . '/edit'), (string) $webResourceId);
check('resource edit page renders', $editPage->status() === 200
    && str_contains((string) $editPage->body(), 'Web Created Water'));

$replenishResponse = $resWeb->replenish(
    make_request('POST', '/organization/resources/' . $webResourceId . '/replenish', ['units' => 5]),
    (string) $webResourceId
);
check('web replenish redirects (PRG)', $replenishResponse->status() === 302);
$replenishedRow = ResourceRepository::find($webResourceId);
check('web replenish persists', (int) $replenishedRow['quantity'] === 30 && (int) $replenishedRow['available_quantity'] === 25);

$updateResponse = $resWeb->update(make_request('POST', '/organization/resources/' . $webResourceId, [
    'resource_type'      => 'WATER',
    'resource_name'      => 'Web Created Water v2',
    'quantity'           => 30,
    'available_quantity' => 25,
    'unit'               => 'bottles',
    'status'             => '',
]), (string) $webResourceId);
check('web resource update persists', $updateResponse->status() === 302
    && (string) ResourceRepository::find($webResourceId)['resource_name'] === 'Web Created Water v2');

$allocateResponse = $resWeb->allocate(
    make_request('POST', '/organization/resources/' . $webResourceId . '/allocate', [
        'request_id' => (int) $activeRequest['id'],
        'quantity'   => 5,
    ]),
    (string) $webResourceId
);
check('web allocation redirects (PRG)', $allocateResponse->status() === 302);
check('web allocation decrements stock', (int) ResourceRepository::find($webResourceId)['available_quantity'] === 20);

// Middleware guards (routes rely on these, not on controller checks).
$roleOrganization = new RoleMiddleware(['ORGANIZATION']);
$roleAdmin = new RoleMiddleware(['ADMIN']);

$_SESSION['user_id'] = $citizenId;
$_SESSION['user'] = $citizenViewer;
$citizenBlocked = $roleOrganization->handle(
    make_request('GET', '/organization/dashboard'),
    static fn (): Response => Response::html('OK', 200)
);
check('RoleMiddleware blocks citizens on organization routes', $citizenBlocked->status() === 403);

$_SESSION['user_id'] = $org1User;
$_SESSION['user'] = $org1Viewer;
$orgBlocked = $roleAdmin->handle(
    make_request('GET', '/admin/organizations'),
    static fn (): Response => Response::html('OK', 200)
);
check('RoleMiddleware blocks organizations on admin routes', $orgBlocked->status() === 403);

$adminWeb = new AdminOrganizationController();
$_SESSION['user_id'] = $adminId;
$_SESSION['user'] = $adminViewer;
$adminQueue = $adminWeb->index(make_request('GET', '/admin/organizations'));
check('admin organization queue renders', $adminQueue->status() === 200
    && str_contains((string) $adminQueue->body(), 'Organization verification'));

$adminVerifyResponse = $adminWeb->verify(
    make_request('POST', '/admin/organizations/' . $org3Id . '/verify', ['verification_status' => 'VERIFIED']),
    (string) $org3Id
);
check('admin web verify redirects (PRG)', $adminVerifyResponse->status() === 302);
check('admin web verify persists', (string) OrganizationRepository::find($org3Id)['verification_status'] === 'VERIFIED');

// CSRF guard: state-changing API calls without a token must fail.
$csrfMiddleware = new CsrfMiddleware();
$csrfBlocked = $csrfMiddleware->handle(
    make_request('POST', '/organization/resources', ['resource_type' => 'FOOD']),
    static fn (): Response => Response::html('OK', 200)
);
check('CSRF middleware blocks token-less mutations', $csrfBlocked->status() === 403);

// ---------------------------------------------------------------------------
echo "\nAPI controllers\n";

$csrf = csrf_token();
$orgApi = new OrganizationApiController();
$resApi = new ResourceApiController();

$_SESSION['user_id'] = $org1User;
$_SESSION['user'] = $org1Viewer;

$apiProfile = $orgApi->profile(make_request('GET', '/api/v1/organizations/profile'));
$apiProfileBody = json_decode((string) $apiProfile->body(), true);
check('API GET organization profile succeeds', $apiProfile->status() === 200 && ($apiProfileBody['success'] ?? null) === true);
check('API profile returns verification status', (string) ($apiProfileBody['data']['verification_status'] ?? '') === 'VERIFIED');
check('API profile returns location block', isset($apiProfileBody['data']['location']));

$apiPatch = $orgApi->updateProfile(make_request('PATCH', '/api/v1/organizations/profile', [
    'name'    => 'Phase Six Relief HQ',
    'contact' => '9111111111',
], ['x-csrf-token' => $csrf], true));
$apiPatchBody = json_decode((string) $apiPatch->body(), true);
check('API PATCH organization profile succeeds', $apiPatch->status() === 200);
check('API PATCH persists fields', (string) ($apiPatchBody['data']['name'] ?? '') === 'Phase Six Relief HQ'
    && (string) OrganizationRepository::find($org1Id)['contact'] === '9111111111');

$apiInvalidProfile = $orgApi->updateProfile(make_request('PATCH', '/api/v1/organizations/profile', [
    'type' => 'NOT_A_TYPE',
], ['x-csrf-token' => $csrf], true));
$apiInvalidBody = json_decode((string) $apiInvalidProfile->body(), true);
check('API PATCH validation failure uses the error envelope', $apiInvalidProfile->status() === 422
    && ($apiInvalidBody['success'] ?? null) === false && (string) ($apiInvalidBody['code'] ?? '') === 'VALIDATION_ERROR');

// Resource API as a citizen (public inventory scope).
$_SESSION['user_id'] = $citizenId;
$_SESSION['user'] = $citizenViewer;
$apiIndex = $resApi->index(make_request('GET', '/api/v1/resources', [], [], true));
$apiIndexBody = json_decode((string) $apiIndex->body(), true);
check('API GET resources succeeds', $apiIndex->status() === 200);
check('API resources return items + pagination', isset($apiIndexBody['data']['items'][0], $apiIndexBody['data']['pagination']));
check('API public resources hide coordinates', !isset($apiIndexBody['data']['items'][0]['latitude']));

// Resource API as the verified organization.
$_SESSION['user_id'] = $org1User;
$_SESSION['user'] = $org1Viewer;

$apiStore = $resApi->store(make_request('POST', '/api/v1/resources', [
    'resource_type'      => 'MEDICAL_SUPPORT',
    'resource_name'      => 'API Created Kits',
    'quantity'           => 20,
    'available_quantity' => 20,
    'unit'               => 'kits',
], ['x-csrf-token' => $csrf], true));
$apiStoreBody = json_decode((string) $apiStore->body(), true);
$apiResourceId = (int) ($apiStoreBody['data']['id'] ?? 0);
$resourceCleanup[] = $apiResourceId;
check('API POST resources returns 201', $apiStore->status() === 201);
check('API POST resources persists', $apiResourceId > 0 && (string) ResourceRepository::find($apiResourceId)['resource_name'] === 'API Created Kits');

$apiStoreInvalid = $resApi->store(make_request('POST', '/api/v1/resources', [
    'resource_type'      => 'MEDICAL_SUPPORT',
    'resource_name'      => 'Too Many Kits',
    'quantity'           => 5,
    'available_quantity' => 500,
], ['x-csrf-token' => $csrf], true));
$apiStoreInvalidBody = json_decode((string) $apiStoreInvalid->body(), true);
check('API POST rejects available > quantity', $apiStoreInvalid->status() === 422
    && (string) ($apiStoreInvalidBody['code'] ?? '') === 'VALIDATION_ERROR');

// IDOR: org2 patching org1's resource.
$_SESSION['user_id'] = $org2User;
$_SESSION['user'] = $org2Viewer;
$apiPatchResource = $resApi->update(make_request('PATCH', '/api/v1/resources/' . $r1Id, [
    'resource_name' => 'Hijacked',
], ['x-csrf-token' => $csrf], true), (string) $r1Id);
$apiPatchResourceBody = json_decode((string) $apiPatchResource->body(), true);
check('API PATCH enforces ownership', $apiPatchResource->status() === 403
    && (string) ($apiPatchResourceBody['code'] ?? '') === 'FORBIDDEN');
check('IDOR attempt did not modify the resource', (string) ResourceRepository::find($r1Id)['resource_name'] === 'Rice Sacks (Premium)');

// Allocation via the API (org1 session).
$_SESSION['user_id'] = $org1User;
$_SESSION['user'] = $org1Viewer;

$apiAllocate = $resApi->allocate(make_request('POST', '/api/v1/resources/' . $r1Id . '/allocate', [
    'request_id' => (int) $activeRequest['id'],
    'quantity'   => 5,
], ['x-csrf-token' => $csrf], true), (string) $r1Id);
$apiAllocateBody = json_decode((string) $apiAllocate->body(), true);
check('API allocate succeeds', $apiAllocate->status() === 200);
check('API allocate returns resource + allocation blocks', isset($apiAllocateBody['data']['resource'], $apiAllocateBody['data']['allocation']));
check('API allocate decrements stock', (int) ($apiAllocateBody['data']['resource']['available_quantity'] ?? -1) === 25);

$apiAllocateFail = $resApi->allocate(make_request('POST', '/api/v1/resources/' . $r1Id . '/allocate', [
    'request_id' => (int) $activeRequest['id'],
    'quantity'   => 9999,
], ['x-csrf-token' => $csrf], true), (string) $r1Id);
$apiAllocateFailBody = json_decode((string) $apiAllocateFail->body(), true);
check('API allocate reports INSUFFICIENT_RESOURCE', $apiAllocateFail->status() === 409
    && (string) ($apiAllocateFailBody['code'] ?? '') === 'INSUFFICIENT_RESOURCE');

// Admin organization API.
$_SESSION['user_id'] = $adminId;
$_SESSION['user'] = $adminViewer;

$apiAdminIndex = $orgApi->adminIndex(make_request('GET', '/api/v1/admin/organizations'));
$apiAdminIndexBody = json_decode((string) $apiAdminIndex->body(), true);
check('API admin organization list succeeds', $apiAdminIndex->status() === 200 && is_array($apiAdminIndexBody['data']['items'] ?? null));
check('API admin list hides account email', !str_contains((string) $apiAdminIndex->body(), '@resqlink.test'));

$apiAdminVerify = $orgApi->adminVerify(
    make_request('POST', '/api/v1/admin/organizations/' . $org3Id . '/verify', ['verification_status' => 'VERIFIED'], ['x-csrf-token' => $csrf], true),
    (string) $org3Id
);
$apiAdminVerifyBody = json_decode((string) $apiAdminVerify->body(), true);
check('API admin verify succeeds', $apiAdminVerify->status() === 200
    && (string) ($apiAdminVerifyBody['data']['verification_status'] ?? '') === 'VERIFIED');

$apiAdminReject = $orgApi->adminReject(
    make_request('POST', '/api/v1/admin/organizations/' . $org3Id . '/reject', ['reason' => 'Documents unreadable.'], ['x-csrf-token' => $csrf], true),
    (string) $org3Id
);
$apiAdminRejectBody = json_decode((string) $apiAdminReject->body(), true);
check('API admin reject succeeds', $apiAdminReject->status() === 200
    && (string) ($apiAdminRejectBody['data']['verification_status'] ?? '') === 'REJECTED');

// Route-level role guard for the organization API surface.
$_SESSION['user_id'] = $volunteerId;
$_SESSION['user'] = $volunteerViewer;
$volunteerBlockedApi = $roleOrganization->handle(
    make_request('GET', '/api/v1/organizations/profile'),
    static fn (): Response => Response::html('OK', 200)
);
$volunteerBlockedBody = json_decode((string) $volunteerBlockedApi->body(), true);
check('RoleMiddleware blocks volunteers from the org API', $volunteerBlockedApi->status() === 403
    && (string) ($volunteerBlockedBody['code'] ?? '') === 'FORBIDDEN');

// ---------------------------------------------------------------------------
echo "\nSecurity notes\n";

check('resource exception messages are safe', !str_contains(
    (new ResourceException('VALIDATION_ERROR', 'Validation failed.', 422, []))->userMessage(),
    'SELECT'
));
check('dashboard does not leak SQL', !str_contains(strtolower($dashboardHtml), 'sql syntax')
    && !str_contains($dashboardHtml, 'SELECT * FROM'));
$guardMessage = (string) ($volunteerBlockedBody['message'] ?? '');
check('organization role guard does not leak internals', $guardMessage !== '' && !str_contains($guardMessage, 'SQL'));

// ---------------------------------------------------------------------------
echo "\nCleanup\n";

try {
    foreach (array_unique($resourceCleanup) as $resourceId) {
        if ($resourceId > 0 && ResourceRepository::find($resourceId) !== null) {
            try {
                ResourceRepository::delete($resourceId);
            } catch (Throwable) {
                // Matches may NULL the resource link; orphaned rows are
                // verified removed below.
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

            // Attachments RESTRICT user deletes by uploader, and requests
            // cascade their attachments: remove attachments (files + rows)
            // before deleting the request, mirroring the emergency suite.
            foreach (AttachmentRepository::forRequest($requestId) as $row) {
                $path = storage_path((string) $row['storage_path']);

                if (is_file($path)) {
                    unlink($path);
                }

                Database::execute('DELETE FROM emergency_attachments WHERE id = :id', ['id' => (int) $row['id']]);
            }

            EmergencyRequestRepository::delete($requestId);
        }

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

check('test users removed', UserRepository::find($adminId) === null && UserRepository::find($org1User) === null);
check('test organizations removed with users', OrganizationRepository::find($org1Id) === null);
check('test resources removed', ResourceRepository::find($r1Id) === null && ResourceRepository::find($webResourceId) === null);
check('test requests removed', EmergencyRequestRepository::find((int) $activeRequest['id']) === null);
check('test audit rows removed', (int) (Database::selectOne('SELECT COUNT(*) AS aggregate FROM audit_logs WHERE id > :id', ['id' => $auditStart])['aggregate'] ?? 0) === 0);
check('seeded inventory untouched', ResourceRepository::count() === 8);

echo "\n----------------------------------------\n";
echo "Passed: {$passed}   Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
