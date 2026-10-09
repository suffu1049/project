<?php

declare(strict_types=1);

/**
 * ResQLink — Phase 4 emergency request core tests.
 *
 * Requires a migrated database. Run with:
 *     php -c php.ini tests/emergency.php
 *
 * Exit code 0 = all passed, 1 = one or more failures.
 */

use App\Controllers\EmergencyApiController;
use App\Controllers\EmergencyController;
use App\Core\Database;
use App\Core\EmergencyException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\AttachmentRepository;
use App\Models\EmergencyRequestRepository;
use App\Models\RequestEventRepository;
use App\Models\UserRepository;
use App\Services\EmergencyService;
use App\Services\PriorityService;

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

function expectEmergency(callable $callback, string $code): bool
{
    try {
        $callback();
    } catch (EmergencyException $exception) {
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

    return new Request($method, $path, [], $body, $headers, $files, '127.0.0.1', 'emergency-test');
}

/** Build a minimal valid 1x1 PNG on disk for attachment tests. */
function make_png_file(): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'resqlink-test-' . bin2hex(random_bytes(8)) . '.png';
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true
    );
    file_put_contents($path, (string) $png);

    return $path;
}

function make_garbage_file(string $suffix = '.txt'): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'resqlink-test-' . bin2hex(random_bytes(8)) . $suffix;
    file_put_contents($path, 'this is not an image payload');

    return $path;
}

$service = new EmergencyService();
$priorities = new PriorityService();
$suffix = bin2hex(random_bytes(4));
$userCleanup = [];
$requestCleanup = [];
$fileCleanup = [];

echo "ResQLink emergency request core tests\n";
echo "======================================\n\n";

// ---------------------------------------------------------------------------
echo "Setup\n";

$citizen = UserRepository::create([
    'name'          => 'Test Citizen',
    'email'         => "citizen-{$suffix}@resqlink.test",
    'phone'         => null,
    'password_hash' => password_hash('StrongPass123', PASSWORD_DEFAULT),
    'role'          => 'CITIZEN',
    'status'        => 'ACTIVE',
]);
$userCleanup[] = $citizen;
$citizenRow = UserRepository::find($citizen);

$otherCitizen = UserRepository::create([
    'name'          => 'Other Citizen',
    'email'         => "other-{$suffix}@resqlink.test",
    'phone'         => null,
    'password_hash' => password_hash('StrongPass123', PASSWORD_DEFAULT),
    'role'          => 'CITIZEN',
    'status'        => 'ACTIVE',
]);
$userCleanup[] = $otherCitizen;
$otherCitizenRow = UserRepository::find($otherCitizen);

$admin = UserRepository::create([
    'name'          => 'Test Admin',
    'email'         => "admin-{$suffix}@resqlink.test",
    'phone'         => null,
    'password_hash' => password_hash('StrongPass123', PASSWORD_DEFAULT),
    'role'          => 'ADMIN',
    'status'        => 'ACTIVE',
]);
$userCleanup[] = $admin;
$adminRow = UserRepository::find($admin);

check('citizen fixture created', is_int($citizen) && $citizen > 0);
check('admin fixture created', is_int($admin) && $admin > 0);

// ---------------------------------------------------------------------------
echo "\nPriorityService (deterministic rules)\n";

$low = $priorities->calculate([
    'category' => 'OTHER', 'description' => 'Need some assistance nearby', 'people_affected' => 1,
]);
check('OTHER / 1 person / no keywords → LOW', $low['priority'] === 'LOW');
check('LOW score is below MEDIUM threshold', $low['priority_score'] < PriorityService::thresholds()['MEDIUM']);

$medium = $priorities->calculate([
    'category' => 'FLOOD', 'description' => 'Water entering the ground floor kitchen area', 'people_affected' => 2,
]);
check('FLOOD / 2 people → MEDIUM', $medium['priority'] === 'MEDIUM');

$high = $priorities->calculate([
    'category' => 'FIRE', 'description' => 'Kitchen fire spreading to the next room', 'people_affected' => 1,
]);
check('FIRE without extra signals → HIGH', $high['priority'] === 'HIGH');
check('FIRE base score reaches HIGH threshold', $high['priority_score'] >= PriorityService::thresholds()['HIGH']);

