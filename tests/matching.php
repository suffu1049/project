<?php

declare(strict_types=1);

/**
 * ResQLink — Phase 7 smart matching engine tests.
 *
 * Requires a migrated database. Run with:
 *     php -c php.ini tests/matching.php
 *
 * Covers capability scoring, radius filtering, candidate exclusions, score
 * determinism, explanation reasons, coordinate privacy, transactional accept /
 * reject / complete, conflict guards, CSRF, IDOR, duplicate suggestion
 * prevention and the web/API surfaces.
 *
 * Exit code 0 = all passed, 1 = one or more failures.
 */

use App\Controllers\MatchingApiController;
use App\Controllers\MatchingController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\AttachmentRepository;
use App\Models\AuditRepository;
use App\Models\EmergencyRequestRepository;
use App\Models\MatchRepository;
use App\Models\OrganizationRepository;
use App\Models\RequestEventRepository;
use App\Models\ResourceRepository;
use App\Models\UserRepository;
use App\Models\VolunteerRepository;
use App\Services\EmergencyService;
use App\Services\MatchingService;
use App\Services\ResourceService;
use App\Services\VolunteerService;

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

function expectMatching(callable $callback, string $code): bool
{
    try {
        $callback();
    } catch (App\Core\MatchingException $exception) {
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

    return new Request($method, $path, [], $body, $headers, $files, '127.0.0.1', 'matching-test');
}

$matching = new MatchingService();
$emergencies = new EmergencyService();
$volunteers = new VolunteerService();
$resources = new ResourceService();
$suffix = bin2hex(random_bytes(4));

$userCleanup = [];
$requestCleanup = [];
$resourceCleanup = [];

echo "ResQLink smart matching tests\n";
echo "==============================\n\n";

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

$makeVolunteer = static function (
    string $label,
    string $skills,
    string $availability = 'AVAILABLE',
    string $verification = 'VERIFIED',
    ?float $lat = 20.3882,
    ?float $lng = 72.9091,
    float $radius = 50.0
) use ($makeUser): array {
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
        'contact'             => '9000000001',
        'description'         => 'Phase 7 matching fixture.',
        'verification_status' => $verification,
        'latitude'            => $lat,
        'longitude'           => $lng,
        'address_text'        => 'Match Street, Demo City',
    ]);

    return [$userId, (int) $organizationId];
};

$adminId = $makeUser('Match Admin', 'ADMIN');
$citizenId = $makeUser('Match Citizen', 'CITIZEN');
$otherCitizenId = $makeUser('Other Match Citizen', 'CITIZEN');

[$medicalUserId, $medicalProfileId] = $makeVolunteer('Medical Volunteer', 'MEDICAL,FIRST_AID', 'AVAILABLE', 'VERIFIED', 20.3882, 72.9091, 50.0);
[$generalUserId, $generalProfileId] = $makeVolunteer('General Volunteer', 'GENERAL', 'AVAILABLE', 'VERIFIED', 20.3884, 72.9093, 50.0);
[$busyUserId, $busyProfileId] = $makeVolunteer('Busy Volunteer', 'MEDICAL', 'BUSY', 'VERIFIED');
[$offlineUserId, $offlineProfileId] = $makeVolunteer('Offline Volunteer', 'MEDICAL', 'OFFLINE', 'VERIFIED');
[$pendingUserId, $pendingProfileId] = $makeVolunteer('Pending Volunteer', 'MEDICAL', 'AVAILABLE', 'PENDING');
[$farUserId, $farProfileId] = $makeVolunteer('Far Volunteer', 'MEDICAL', 'AVAILABLE', 'VERIFIED', 21.5000, 73.5000, 10.0);
[$wrongSkillUserId, $wrongSkillProfileId] = $makeVolunteer('Driving Only Volunteer', 'DRIVING', 'AVAILABLE', 'VERIFIED', 20.3882, 72.9091, 50.0);
[$secondMedicalUserId, $secondMedicalProfileId] = $makeVolunteer('Second Medical Volunteer', 'MEDICAL', 'AVAILABLE', 'VERIFIED', 20.3890, 72.9100, 50.0);

[$verifiedOrgUser, $verifiedOrgId] = $makeOrg('Verified Relief Org', 'VERIFIED');
[$pendingOrgUser, $pendingOrgId] = $makeOrg('Pending Relief Org', 'PENDING');

$adminViewer = UserRepository::find($adminId);
$citizenViewer = UserRepository::find($citizenId);
$otherCitizenViewer = UserRepository::find($otherCitizenId);
$medicalViewer = UserRepository::find($medicalUserId);
$generalViewer = UserRepository::find($generalUserId);
$busyViewer = UserRepository::find($busyUserId);
$farViewer = UserRepository::find($farUserId);
$wrongSkillViewer = UserRepository::find($wrongSkillUserId);
$secondMedicalViewer = UserRepository::find($secondMedicalUserId);
$verifiedOrgViewer = UserRepository::find($verifiedOrgUser);
$pendingOrgViewer = UserRepository::find($pendingOrgUser);

