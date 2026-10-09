<?php

declare(strict_types=1);

/**
 * ResQLink — Phase 2 database integration tests.
 *
 * Requires a migrated + seeded database. Run with:
 *     php -c php.ini tests/database.php
 *
 * Exit code 0 = all passed, 1 = one or more failures.
 */

use App\Core\Database;
use App\Models\AttachmentRepository;
use App\Models\AuditRepository;
use App\Models\EmergencyRequestRepository;
use App\Models\MatchRepository;
use App\Models\NotificationRepository;
use App\Models\OrganizationRepository;
use App\Models\RequestEventRepository;
use App\Models\ResourceRepository;
use App\Models\UserRepository;
use App\Models\VolunteerRepository;

define('BASE_PATH', dirname(__DIR__));

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

/** Returns true when the callable throws. */
function throws(callable $callback): bool
{
    try {
        $callback();
    } catch (Throwable) {
        return true;
    }

    return false;
}

echo "ResQLink database tests\n";
echo "=======================\n\n";

echo "Connection\n";
check('database is reachable', Database::ping());

echo "\nSchema\n";
$tables = (new App\Core\Migrator(base_path('database/migrations')))->existingTables();
$expected = ['users', 'emergency_requests', 'volunteer_profiles', 'organizations', 'resources', 'matches', 'notifications', 'request_events', 'emergency_attachments', 'audit_logs', 'migrations'];
$missing = array_diff($expected, $tables);
check('all core tables exist', $missing === []);

echo "\nSeed data\n";
check('users seeded (>= 19)', UserRepository::count() >= 19);
check('admin exists', UserRepository::countByRole('ADMIN') === 1);
check('at least 5 citizens seeded', UserRepository::countByRole('CITIZEN') >= 5);
check('at least 10 volunteers seeded', VolunteerRepository::count() >= 10);
check('at least 3 organizations seeded', OrganizationRepository::count() >= 3);
check('8 resources seeded', ResourceRepository::count() === 8);
check('10 requests seeded', EmergencyRequestRepository::count() === 10);
check('request events seeded', RequestEventRepository::count() >= 10);
check('matches seeded', MatchRepository::count() === 10);
check('notifications seeded (>= 6)', NotificationRepository::count() >= 6);
check('attachments seeded', AttachmentRepository::count() === 2);
check('audit logs seeded', AuditRepository::count() === 6);

echo "\nRepository CRUD\n";
$email = 'crud-test@resqlink.test';
$userId = UserRepository::create([
    'name' => 'CRUD Test', 'email' => $email, 'phone' => null,
    'password_hash' => password_hash('irrelevant', PASSWORD_DEFAULT),
    'role' => 'CITIZEN', 'status' => 'ACTIVE',
]);
check('create returns an id', $userId > 0);
check('findByEmail finds the row', UserRepository::findByEmail($email) !== null);
check('emailExists true', UserRepository::emailExists($email));
check('update changes a column', UserRepository::update($userId, ['name' => 'CRUD Renamed']) === 1);
check('find reflects update', (UserRepository::find($userId)['name'] ?? null) === 'CRUD Renamed');
check('delete removes the row', UserRepository::delete($userId) === 1);
check('row is gone', UserRepository::find($userId) === null);

echo "\nConstraints (unique / FK / check)\n";
check('duplicate email rejected', throws(static fn () => UserRepository::create([
    'name' => 'Dup', 'email' => 'admin@resqlink.test', 'phone' => null,
    'password_hash' => 'x', 'role' => 'CITIZEN', 'status' => 'ACTIVE',
])));

check('request with invalid user rejected (FK)', throws(static fn () => EmergencyRequestRepository::create([
    'user_id' => 999999999, 'category' => 'MEDICAL', 'description' => 'x',
    'people_affected' => 1, 'priority' => 'LOW', 'priority_score' => 1,
    'status' => 'CREATED',
])));

check('resource available > quantity rejected (CHECK)', throws(static fn () => ResourceRepository::create([
    'organization_id' => OrganizationRepository::first()['id'] ?? 1,
    'resource_type' => 'FOOD', 'resource_name' => 'Bad Row',
    'quantity' => 10, 'available_quantity' => 999, 'status' => 'AVAILABLE',
])));

check('match without target rejected (rule)', throws(static fn () => MatchRepository::createMatch([
    'request_id' => EmergencyRequestRepository::first()['id'] ?? 1,
    'volunteer_id' => null, 'resource_id' => null,
    'match_score' => 0, 'status' => 'SUGGESTED',
])));

echo "\nForeign-key behavior\n";
$ownerId = Database::selectOne('SELECT user_id FROM emergency_requests LIMIT 1')['user_id'] ?? null;
check('deleting a request owner is restricted', $ownerId !== null && throws(static fn () => UserRepository::delete((int) $ownerId)));

$cascadeEmail = 'cascade-test@resqlink.test';
$cascadeId = UserRepository::create([
    'name' => 'Cascade', 'email' => $cascadeEmail, 'phone' => null,
    'password_hash' => 'x', 'role' => 'VOLUNTEER', 'status' => 'ACTIVE',
]);
VolunteerRepository::create([
    'user_id' => $cascadeId, 'skills' => 'GENERAL', 'availability' => 'OFFLINE',
    'service_radius_km' => 5, 'verification_status' => 'PENDING',
]);
check('volunteer profile created', VolunteerRepository::findByUserId($cascadeId) !== null);
UserRepository::delete($cascadeId);
check('deleting user cascades to volunteer profile', VolunteerRepository::findByUserId($cascadeId) === null);

echo "\nTransactions\n";
$before = UserRepository::count();
$rolled = throws(static function (): void {
    Database::transaction(static function (): void {
        UserRepository::create([
            'name' => 'Rollback', 'email' => 'rollback-test@resqlink.test', 'phone' => null,
            'password_hash' => 'x', 'role' => 'CITIZEN', 'status' => 'ACTIVE',
        ]);

        throw new RuntimeException('force rollback');
    });
});
check('transaction throws on failure', $rolled);
check('rollback reverted the insert', UserRepository::count() === $before);

echo "\nResource allocation\n";
$orgId = (int) Database::selectOne('SELECT id FROM organizations ORDER BY id LIMIT 1')['id'];
$resourceId = ResourceRepository::create([
    'organization_id' => $orgId, 'resource_type' => 'WATER', 'resource_name' => 'Test Water',
    'quantity' => 100, 'available_quantity' => 100, 'unit' => 'litres', 'status' => 'AVAILABLE',
]);

$result = ResourceRepository::allocate($resourceId, 30);
check('allocate decrements availability', $result['available_after'] === 70);
check('allocate persists', (int) ResourceRepository::find($resourceId)['available_quantity'] === 70);
check('over-allocation rejected', throws(static fn () => ResourceRepository::allocate($resourceId, 1000)));
check('over-allocation left quantity unchanged', (int) ResourceRepository::find($resourceId)['available_quantity'] === 70);
check('allocation of zero rejected', throws(static fn () => ResourceRepository::allocate($resourceId, 0)));
ResourceRepository::delete($resourceId);
check('test resource cleaned up', ResourceRepository::find($resourceId) === null);

echo "\nStatus recalculation\n";
check('status UNAVAILABLE when empty', ResourceRepository::statusForQuantity(0, 100) === 'UNAVAILABLE');
check('status LIMITED when low', ResourceRepository::statusForQuantity(10, 100) === 'LIMITED');
check('status AVAILABLE when healthy', ResourceRepository::statusForQuantity(80, 100) === 'AVAILABLE');

echo "\n----------------------------------------\n";
echo "Passed: {$passed}   Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