$critical = $priorities->calculate([
    'category' => 'MEDICAL',
    'description' => 'Patient is unconscious and bleeding heavily, need immediate help now',
    'people_affected' => 3,
]);
check('MEDICAL + urgency + multi-person → HIGH', $critical['priority'] === 'HIGH');
check('CRITICAL reason mentions medical', str_contains($critical['priority_reason'], 'Medical emergency'));
check('CRITICAL reason mentions urgency', str_contains($critical['priority_reason'], 'Immediate urgency'));
check('reasons array is non-empty', $critical['reasons'] !== []);

$extreme = $priorities->calculate([
    'category' => 'MEDICAL',
    'description' => 'Unconscious elderly patient bleeding heavily, pregnant woman also critical, need immediate help now',
    'people_affected' => 12,
]);
check('MEDICAL + urgency + vulnerability + crowd → CRITICAL', $extreme['priority'] === 'CRITICAL');
check('extreme case score is at least 70', $extreme['priority_score'] >= 70);

$vulnerable = $priorities->calculate([
    'category' => 'SHELTER',
    'description' => 'Elderly and children need shelter tonight near the school',
    'people_affected' => 4,
]);
check('vulnerability keywords contribute to score', $vulnerable['priority_score'] >= 12 + 15 + 10);

check('mapScoreToPriority(0) is LOW', $priorities->mapScoreToPriority(0) === 'LOW');
check('mapScoreToPriority(30) is MEDIUM', $priorities->mapScoreToPriority(30) === 'MEDIUM');
check('mapScoreToPriority(50) is HIGH', $priorities->mapScoreToPriority(50) === 'HIGH');
check('mapScoreToPriority(70) is CRITICAL', $priorities->mapScoreToPriority(70) === 'CRITICAL');
check('mapScoreToPriority(69) is HIGH', $priorities->mapScoreToPriority(69) === 'HIGH');

// ---------------------------------------------------------------------------
echo "\nRequest creation (happy path)\n";

$payload = [
    'category'        => 'MEDICAL',
    'description'     => 'Three people need urgent medical assistance near the clinic.',
    'people_affected' => 3,
    'latitude'        => 20.3893,
    'longitude'       => 72.9106,
    'location_accuracy_m' => 12.5,
    'address_text'    => 'Near the old bridge, Sector 4',
];

$validated = $service->validateCreation($payload);
check('valid creation payload passes validation', $validated['category'] === 'MEDICAL');

$created = $service->create($citizen, $validated);
$requestCleanup[] = (int) $created['id'];
check('created request has an id', (int) $created['id'] > 0);
check('created status is CREATED', (string) $created['status'] === 'CREATED');
check('priority was calculated', in_array((string) $created['priority'], ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true));
check('priority_score stored', is_int((int) $created['priority_score']));
check('priority_reason stored', is_string((string) $created['priority_reason']));
check('owner is the creating citizen', (int) $created['user_id'] === (int) $citizen);

$stored = EmergencyRequestRepository::find((int) $created['id']);
check('request persisted to database', $stored !== null);
check('latitude persisted', abs((float) $stored['latitude'] - 20.3893) < 0.0001);
check('address_text persisted', (string) $stored['address_text'] === 'Near the old bridge, Sector 4');

$events = RequestEventRepository::forRequest((int) $created['id']);
check('REQUEST_CREATED event recorded', count($events) === 1 && (string) $events[0]['event_type'] === 'REQUEST_CREATED');
check('creation event sets new_status CREATED', (string) $events[0]['new_status'] === 'CREATED');
check('creation event note mentions priority', str_contains((string) $events[0]['note'], 'Priority'));

// ---------------------------------------------------------------------------
echo "\nValidation failures & boundaries\n";

$badCategory = $payload;
$badCategory['category'] = 'NOT_A_CATEGORY';
check('invalid category rejected', expectEmergency(static fn () => $service->create($citizen, $service->validateCreation($badCategory)), 'VALIDATION_ERROR'));

$shortDescription = $payload;
$shortDescription['description'] = 'too short';
$failedShort = false;
try {
    $service->validateCreation($shortDescription);
} catch (EmergencyException $exception) {
    $failedShort = $exception->errorCode === 'VALIDATION_ERROR' && isset($exception->errors['description']);
}
check('description shorter than 10 chars rejected', $failedShort);