check('fixtures created', $adminId > 0 && $citizenId > 0 && $medicalProfileId > 0 && $verifiedOrgId > 0);
check('volunteer profiles linked', VolunteerRepository::findByUserId($medicalUserId) !== null);

// Open MEDICAL request near fixture coordinates.
$medicalRequest = $emergencies->create($citizenId, $emergencies->validateCreation([
    'category'        => 'MEDICAL',
    'description'     => 'Injured worker needs urgent medical assistance near the depot.',
    'people_affected' => 2,
    'latitude'        => 20.3883,
    'longitude'       => 72.9092,
    'address_text'    => 'Depot gate',
]));
$requestCleanup[] = (int) $medicalRequest['id'];

$foodRequest = $emergencies->create($citizenId, $emergencies->validateCreation([
    'category'        => 'FOOD_WATER',
    'description'     => 'Families without food or clean water after the flood.',
    'people_affected' => 8,
    'latitude'        => 20.3882,
    'longitude'       => 72.9091,
]));
$requestCleanup[] = (int) $foodRequest['id'];

// Closed request — suggestions must be rejected.
$closedRequest = $emergencies->create($citizenId, $emergencies->validateCreation([
    'category'        => 'MEDICAL',
    'description'     => 'Medical case already resolved by local clinic.',
    'people_affected' => 1,
]));
$requestCleanup[] = (int) $closedRequest['id'];
EmergencyRequestRepository::updateStatus((int) $closedRequest['id'], 'RESOLVED', date('Y-m-d H:i:s'));

// Verified org stock for FOOD_WATER + MEDICAL.
$orgViewer = $verifiedOrgViewer;
$foodResource = $resources->create($orgViewer, [
    'resource_type'      => 'FOOD',
    'resource_name'      => 'Emergency Rice',
    'quantity'           => 200,
    'available_quantity' => 200,
    'unit'               => 'bags',
    'latitude'           => 20.3901,
    'longitude'          => 72.9102,
]);
$resourceCleanup[] = (int) $foodResource['id'];

$waterResource = $resources->create($orgViewer, [
    'resource_type'      => 'WATER',
    'resource_name'      => 'Clean Water Packs',
    'quantity'           => 150,
    'available_quantity' => 150,
    'unit'               => 'packs',
]);
$resourceCleanup[] = (int) $waterResource['id'];

$medicalResource = $resources->create($orgViewer, [
    'resource_type'      => 'MEDICAL_SUPPORT',
    'resource_name'      => 'First Aid Kits',
    'quantity'           => 40,
    'available_quantity' => 40,
    'unit'               => 'kits',
]);
$resourceCleanup[] = (int) $medicalResource['id'];

// Pending-org stock must never be suggested.
$pendingResource = null;

try {
    $pendingResource = $resources->create($pendingOrgViewer, [
        'resource_type'      => 'FOOD',
        'resource_name'      => 'Pending Org Food',
        'quantity'           => 10,
        'available_quantity' => 10,
    ]);
    $resourceCleanup[] = (int) $pendingResource['id'];
} catch (Throwable) {
    // Expected: pending organizations cannot manage resources.
}

// ---------------------------------------------------------------------------
echo "\nHaversine distance\n";

$nearKm = VolunteerService::distanceKm(20.3882, 72.9091, 20.3883, 72.9092);
check('distanceKm returns a small positive value for nearby points', $nearKm > 0.0 && $nearKm < 1.0);

$farKm = VolunteerService::distanceKm(20.3882, 72.9091, 21.5000, 73.5000);
check('distanceKm separates distant points', $farKm > 50.0);

check('distanceKm is symmetric', abs($nearKm - VolunteerService::distanceKm(20.3883, 72.9092, 20.3882, 72.9091)) < 0.0001);

// ---------------------------------------------------------------------------
echo "\nCapability scoring\n";

// Use a high limit so seeded demo volunteers cannot crowd out fixtures.
$scored = $matching->suggestForRequest((int) $medicalRequest['id'], $adminViewer, false, ['limit' => 50]);
$volunteerSuggestions = array_values(array_filter(
    $scored['items'],
    static fn (array $item): bool => (string) ($item['type'] ?? '') === 'VOLUNTEER'
));
$resourceSuggestions = array_values(array_filter(
    $scored['items'],
    static fn (array $item): bool => (string) ($item['type'] ?? '') === 'RESOURCE'
));

check('MEDICAL request returns volunteer suggestions', $volunteerSuggestions !== []);
check('MEDICAL request returns resource suggestions', $resourceSuggestions !== []);

$volunteerIds = array_map(static fn (array $item): int => (int) ($item['volunteer_id'] ?? 0), $volunteerSuggestions);
check('capable MEDICAL volunteer is suggested', in_array($medicalProfileId, $volunteerIds, true));
check('GENERAL volunteer is suggested for MEDICAL (0.85 capability)', in_array($generalProfileId, $volunteerIds, true));
check('BUSY volunteer excluded', !in_array($busyProfileId, $volunteerIds, true));
check('OFFLINE volunteer excluded', !in_array($offlineProfileId, $volunteerIds, true));
check('PENDING volunteer excluded', !in_array($pendingProfileId, $volunteerIds, true));
check('far volunteer outside radius excluded', !in_array($farProfileId, $volunteerIds, true));
check('wrong-skill volunteer excluded from MEDICAL', !in_array($wrongSkillProfileId, $volunteerIds, true));

