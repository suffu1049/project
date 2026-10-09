<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;
use App\Models\EmergencyRequestRepository;
use App\Models\OrganizationRepository;
use App\Models\ResourceRepository;
use App\Models\UserRepository;
use App\Models\VolunteerRepository;

/**
 * Orchestrates the full demo dataset.
 *
 * Truncates application tables (foreign-key safe) then seeds them in
 * dependency order. Re-running produces an identical, deterministic dataset.
 */
final class DatabaseSeeder extends Seeder
{
    /** Children first, so foreign keys never block truncation. */
    private const TRUNCATE_ORDER = [
        'emergency_attachments',
        'request_events',
        'notifications',
        'matches',
        'audit_logs',
        'resources',
        'organizations',
        'volunteer_profiles',
        'emergency_requests',
        'users',
    ];

    /** @var array<int, class-string<Seeder>> */
    private const SEEDERS = [
        UsersSeeder::class,
        OrganizationSeeder::class,
        VolunteerSeeder::class,
        ResourceSeeder::class,
        EmergencyRequestSeeder::class,
        MatchSeeder::class,
        NotificationSeeder::class,
        AuditSeeder::class,
        AttachmentSeeder::class,
    ];

    public function run(): void
    {
        $this->say('Clearing existing demo data...');
        self::truncate(self::TRUNCATE_ORDER);

        foreach (self::SEEDERS as $seeder) {
            (new $seeder())->run();
        }

        $this->summary();
    }

    private function summary(): void
    {
        $this->say('');
        $this->say('Seed summary');
        $this->say('------------');

        $tables = ['users', 'volunteer_profiles', 'organizations', 'resources', 'emergency_requests', 'matches', 'notifications', 'request_events', 'emergency_attachments', 'audit_logs'];

        foreach ($tables as $table) {
            $row = Database::selectOne('SELECT COUNT(*) AS aggregate FROM `' . $table . '`');
            $this->say(sprintf('  %-22s %d', $table, (int) ($row['aggregate'] ?? 0)));
        }

        $this->say('');
        $this->say('Users by role:');
        foreach (['ADMIN', 'CITIZEN', 'VOLUNTEER', 'ORGANIZATION'] as $role) {
            $this->say(sprintf('  %-14s %d', $role, UserRepository::countByRole($role)));
        }

        $this->say('');
        $this->say(sprintf('  active requests:     %d', EmergencyRequestRepository::activeCount()));
        $this->say(sprintf('  critical requests:   %d', EmergencyRequestRepository::criticalCount()));
        $this->say(sprintf('  resolved requests:   %d', EmergencyRequestRepository::resolvedCount()));
        $this->say(sprintf('  available volunteers:%d', VolunteerRepository::countAvailableVerified()));
        $this->say(sprintf('  verified orgs:       %d', OrganizationRepository::countVerified()));
    }
}