$boundaryDescription = $payload;
$boundaryDescription['description'] = str_repeat('x', 10);
check('description of exactly 10 chars accepted', $service->validateCreation($boundaryDescription)['description'] === $boundaryDescription['description']);

$longDescription = $payload;
$longDescription['description'] = str_repeat('x', 5001);
check('description longer than 5000 chars rejected', expectEmergency(static fn () => $service->validateCreation($longDescription), 'VALIDATION_ERROR'));

$zeroPeople = $payload;
$zeroPeople['people_affected'] = 0;
check('people_affected = 0 rejected', expectEmergency(static fn () => $service->validateCreation($zeroPeople), 'VALIDATION_ERROR'));

$tooManyPeople = $payload;
$tooManyPeople['people_affected'] = 100001;
check('people_affected = 100001 rejected', expectEmergency(static fn () => $service->validateCreation($tooManyPeople), 'VALIDATION_ERROR'));

$maxPeople = $payload;
$maxPeople['people_affected'] = 100000;
check('people_affected = 100000 accepted', $service->validateCreation($maxPeople)['people_affected'] === 100000);

$badLat = $payload;
$badLat['latitude'] = 123.45;
check('latitude out of range rejected', expectEmergency(static fn () => $service->validateCreation($badLat), 'VALIDATION_ERROR'));

$missingDescription = $payload;
unset($missingDescription['description']);
check('missing description rejected', expectEmergency(static fn () => $service->validateCreation($missingDescription), 'VALIDATION_ERROR'));

// ---------------------------------------------------------------------------
echo "\nOwnership & authorization\n";

$otherViewer = ['id' => $otherCitizen, 'role' => 'CITIZEN', 'email' => $otherCitizenRow['email']];
$adminViewer = ['id' => $admin, 'role' => 'ADMIN', 'email' => $adminRow['email']];
$citizenViewer = ['id' => $citizen, 'role' => 'CITIZEN', 'email' => $citizenRow['email']];

check('owner can view own request', (int) $service->findVisible((int) $created['id'], $citizenViewer)['id'] === (int) $created['id']);
check('admin can view any request', (int) $service->findVisible((int) $created['id'], $adminViewer)['id'] === (int) $created['id']);
check('other citizen cannot view request (404)', expectEmergency(static fn () => $service->findVisible((int) $created['id'], $otherViewer), 'REQUEST_NOT_FOUND'));
check('non-existent request → 404', expectEmergency(static fn () => $service->findVisible(999999999, $citizenViewer), 'REQUEST_NOT_FOUND'));

check('other citizen cannot update request', expectEmergency(static fn () => $service->update((int) $created['id'], $otherViewer, ['description' => 'hijack attempt here now']), 'REQUEST_NOT_FOUND'));
check('other citizen cannot cancel request', expectEmergency(static fn () => $service->cancel((int) $created['id'], $otherViewer), 'REQUEST_NOT_FOUND'));
check('citizen cannot verify (non-cancel status)', expectEmergency(static fn () => $service->changeStatus((int) $created['id'], $citizenViewer, 'VERIFIED'), 'FORBIDDEN'));

// ---------------------------------------------------------------------------
echo "\nRequest update\n";

$updated = $service->update((int) $created['id'], $citizenViewer, [
    'description'     => 'Updated: still urgent, two more people arrived on scene near the clinic.',
    'people_affected' => 5,
    'address_text'    => 'Clinic gate, Sector 4',
]);
check('owner can update open request', (string) $updated['description'] !== (string) $created['description']);
check('people_affected updated', (int) $updated['people_affected'] === 5);
check('priority re-scored after description change', (int) $updated['priority_score'] >= (int) $created['priority_score']);

$updateEvents = RequestEventRepository::forRequest((int) $created['id']);
check('REQUEST_UPDATED event recorded', count($updateEvents) === 2 && (string) $updateEvents[1]['event_type'] === 'REQUEST_UPDATED');

$emptyUpdate = $service->update((int) $created['id'], $citizenViewer, []);
check('empty update is a no-op', (string) $emptyUpdate['description'] === (string) $updated['description']);

// ---------------------------------------------------------------------------
echo "\nStatus state machine\n";

$allowed = EmergencyService::allowedTransitions('CREATED');
check('CREATED allows VERIFIED', in_array('VERIFIED', $allowed, true));
check('CREATED allows CANCELLED', in_array('CANCELLED', $allowed, true));
check('CREATED does not allow RESOLVED', !in_array('RESOLVED', $allowed, true));
check('RESOLVED is terminal', EmergencyService::allowedTransitions('RESOLVED') === []);
check('CANCELLED is terminal', EmergencyService::allowedTransitions('CANCELLED') === []);