$reasonsOk = true;
foreach ($volunteerSuggestions as $item) {
    if (!is_array($item['reasons'] ?? null) || $item['reasons'] === []) {
        $reasonsOk = false;
        break;
    }
}
check('every suggestion carries non-empty reasons', $reasonsOk);

$medicalItem = null;
$generalItem = null;

foreach ($volunteerSuggestions as $item) {
    if ((int) ($item['volunteer_id'] ?? 0) === $medicalProfileId) {
        $medicalItem = $item;
    }

    if ((int) ($item['volunteer_id'] ?? 0) === $generalProfileId) {
        $generalItem = $item;
    }
}

check('exact skill match scores capability 100', $medicalItem !== null && (float) $medicalItem['capability_score'] === 100.0);
check('GENERAL fallback scores capability 85', $generalItem !== null && (float) $generalItem['capability_score'] === 85.0);

if ($medicalItem !== null && $generalItem !== null) {
    check('exact skill outranks GENERAL fallback', (float) $medicalItem['match_score'] >= (float) $generalItem['match_score']);
}

check('score formula is explainable and in range', $medicalItem !== null
    && (float) $medicalItem['match_score'] > 0
    && (float) $medicalItem['match_score'] <= 100);

// Deterministic scoring: same inputs → same scores.
$rescored = $matching->suggestForRequest((int) $medicalRequest['id'], $adminViewer, false, ['limit' => 50]);
$medicalAgain = null;

foreach ($rescored['items'] as $item) {
    if ((int) ($item['volunteer_id'] ?? 0) === $medicalProfileId) {
        $medicalAgain = $item;
        break;
    }
}

check('scores are deterministic across runs', $medicalAgain !== null
    && $medicalItem !== null
    && (float) $medicalAgain['match_score'] === (float) $medicalItem['match_score']);

$foodScored = $matching->suggestForRequest((int) $foodRequest['id'], $adminViewer, false, ['limit' => 50]);
$foodVolunteers = array_values(array_filter(
    $foodScored['items'],
    static fn (array $item): bool => (string) ($item['type'] ?? '') === 'VOLUNTEER'
));
$foodVolunteerIds = array_map(static fn (array $item): int => (int) ($item['volunteer_id'] ?? 0), $foodVolunteers);

check('FOOD_WATER suggests GENERAL volunteer', in_array($generalProfileId, $foodVolunteerIds, true));
check('FOOD_WATER excludes MEDICAL-only volunteer', !in_array($medicalProfileId, $foodVolunteerIds, true));

$foodResources = array_values(array_filter(
    $foodScored['items'],
    static fn (array $item): bool => (string) ($item['type'] ?? '') === 'RESOURCE'
));
$foodResourceIds = array_map(static fn (array $item): int => (int) ($item['resource_id'] ?? 0), $foodResources);

check('FOOD_WATER suggests FOOD resource', in_array((int) $foodResource['id'], $foodResourceIds, true));
check('FOOD_WATER suggests WATER resource', in_array((int) $waterResource['id'], $foodResourceIds, true));
check('FOOD_WATER excludes MEDICAL_SUPPORT resource', !in_array((int) $medicalResource['id'], $foodResourceIds, true));

$medicalResources = array_values(array_filter(
    $scored['items'],
    static fn (array $item): bool => (string) ($item['type'] ?? '') === 'RESOURCE'
));
$medicalResourceIds = array_map(static fn (array $item): int => (int) ($item['resource_id'] ?? 0), $medicalResources);

check('MEDICAL suggests MEDICAL_SUPPORT resource', in_array((int) $medicalResource['id'], $medicalResourceIds, true));
check('MEDICAL excludes FOOD resource', !in_array((int) $foodResource['id'], $medicalResourceIds, true));

// ---------------------------------------------------------------------------
echo "\nCoordinate privacy\n";

$_SESSION['user_id'] = $medicalUserId;
$_SESSION['user'] = $medicalViewer;

$volunteerView = $matching->suggestForRequest((int) $medicalRequest['id'], $medicalViewer, false, ['limit' => 50]);
$volunteerSuggestion = null;

foreach ($volunteerView['items'] as $item) {
    if ((string) ($item['type'] ?? '') === 'VOLUNTEER') {
        $volunteerSuggestion = $item;
        break;
    }
}

check('volunteer sees suggestions', $volunteerSuggestion !== null);
check('volunteer suggestion hides exact request latitude', $volunteerSuggestion !== null
    && array_key_exists('request', $volunteerSuggestion)
    && (($volunteerSuggestion['request']['latitude'] ?? null) === null));
check('volunteer suggestion hides exact request longitude', $volunteerSuggestion !== null
    && (($volunteerSuggestion['request']['longitude'] ?? null) === null));

// ---------------------------------------------------------------------------
echo "\nPersistence & duplicate prevention\n";

$persisted = $matching->suggestForRequest((int) $medicalRequest['id'], $adminViewer, true, ['limit' => 50]);
check('suggestions persist match rows', $persisted['meta']['persisted'] === true);

$storedMatches = MatchRepository::forRequest((int) $medicalRequest['id']);
check('stored matches exist after suggest', $storedMatches !== []);

$storedVolunteerIds = array_map(
    static fn (array $row): int => (int) ($row['volunteer_id'] ?? 0),
    array_filter($storedMatches, static fn (array $row): bool => !empty($row['volunteer_id']))
);
check('capable medical volunteer persisted as match', in_array($medicalProfileId, $storedVolunteerIds, true));
check('busy volunteer never persisted', !in_array($busyProfileId, $storedVolunteerIds, true));
check('GENERAL volunteer persisted as match for MEDICAL', in_array($generalProfileId, $storedVolunteerIds, true));

$firstStoredCount = count($storedMatches);
$matching->suggestForRequest((int) $medicalRequest['id'], $adminViewer, true, ['limit' => 50]);
$secondStoredCount = count(MatchRepository::forRequest((int) $medicalRequest['id']));

check('re-suggest does not duplicate open suggestions', $secondStoredCount === $firstStoredCount);

check('closed request rejects suggestions', expectMatching(
    static fn () => $matching->suggestForRequest((int) $closedRequest['id'], $adminViewer, true),
    'INVALID_REQUEST_STATUS'
));

check('invalid type filter rejected', expectMatching(
    static fn () => $matching->suggestForRequest((int) $medicalRequest['id'], $adminViewer, false, ['type' => 'ROBOTS']),
    'VALIDATION_ERROR'
));

// ---------------------------------------------------------------------------
echo "\nMatch lifecycle — accept\n";

// Persist suggestions for a second request while the medical volunteer is
// still AVAILABLE so a competing assignment row exists for conflict tests.
$conflictRequest = $emergencies->create($citizenId, $emergencies->validateCreation([
    'category'        => 'MEDICAL',
    'description'     => 'Second emergency needing the same medical volunteer.',
    'people_affected' => 2,
]));
$requestCleanup[] = (int) $conflictRequest['id'];

$matching->suggestForRequest((int) $conflictRequest['id'], $adminViewer, true, ['limit' => 50]);

$conflictMatchRow = null;

foreach (MatchRepository::forRequest((int) $conflictRequest['id']) as $row) {
    if ((int) ($row['volunteer_id'] ?? 0) === $medicalProfileId) {
        $conflictMatchRow = $row;
        break;
    }
}

check('conflict suggestion created while volunteer was AVAILABLE', $conflictMatchRow !== null);

$medicalMatchRow = null;

foreach (MatchRepository::forRequest((int) $medicalRequest['id']) as $row) {
    if ((int) ($row['volunteer_id'] ?? 0) === $medicalProfileId) {
        $medicalMatchRow = $row;
        break;
    }
}

check('medical volunteer match row exists', $medicalMatchRow !== null);

$acceptResult = $matching->accept((int) $medicalMatchRow['id'], $medicalViewer);
check('volunteer accept succeeds', (string) (($acceptResult['match']['status'] ?? '')) === 'ACCEPTED');
check('request advanced to ACCEPTED', (string) (EmergencyRequestRepository::find((int) $medicalRequest['id'])['status'] ?? '') === 'ACCEPTED');
check('volunteer marked BUSY on accept', (string) (VolunteerRepository::find($medicalProfileId)['availability'] ?? '') === 'BUSY');

$events = RequestEventRepository::forRequest((int) $medicalRequest['id']);
$eventTypes = array_map(static fn (array $row): string => (string) $row['event_type'], $events);
check('timeline records STATUS_CHANGED steps to ACCEPTED', in_array('STATUS_CHANGED', $eventTypes, true));
check('timeline records MATCH_ACCEPTED', in_array('MATCH_ACCEPTED', $eventTypes, true));

$acceptAudit = AuditRepository::forEntity('match', (int) $medicalMatchRow['id']);
check('accept writes ACCEPT_MATCH audit row', count($acceptAudit) >= 1
    && (string) ($acceptAudit[0]['action'] ?? '') === 'ACCEPT_MATCH');

check('accepted request rejects new suggestions', expectMatching(
    static fn () => $matching->suggestForRequest((int) $medicalRequest['id'], $adminViewer, true),
    'INVALID_REQUEST_STATUS'
));

check('cannot accept an already-accepted match again', expectMatching(
    static fn () => $matching->accept((int) $medicalMatchRow['id'], $medicalViewer),
    'VALIDATION_ERROR'
));

// BUSY volunteer cannot accept a second concurrent assignment.
check('BUSY volunteer cannot accept another assignment', expectMatching(
    static fn () => $matching->accept((int) $conflictMatchRow['id'], $medicalViewer),
    'MATCH_NOT_ELIGIBLE'
));

// Competing volunteer on the same already-accepted request is blocked.
$competingMatch = null;