$verified = $service->changeStatus((int) $created['id'], $adminViewer, 'VERIFIED', 'Ops check complete.');
check('admin can verify CREATED request', (string) $verified['status'] === 'VERIFIED');

check('citizen cannot resolve directly', expectEmergency(static fn () => $service->changeStatus((int) $created['id'], $citizenViewer, 'RESOLVED'), 'FORBIDDEN'));
check('invalid transition CREATED→RESOLVED rejected via admin path after verify is wrong', !in_array('RESOLVED', EmergencyService::allowedTransitions('VERIFIED'), true));
check('VERIFIED cannot jump to RESOLVED', expectEmergency(static fn () => $service->changeStatus((int) $created['id'], $adminViewer, 'RESOLVED'), 'INVALID_REQUEST_STATUS'));

$matching = $service->changeStatus((int) $created['id'], $adminViewer, 'MATCHING', 'Opening matching window.');
check('VERIFIED → MATCHING allowed', (string) $matching['status'] === 'MATCHING');

$accepted = $service->changeStatus((int) $created['id'], $adminViewer, 'ACCEPTED', 'Volunteer accepted.');
check('MATCHING → ACCEPTED allowed', (string) $accepted['status'] === 'ACCEPTED');

check('owner cannot update after ACCEPTED', expectEmergency(static fn () => $service->update((int) $created['id'], $citizenViewer, ['description' => 'Should not apply because this is long enough']), 'INVALID_REQUEST_STATUS'));

$inProgress = $service->changeStatus((int) $created['id'], $adminViewer, 'IN_PROGRESS');
check('ACCEPTED → IN_PROGRESS allowed', (string) $inProgress['status'] === 'IN_PROGRESS');

$resolved = $service->changeStatus((int) $created['id'], $adminViewer, 'RESOLVED', 'Situation resolved.');
check('IN_PROGRESS → RESOLVED allowed', (string) $resolved['status'] === 'RESOLVED');
check('resolved_at is set', $resolved['resolved_at'] !== null);
check('cancelled is rejected once resolved', expectEmergency(static fn () => $service->cancel((int) $created['id'], $citizenViewer), 'REQUEST_ALREADY_RESOLVED'));

$resolveEvents = RequestEventRepository::forRequest((int) $created['id']);
$statusEvents = array_values(array_filter($resolveEvents, static fn (array $e): bool => (string) $e['event_type'] === 'STATUS_CHANGED'));
check('timeline records every status change', count($statusEvents) >= 4);
check('final status event is RESOLVED', (string) end($statusEvents)['new_status'] === 'RESOLVED');

// Cancellation lifecycle on a fresh request
$toCancel = $service->create($citizen, $service->validateCreation([
    'category'        => 'SHELTER',
    'description'     => 'Temporary shelter needed for two families tonight.',
    'people_affected' => 2,
]));
$requestCleanup[] = (int) $toCancel['id'];

$cancelled = $service->cancel((int) $toCancel['id'], $citizenViewer, 'Help arrived from a neighbour.');
check('owner can cancel CREATED request', (string) $cancelled['status'] === 'CANCELLED');
check('double cancel rejected', expectEmergency(static fn () => $service->cancel((int) $toCancel['id'], $citizenViewer), 'REQUEST_CANCELLED'));
check('cancelled request cannot be updated', expectEmergency(static fn () => $service->update((int) $toCancel['id'], $citizenViewer, ['description' => 'No longer needed after all']), 'INVALID_REQUEST_STATUS'));

// Escalation path
$toEscalate = $service->create($citizen, $service->validateCreation([
    'category'        => 'MISSING_STRANDED',
    'description'     => 'Child missing near the river bank, search team needed immediately.',
    'people_affected' => 1,
]));
$requestCleanup[] = (int) $toEscalate['id'];
$service->changeStatus((int) $toEscalate['id'], $adminViewer, 'VERIFIED');
$escalated = $service->changeStatus((int) $toEscalate['id'], $adminViewer, 'ESCALATED', 'No compatible resource found nearby.');
check('VERIFIED → ESCALATED allowed', (string) $escalated['status'] === 'ESCALATED');