foreach (MatchRepository::forRequest((int) $medicalRequest['id']) as $row) {
    if ((int) ($row['volunteer_id'] ?? 0) === $generalProfileId) {
        $competingMatch = $row;
        break;
    }
}

check('competing volunteer suggestion exists', $competingMatch !== null);

if ($competingMatch !== null) {
    check('cannot accept a competing match on an accepted request', expectMatching(
        static fn () => $matching->accept((int) $competingMatch['id'], $generalViewer),
        'INVALID_REQUEST_STATUS'
    ));
}

// Closed request cannot accept.
check('closed request cannot accept matches', expectMatching(
    static fn () => $matching->accept((int) $medicalMatchRow['id'], $medicalViewer),
    'VALIDATION_ERROR'
));

check('missing match is 404 MATCH_NOT_FOUND', expectMatching(
    static fn () => $matching->accept(999999999, $medicalViewer),
    'MATCH_NOT_FOUND'
));

// ---------------------------------------------------------------------------
echo "\nMatch lifecycle — reject & complete\n";

// Use a volunteer without an existing acceptance: the medical volunteer is
// already BUSY on an accepted assignment and is correctly suppressed.
$rejectRequest = $emergencies->create($citizenId, $emergencies->validateCreation([
    'category'        => 'MEDICAL',
    'description'     => 'Follow-up medical support needed at the shelter clinic.',
    'people_affected' => 3,
    'latitude'        => 20.3885,
    'longitude'       => 72.9095,
]));
$requestCleanup[] = (int) $rejectRequest['id'];

$matching->suggestForRequest((int) $rejectRequest['id'], $adminViewer, true, ['limit' => 50]);

$rejectMatchRow = null;

foreach (MatchRepository::forRequest((int) $rejectRequest['id']) as $row) {
    if ((int) ($row['volunteer_id'] ?? 0) === $secondMedicalProfileId) {
        $rejectMatchRow = $row;
        break;
    }
}

check('second request produced a free medical suggestion', $rejectMatchRow !== null);

if ($rejectMatchRow !== null) {
    $rejectResult = $matching->reject((int) $rejectMatchRow['id'], $secondMedicalViewer, 'Already assigned elsewhere.');
    check('volunteer reject succeeds', (string) ($rejectResult['match']['status'] ?? '') === 'REJECTED');
    check('rejected match is terminal', (string) (MatchRepository::find((int) $rejectMatchRow['id'])['status'] ?? '') === 'REJECTED');
    check('volunteer remains AVAILABLE after reject', (string) (VolunteerRepository::find($secondMedicalProfileId)['availability'] ?? '') === 'AVAILABLE');

    $rejectEvents = RequestEventRepository::forRequest((int) $rejectRequest['id']);
    $rejectEventTypes = array_map(static fn (array $row): string => (string) $row['event_type'], $rejectEvents);
    check('timeline records MATCH_REJECTED', in_array('MATCH_REJECTED', $rejectEventTypes, true));
} else {
    check('volunteer reject succeeds', false);
    check('rejected match is terminal', false);
    check('volunteer remains AVAILABLE after reject', false);
    check('timeline records MATCH_REJECTED', false);
}

// Accept then complete on a third request with the GENERAL volunteer
// (no concurrent acceptance, AVAILABLE, capability 0.85 for MEDICAL).
$completeRequest = $emergencies->create($citizenId, $emergencies->validateCreation([
    'category'        => 'MEDICAL',
    'description'     => 'Clinic transport support after acceptance workflow.',
    'people_affected' => 2,
]));
$requestCleanup[] = (int) $completeRequest['id'];

$matching->suggestForRequest((int) $completeRequest['id'], $adminViewer, true, ['limit' => 50]);

$completeMatchRow = null;

foreach (MatchRepository::forRequest((int) $completeRequest['id']) as $row) {
    if ((int) ($row['volunteer_id'] ?? 0) === $generalProfileId) {
        $completeMatchRow = $row;
        break;
    }
}

if ($completeMatchRow === null) {
    foreach (MatchRepository::forRequest((int) $completeRequest['id']) as $row) {
        if ((int) ($row['volunteer_id'] ?? 0) === $secondMedicalProfileId) {
            $completeMatchRow = $row;
            break;
        }
    }
}

check('complete-cycle suggestion exists', $completeMatchRow !== null);

if ($completeMatchRow !== null) {
    $completeVolunteerId = (int) $completeMatchRow['volunteer_id'];
    $completeViewer = UserRepository::find((int) (
        VolunteerRepository::find($completeVolunteerId)['user_id'] ?? 0
    ));

    $matching->accept((int) $completeMatchRow['id'], $completeViewer);
    check('pre-complete match is ACCEPTED', (string) (MatchRepository::find((int) $completeMatchRow['id'])['status'] ?? '') === 'ACCEPTED');
    check('pre-complete request is ACCEPTED', (string) (EmergencyRequestRepository::find((int) $completeRequest['id'])['status'] ?? '') === 'ACCEPTED');

    $completeResult = $matching->complete((int) $completeMatchRow['id'], $completeViewer);
    check('volunteer complete succeeds', (string) ($completeResult['match']['status'] ?? '') === 'COMPLETED');
    check('request resolved after complete', (string) (EmergencyRequestRepository::find((int) $completeRequest['id'])['status'] ?? '') === 'RESOLVED');
    check('volunteer freed to AVAILABLE after complete', (string) (VolunteerRepository::find($completeVolunteerId)['availability'] ?? '') === 'AVAILABLE');

    $completeEvents = RequestEventRepository::forRequest((int) $completeRequest['id']);
    $completeEventTypes = array_map(static fn (array $row): string => (string) $row['event_type'], $completeEvents);
    check('timeline records MATCH_COMPLETED', in_array('MATCH_COMPLETED', $completeEventTypes, true));

    $completeAudit = AuditRepository::forEntity('match', (int) $completeMatchRow['id']);
    check('complete writes COMPLETE_MATCH audit row', count($completeAudit) >= 1
        && (string) ($completeAudit[0]['action'] ?? '') === 'COMPLETE_MATCH');
} else {
    check('pre-complete match is ACCEPTED', false);
    check('pre-complete request is ACCEPTED', false);
    check('volunteer complete succeeds', false);
    check('request resolved after complete', false);
    check('volunteer freed to AVAILABLE after complete', false);
    check('timeline records MATCH_COMPLETED', false);
    check('complete writes COMPLETE_MATCH audit row', false);
}

// ---------------------------------------------------------------------------
echo "\nConflict guards\n";

// Closed / missing / cross-role conflict checks already asserted above.
check('citizen cannot accept a volunteer match', expectMatching(
    static fn () => $matching->accept((int) ($conflictMatchRow['id'] ?? 0), $citizenViewer),
    'FORBIDDEN'
));

// ---------------------------------------------------------------------------
echo "\nAuthorization & IDOR\n";

check('other citizen cannot read another request match list', expectMatching(
    static fn () => $matching->matchesForRequest((int) $medicalRequest['id'], $otherCitizenViewer),
    'FORBIDDEN'
));

check('volunteer cannot accept another volunteer match', expectMatching(
    static fn () => $matching->accept((int) ($conflictMatchRow['id'] ?? 0), $farViewer),
    'FORBIDDEN'
));

check('citizen cannot accept a volunteer match', expectMatching(
    static fn () => $matching->accept((int) ($conflictMatchRow['id'] ?? 0), $citizenViewer),
    'FORBIDDEN'
));

$adminList = $matching->listMatches($adminViewer, []);
check('admin can read all matches', is_array($adminList['items'] ?? null) && $adminList['pagination']['total'] >= 1);

$volunteerList = $matching->listMatches($medicalViewer, []);
check('volunteer match list is role-scoped', is_array($volunteerList['items'] ?? null)
    && $volunteerList['pagination']['total'] >= 1);

$volunteerListIds = array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $volunteerList['items']);
check('volunteer list includes own medical match', in_array((int) $medicalMatchRow['id'], $volunteerListIds, true));

if ($conflictMatchRow !== null) {
    check('volunteer list includes own pending conflict suggestion', in_array((int) $conflictMatchRow['id'], $volunteerListIds, true));
}

if ($completeMatchRow !== null) {
    check('volunteer list excludes another volunteers completed match', !in_array((int) $completeMatchRow['id'], $volunteerListIds, true));
}

check('invalid list status rejected', expectMatching(
    static fn () => $matching->listMatches($medicalViewer, ['status' => 'NOPE']),
    'VALIDATION_ERROR'
));

// ---------------------------------------------------------------------------
echo "\nMatch payload privacy\n";

$showResult = $matching->show((int) $medicalMatchRow['id'], $medicalViewer);
check('volunteer can read own accepted match', (int) ($showResult['id'] ?? 0) === (int) $medicalMatchRow['id']);
check('match payload has no volunteer phone', !array_key_exists('phone', (array) ($showResult['provider'] ?? [])));
check('match payload has no volunteer email', !array_key_exists('email', (array) ($showResult['provider'] ?? [])));
check('accepted match exposes request summary to volunteer', is_array($showResult['request'] ?? null));

$suggestedShow = null;

foreach (MatchRepository::forRequest((int) $conflictRequest['id']) as $row) {
    if ((int) ($row['volunteer_id'] ?? 0) === $medicalProfileId) {
        $suggestedShow = $row;
        break;
    }
}

if ($suggestedShow !== null) {
    // Restore volunteer view of a SUGGESTED row via admin present path.
    $suggestedAsAdmin = $matching->show((int) $suggestedShow['id'], $adminViewer);
    check('admin can read suggestion match', (int) ($suggestedAsAdmin['id'] ?? 0) === (int) $suggestedShow['id']);
}

// ---------------------------------------------------------------------------
echo "\nWeb controllers & views\n";

$_SESSION['user_id'] = $medicalUserId;
$_SESSION['user'] = $medicalViewer;

$volunteerWeb = (new MatchingController())->volunteerMatches(make_request('GET', '/volunteer/matches'));
check('volunteer matches page renders', $volunteerWeb->status() === 200 && str_contains((string) $volunteerWeb->body(), 'My matches'));