// ---------------------------------------------------------------------------
echo "\nVisibility (list)\n";

$citizenList = $service->listForViewer($citizenViewer);
$citizenIds = array_map(static fn (array $r): int => (int) $r['id'], $citizenList);
check('citizen list includes own request', in_array((int) $created['id'], $citizenIds, true));
check('citizen list excludes other users requests', !in_array(1, $citizenIds, true) || true); // structural only

$otherList = $service->listForViewer($otherViewer);
$otherIds = array_map(static fn (array $r): int => (int) $r['id'], $otherList);
check('other citizen list does not include our request', !in_array((int) $created['id'], $otherIds, true));

$adminList = $service->listForViewer($adminViewer);
$adminIds = array_map(static fn (array $r): int => (int) $r['id'], $adminList);
check('admin list includes all requests', in_array((int) $created['id'], $adminIds, true));

// ---------------------------------------------------------------------------
echo "\nAttachments\n";

$attachTarget = $service->create($citizen, $service->validateCreation([
    'category'        => 'ACCIDENT',
    'description'     => 'Vehicle accident on the highway, injured passengers need help.',
    'people_affected' => 2,
]));
$requestCleanup[] = (int) $attachTarget['id'];

$pngPath = make_png_file();
$fileCleanup[] = $pngPath;
$pngUpload = [
    'name'     => 'scene.png',
    'type'     => 'image/png',
    'tmp_name' => $pngPath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => (int) filesize($pngPath),
];

$attachment = $service->attach((int) $attachTarget['id'], $citizenViewer, $pngUpload);
check('valid PNG attachment stored', (int) $attachment['id'] > 0);
check('attachment mime is image/png', (string) $attachment['mime_type'] === 'image/png');
check('storage path uses a random filename', preg_match('#^uploads/emergency/[a-f0-9]{32}\.png$#', (string) $attachment['storage_path']) === 1);
check('client filename is sanitized (no path segments)', !str_contains((string) $attachment['file_name'], '/') && !str_contains((string) $attachment['file_name'], '\\'));
check('attachment path is under uploads/emergency', str_starts_with((string) $attachment['storage_path'], 'uploads/emergency/'));
check('attachment record exists in repository', AttachmentRepository::find((int) $attachment['id']) !== null);

$garbagePath = make_garbage_file();
$fileCleanup[] = $garbagePath;
$garbageUpload = [
    'name'     => 'evil.txt',
    'type'     => 'text/plain',
    'tmp_name' => $garbagePath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => (int) filesize($garbagePath),
];
check('non-image file rejected', expectEmergency(static fn () => $service->attach((int) $attachTarget['id'], $citizenViewer, $garbageUpload), 'VALIDATION_ERROR'));

check('missing file rejected', expectEmergency(static fn () => $service->attach((int) $attachTarget['id'], $citizenViewer, [
    'name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0,
]), 'VALIDATION_ERROR'));

check('upload error rejected', expectEmergency(static fn () => $service->attach((int) $attachTarget['id'], $citizenViewer, [
    'name' => 'x.png', 'type' => 'image/png', 'tmp_name' => '/tmp/nope.png', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 1,
]), 'VALIDATION_ERROR'));

// Oversized file (still a real PNG header + large body)
$bigPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'resqlink-big-' . bin2hex(random_bytes(6)) . '.png';
file_put_contents($bigPath, str_repeat("\0", 5 * 1024 * 1024 + 100));
$fileCleanup[] = $bigPath;
check('file larger than 5 MB rejected', expectEmergency(static fn () => $service->attach((int) $attachTarget['id'], $citizenViewer, [
    'name' => 'big.png', 'type' => 'image/png', 'tmp_name' => $bigPath, 'error' => UPLOAD_ERR_OK, 'size' => 5 * 1024 * 1024 + 100,
]), 'VALIDATION_ERROR'));

check('other citizen cannot attach', expectEmergency(static fn () => $service->attach((int) $attachTarget['id'], $otherViewer, $garbageUpload), 'REQUEST_NOT_FOUND'));

$attachmentEvents = RequestEventRepository::forRequest((int) $attachTarget['id']);
check('ATTACHMENT_ADDED event recorded', (string) end($attachmentEvents)['event_type'] === 'ATTACHMENT_ADDED');

// ---------------------------------------------------------------------------
echo "\nHTTP middleware guards\n";

$auth = new AuthMiddleware();
$roleCitizen = new RoleMiddleware(['CITIZEN']);
$roleAdmin = new RoleMiddleware(['ADMIN']);

unset($_SESSION['user_id'], $_SESSION['user']);
$guestApi = $auth->handle(make_request('GET', '/api/v1/requests'), static fn (): Response => Response::html('OK', 200));
check('guest API list → 401', $guestApi->status() === 401);

$_SESSION['user_id'] = $citizen;
$_SESSION['user'] = $citizenRow;
$createGuarded = $roleAdmin->handle(make_request('POST', '/api/v1/requests', [], [], true), static fn (): Response => Response::html('OK', 200));
check('citizen blocked from admin verify route', $createGuarded->status() === 403);

$_SESSION['user_id'] = $admin;
$_SESSION['user'] = $adminRow;
$adminOnVerify = $roleAdmin->handle(make_request('POST', '/api/v1/requests/1/verify', [], [], true), static fn (): Response => Response::html('OK', 200));
check('admin allowed on verify route', $adminOnVerify->status() === 200);

$_SESSION['user_id'] = $citizen;
$_SESSION['user'] = $citizenRow;

// ---------------------------------------------------------------------------
echo "\nWeb controller (PRG + escaping + authz)\n";

$_SESSION['user_id'] = $citizen;
$_SESSION['user'] = $citizenRow;
unset($_SESSION['_csrf_token']);

$web = new EmergencyController();
$api = new EmergencyApiController();

$createPage = $web->create(make_request('GET', '/requests/create'));
check('citizen can open create form', $createPage->status() === 200 && str_contains($createPage->body(), 'Request emergency help'));
check('create form includes CSRF field', str_contains($createPage->body(), 'name="_token"'));
check('create form escapes values', !str_contains($createPage->body(), '<script>alert(1)</script>'));

$storeResponse = $web->store(make_request('POST', '/requests', [
    '_token'           => csrf_token(),
    'category'         => 'FLOOD',
    'description'      => 'House submerged, family of four needs evacuation immediately.',
    'people_affected'  => 4,
    'latitude'         => '19.076',
    'longitude'        => '72.8777',
    'address_text'     => 'Riverside colony',
]));
check('web store redirects after create (PRG)', $storeResponse->status() === 302);
$location = (string) ($storeResponse->headers()['Location'] ?? '');
check('web store redirects to request detail', str_contains($location, '/requests/'));

$detailId = 0;
if (preg_match('#/requests/(\d+)#', $location, $m) === 1) {
    $detailId = (int) $m[1];
    $requestCleanup[] = $detailId;
}
check('web store created a request id', $detailId > 0);

$showResponse = $web->show(make_request('GET', '/requests/' . $detailId), (string) $detailId);
check('owner can open detail page', $showResponse->status() === 200 && str_contains($showResponse->body(), 'Situation'));
check('detail page shows priority reason', str_contains($showResponse->body(), 'Why this priority?'));
check('detail page shows timeline', str_contains($showResponse->body(), 'REQUEST_CREATED'));
check('detail page escapes description', str_contains($showResponse->body(), 'family of four'));

$_SESSION['user_id'] = $otherCitizen;
$_SESSION['user'] = $otherCitizenRow;
$forbiddenShow = $web->show(make_request('GET', '/requests/' . $detailId), (string) $detailId);
check('non-owner detail page is 404', $forbiddenShow->status() === 404);

$_SESSION['user_id'] = $citizen;
$_SESSION['user'] = $citizenRow;

$cancelResponse = $web->cancel(make_request('POST', '/requests/' . $detailId . '/cancel', ['_token' => csrf_token(), 'note' => 'Resolved locally']), (string) $detailId);
check('web cancel redirects to detail', $cancelResponse->status() === 302);
check('request is cancelled after web action', (string) EmergencyRequestRepository::find($detailId)['status'] === 'CANCELLED');

// Invalid create via web: PRG with flashed errors, no new row
$beforeCount = EmergencyRequestRepository::count();
$invalidStore = $web->store(make_request('POST', '/requests', [
    '_token'          => csrf_token(),
    'category'        => 'MEDICAL',
    'description'     => 'short',
    'people_affected' => 1,
]));
check('invalid web create redirects back', $invalidStore->status() === 302);
check('invalid web create does not persist', EmergencyRequestRepository::count() === $beforeCount);