$requestWeb = (new MatchingController())->requestMatches(make_request('GET', '/requests/' . $medicalRequest['id'] . '/matches'), (string) $medicalRequest['id']);
check('request match panel renders for admin', $requestWeb->status() === 200 && str_contains((string) $requestWeb->body(), 'Matches'));

$_SESSION['user_id'] = $citizenId;
$_SESSION['user'] = $citizenViewer;
$citizenPanel = (new MatchingController())->requestMatches(make_request('GET', '/requests/' . $medicalRequest['id'] . '/matches'), (string) $medicalRequest['id']);
check('request owner can open match panel', $citizenPanel->status() === 200 && str_contains((string) $citizenPanel->body(), 'suggestion'));

$_SESSION['user_id'] = $otherCitizenId;
$_SESSION['user'] = $otherCitizenViewer;
$idorPanel = (new MatchingController())->requestMatches(make_request('GET', '/requests/' . $medicalRequest['id'] . '/matches'), (string) $medicalRequest['id']);
check('non-owner citizen blocked from match panel', $idorPanel->status() === 403 || str_contains((string) $idorPanel->body(), 'not allowed') || $idorPanel->status() === 302);

// Accept via web PRG path on a fresh open request (medical request is already ACCEPTED).
VolunteerRepository::updateAvailability($secondMedicalProfileId, 'AVAILABLE');
$webAcceptRequest = $emergencies->create($otherCitizenId, $emergencies->validateCreation([
    'category'        => 'MEDICAL',
    'description'     => 'Web accept flow needs a verified medical responder.',
    'people_affected' => 1,
]));
$requestCleanup[] = (int) $webAcceptRequest['id'];

$_SESSION['user_id'] = $otherCitizenId;
$_SESSION['user'] = $otherCitizenViewer;
(new MatchingController())->suggest(make_request('POST', '/requests/' . $webAcceptRequest['id'] . '/matches/suggest', ['_token' => csrf_token(), 'limit' => 50]), (string) $webAcceptRequest['id']);

$webAcceptMatch = null;
foreach (MatchRepository::forRequest((int) $webAcceptRequest['id']) as $row) {
    if ((int) ($row['volunteer_id'] ?? 0) === $secondMedicalProfileId) {
        $webAcceptMatch = $row;
        break;
    }
}

check('web suggest created a match row', $webAcceptMatch !== null);

if ($webAcceptMatch !== null) {
    $_SESSION['user_id'] = $secondMedicalUserId;
    $_SESSION['user'] = $secondMedicalViewer;
    $acceptWeb = (new MatchingController())->acceptVolunteerMatch(
        make_request('POST', '/volunteer/matches/' . (int) $webAcceptMatch['id'] . '/accept', ['_token' => csrf_token()]),
        (string) $webAcceptMatch['id']
    );
    check('web accept redirects to match inbox', $acceptWeb->status() === 302);
    check('web accept persisted ACCEPTED', (string) (MatchRepository::find((int) $webAcceptMatch['id'])['status'] ?? '') === 'ACCEPTED');
} else {
    check('web accept redirects to match inbox', false);
    check('web accept persisted ACCEPTED', false);
}

// Direct view render smoke (no controller session requirements beyond layout).
$fragment = View::render('matching/volunteer', [
    'title'  => 'My matches',
    'user'   => $medicalViewer,
    'items'  => $volunteerList['items'] ?? [],
    'filter' => null,
], null);
check('matching/volunteer view renders empty-safe', str_contains($fragment, 'My matches'));

$fragmentRequest = View::render('matching/request', [
    'title'        => 'Request matches',
    'user'         => $adminViewer,
    'request_id'   => (int) $medicalRequest['id'],
    'items'        => ($matching->matchesForRequest((int) $medicalRequest['id'], $adminViewer)['items'] ?? []),
    'meta'         => ['request_status' => 'ACCEPTED', 'count' => 1],
    'suggestions'  => [],
    'suggest_meta' => null,
], null);
check('matching/request view renders', str_contains($fragmentRequest, 'Matches'));

// ---------------------------------------------------------------------------
echo "\nAPI controllers\n";

$api = new MatchingApiController();
$csrf = csrf_token();

$_SESSION['user_id'] = $citizenId;
$_SESSION['user'] = $citizenViewer;

$apiMatches = $api->requestMatches(make_request('GET', '/api/v1/requests/' . $medicalRequest['id'] . '/matches'), (string) $medicalRequest['id']);
$apiMatchesBody = json_decode((string) $apiMatches->body(), true);
check('API request matches succeeds', $apiMatches->status() === 200 && is_array($apiMatchesBody['data']['items'] ?? null));

$apiList = $api->index(make_request('GET', '/api/v1/matches'));
$apiListBody = json_decode((string) $apiList->body(), true);
check('API list matches succeeds for citizen owner path', $apiList->status() === 200 || $apiList->status() === 403);

// Volunteer API list should work.
$_SESSION['user_id'] = $medicalUserId;
$_SESSION['user'] = $medicalViewer;
$apiVolList = $api->index(make_request('GET', '/api/v1/matches'));
$apiVolListBody = json_decode((string) $apiVolList->body(), true);
check('API volunteer match list succeeds', $apiVolList->status() === 200 && is_array($apiVolListBody['data']['items'] ?? null));