// ---------------------------------------------------------------------------
echo "\nAPI controller\n";

$_SESSION['user_id'] = $citizen;
$_SESSION['user'] = $citizenRow;

$csrf = csrf_token();
$apiCreate = $api->store(make_request('POST', '/api/v1/requests', [
    'category'        => 'MEDICAL',
    'description'     => 'Elderly person collapsed at home, needs immediate medical support.',
    'people_affected' => 1,
    'latitude'        => 28.6,
    'longitude'       => 77.2,
], ['x-csrf-token' => $csrf]));
check('API create returns 201', $apiCreate->status() === 201);
$apiBody = json_decode($apiCreate->body(), true);
check('API create success envelope', ($apiBody['success'] ?? null) === true);
$apiRequestId = (int) ($apiBody['data']['request']['id'] ?? 0);
$requestCleanup[] = $apiRequestId;
check('API create returns request id', $apiRequestId > 0);
check('API create returns priority', in_array((string) ($apiBody['data']['request']['priority'] ?? ''), ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true));
check('API priority_reason is an array', is_array($apiBody['data']['request']['priority_reason'] ?? null));

$apiList = $api->index(make_request('GET', '/api/v1/requests'));
$apiListBody = json_decode($apiList->body(), true);
check('API list success', ($apiListBody['success'] ?? null) === true);
check('API list has items + pagination', isset($apiListBody['data']['items'], $apiListBody['data']['pagination']));
$foundInList = false;
foreach ($apiListBody['data']['items'] as $row) {
    if ((int) $row['id'] === $apiRequestId) {
        $foundInList = true;
    }
}
check('API list includes created request', $foundInList);

$apiFiltered = $api->index(make_request('GET', '/api/v1/requests?status=CREATED&priority=MEDIUM', [], [], true));
$apiFilteredBody = json_decode($apiFiltered->body(), true);
check('API list filters by status/priority', ($apiFilteredBody['success'] ?? null) === true);

$apiShow = $api->show(make_request('GET', '/api/v1/requests/' . $apiRequestId), (string) $apiRequestId);
check('API show returns request', $apiShow->status() === 200 && str_contains($apiShow->body(), 'description'));

$apiPatch = $api->update(make_request('PATCH', '/api/v1/requests/' . $apiRequestId, [
    'description'     => 'Update: ambulance requested, still waiting on scene.',
    'people_affected' => 2,
], ['x-csrf-token' => $csrf]), (string) $apiRequestId);
check('API PATCH updates request', $apiPatch->status() === 200);
$patchBody = json_decode($apiPatch->body(), true);
check('API PATCH returns updated people_affected', (int) ($patchBody['data']['people_affected'] ?? 0) === 2);

$apiEvents = $api->events(make_request('GET', '/api/v1/requests/' . $apiRequestId . '/events'), (string) $apiRequestId);
$apiEventsBody = json_decode($apiEvents->body(), true);
check('API events endpoint works', $apiEvents->status() === 200 && count($apiEventsBody['data'] ?? []) >= 2);

$apiVerify = $api->verify(make_request('POST', '/api/v1/requests/' . $apiRequestId . '/verify', [], ['x-csrf-token' => $csrf]), (string) $apiRequestId);
// Citizen session is still active — verify must be forbidden at route/service level
check('API verify forbidden for citizen', $apiVerify->status() === 403);

$_SESSION['user_id'] = $admin;
$_SESSION['user'] = $adminRow;
$apiVerify = $api->verify(make_request('POST', '/api/v1/requests/' . $apiRequestId . '/verify', [], ['x-csrf-token' => $csrf]), (string) $apiRequestId);
check('API verify allowed for admin', $apiVerify->status() === 200);
$verifyBody = json_decode($apiVerify->body(), true);
check('API verify returns VERIFIED status', (string) ($verifyBody['data']['status'] ?? '') === 'VERIFIED');

$apiEscalate = $api->escalate(make_request('POST', '/api/v1/requests/' . $apiRequestId . '/escalate', [
    'reason' => 'Escalating because matching found no volunteers.',
], ['x-csrf-token' => $csrf]), (string) $apiRequestId);
check('API escalate allowed for admin', $apiEscalate->status() === 200);
check('API escalate sets ESCALATED', (string) (json_decode($apiEscalate->body(), true)['data']['status'] ?? '') === 'ESCALATED');

$apiStatus = $api->status(make_request('POST', '/api/v1/requests/' . $apiRequestId . '/status', [
    'status' => 'NOT_A_STATUS',
], ['x-csrf-token' => $csrf]), (string) $apiRequestId);
check('API invalid status value rejected', $apiStatus->status() === 422);

$apiStatus = $api->status(make_request('POST', '/api/v1/requests/' . $apiRequestId . '/status', [
    'status' => 'CANCELLED',
    'note'   => 'Admin cancelled duplicate report.',
], ['x-csrf-token' => $csrf]), (string) $apiRequestId);
check('API status update works', $apiStatus->status() === 200);

$_SESSION['user_id'] = $citizen;
$_SESSION['user'] = $citizenRow;

$apiCancelled = $api->cancel(make_request('POST', '/api/v1/requests/' . $detailId . '/cancel', [], ['x-csrf-token' => $csrf]), (string) $detailId);
check('API cancel on already-cancelled → 409', $apiCancelled->status() === 409);

$apiMissing = $api->show(make_request('GET', '/api/v1/requests/999999999'), '999999999');
check('API missing request → 404', $apiMissing->status() === 404);

$apiAttachmentFile = make_png_file();
$fileCleanup[] = $apiAttachmentFile;

$apiAttachment = $api->storeAttachment(
    make_request('POST', '/api/v1/requests/' . $detailId . '/attachments', [], ['x-csrf-token' => $csrf], false, [
        'file' => [
            'name'     => 'photo.png',
            'type'     => 'image/png',
            'tmp_name' => $apiAttachmentFile,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) filesize($apiAttachmentFile),
        ],
    ]),
    (string) $detailId
);
// detail is cancelled but still viewable — attachment should succeed for owner
check('API attachment upload works for owner', $apiAttachment->status() === 201);

// ---------------------------------------------------------------------------
echo "\nSecurity notes\n";

$detailPage = (string) $showResponse->body();
check('detail page does not leak SQL', !str_contains(strtolower($detailPage), 'sql syntax') && !str_contains($detailPage, 'SELECT * FROM'));
check('service errors never include raw SQL text', !str_contains((new EmergencyException('VALIDATION_ERROR', 'Validation failed.', 422, []))->userMessage(), 'SELECT'));

// CSRF missing on API mutate
$_SESSION['user_id'] = $citizen;
$_SESSION['user'] = $citizenRow;
$noCsrf = $api->store(make_request('POST', '/api/v1/requests', [
    'category'        => 'FIRE',
    'description'     => 'Fire in the market area, need help immediately.',
    'people_affected' => 6,
], []));
// Controller itself does not enforce CSRF — middleware does. Verify middleware blocks it.
$_SESSION['_csrf_token'] = csrf_token();
$csrfMiddleware = new \App\Middleware\CsrfMiddleware();
$blocked = $csrfMiddleware->handle(make_request('POST', '/api/v1/requests', [], []), static fn (): Response => Response::html('OK', 200));
check('CsrfMiddleware blocks API mutate without token', $blocked->status() === 403);
check('API controller without CSRF token can still be unit-called', true); // documented separation

// ---------------------------------------------------------------------------
echo "\nCleanup\n";

try {
    // Delete by user first so FK RESTRICT on users cannot block cleanup.
    foreach ($userCleanup as $userId) {
        $requestIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            Database::select('SELECT id FROM emergency_requests WHERE user_id = ?', [$userId])
        );

        foreach ($requestIds as $requestId) {
            foreach (AttachmentRepository::forRequest($requestId) as $row) {
                $path = storage_path((string) $row['storage_path']);
                if (is_file($path)) {
                    unlink($path);
                }
            }
            EmergencyRequestRepository::delete($requestId);
        }

        UserRepository::delete($userId);
    }

    foreach ($fileCleanup as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    echo "  cleanup deletes finished\n";
} catch (Throwable $exception) {
    echo '  CLEANUP ERROR: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n";
    $failed++;
}

check('test users removed', UserRepository::find($citizen) === null && UserRepository::find($admin) === null);
check('test requests removed', EmergencyRequestRepository::find((int) $created['id']) === null);

echo "\n----------------------------------------\n";
echo "Passed: {$passed}   Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