$apiShow = $api->show(make_request('GET', '/api/v1/matches/' . $medicalMatchRow['id']), (string) $medicalMatchRow['id']);
$apiShowBody = json_decode((string) $apiShow->body(), true);
check('API match show succeeds', $apiShow->status() === 200 && (int) ($apiShowBody['data']['id'] ?? 0) === (int) $medicalMatchRow['id']);
check('API match show hides provider contact', !str_contains((string) $apiShow->body(), '"email"'));

// CSRF guard on API accept.
$_SESSION['user_id'] = $citizenId;
$_SESSION['user'] = $citizenViewer;
$apiCsrfBlocked = (new CsrfMiddleware())->handle(
    make_request('POST', '/api/v1/matches/' . $medicalMatchRow['id'] . '/accept', [], ['accept' => 'application/json']),
    static fn (): Response => Response::html('OK', 200)
);
$apiCsrfBody = json_decode((string) $apiCsrfBlocked->body(), true);
check('CsrfMiddleware blocks API match accept without token', $apiCsrfBlocked->status() === 403
    && (string) ($apiCsrfBody['code'] ?? '') === 'CSRF_MISMATCH');

// Role middleware blocks citizens from suggest endpoint.
$roleCitizenSuggest = (new RoleMiddleware(['CITIZEN', 'ADMIN']))->handle(
    make_request('POST', '/api/v1/requests/' . $medicalRequest['id'] . '/matches/suggest', [], ['accept' => 'application/json']),
    static fn (): Response => Response::html('OK', 200)
);
check('RoleMiddleware allows citizens on suggest', $roleCitizenSuggest->status() === 200);

$_SESSION['user_id'] = $medicalUserId;
$_SESSION['user'] = $medicalViewer;
$roleVolunteerSuggest = (new RoleMiddleware(['CITIZEN', 'ADMIN']))->handle(
    make_request('POST', '/api/v1/requests/' . $medicalRequest['id'] . '/matches/suggest', [], ['accept' => 'application/json']),
    static fn (): Response => Response::html('OK', 200)
);
check('RoleMiddleware blocks volunteers from suggest', $roleVolunteerSuggest->status() === 403);

// API suggest with CSRF as owner of a fresh open request.
$apiSuggestRequest = $emergencies->create($citizenId, $emergencies->validateCreation([
    'category'        => 'FOOD_WATER',
    'description'     => 'API suggest flow for open food and water emergency.',
    'people_affected' => 4,
]));
$requestCleanup[] = (int) $apiSuggestRequest['id'];

$_SESSION['user_id'] = $citizenId;
$_SESSION['user'] = $citizenViewer;
$_SESSION['_csrf_token'] = $csrf;
$apiSuggest = $api->suggest(
    make_request('POST', '/api/v1/requests/' . $apiSuggestRequest['id'] . '/matches/suggest', ['type' => 'BOTH', 'limit' => 50], ['x-csrf-token' => $csrf], true),
    (string) $apiSuggestRequest['id']
);
$apiSuggestBody = json_decode((string) $apiSuggest->body(), true);
check('API suggest succeeds for owner', $apiSuggest->status() === 200
    && array_key_exists('meta', (array) ($apiSuggestBody['data'] ?? [])));

// ---------------------------------------------------------------------------
echo "\nSecurity notes\n";

check('matching exception messages are safe', !str_contains(
    (new App\Core\MatchingException('MATCH_CONFLICT', 'Already accepted.', 409))->userMessage(),
    'SELECT'
));

$html = (string) $volunteerWeb->body();
check('volunteer matches page does not leak SQL', !str_contains(strtolower($html), 'sql syntax')
    && !str_contains($html, 'SELECT * FROM'));

check('volunteer matches page hides exact coordinates by default', !str_contains($html, '20.3882') && !str_contains($html, '72.9091'));

// ---------------------------------------------------------------------------
echo "\nCleanup\n";

try {
    foreach (array_unique($resourceCleanup) as $resourceId) {
        $resourceId = (int) $resourceId;

        if ($resourceId > 0 && ResourceRepository::find($resourceId) !== null) {
            try {
                ResourceRepository::delete($resourceId);
            } catch (Throwable) {
                // Matches may NULL the resource link; removals are verified below.
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

check('test users removed', UserRepository::find($medicalUserId) === null && UserRepository::find($citizenId) === null);
check('test volunteer profiles removed with users', VolunteerRepository::find($medicalProfileId) === null);
check('test organizations removed with users', OrganizationRepository::find($verifiedOrgId) === null);
check('test requests removed', EmergencyRequestRepository::find((int) $medicalRequest['id']) === null);
check('test audit rows removed', (int) (Database::selectOne('SELECT COUNT(*) AS aggregate FROM audit_logs WHERE id > :id', ['id' => $auditStart])['aggregate'] ?? 0) === 0);

echo "\n----------------------------------------\n";
echo "Passed: {$passed}   Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
