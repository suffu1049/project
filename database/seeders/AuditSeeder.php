<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AuditRepository;

/**
 * Seeds representative audit entries (login, request creation, verification,
 * match acceptance, status change). Metadata contains no secrets.
 */
final class AuditSeeder extends Seeder
{
    public function run(): void
    {
        $admin    = self::userId('admin@resqlink.test');
        $citizen1 = self::userId('citizen1@resqlink.test');
        $volunteer2 = self::userId('volunteer2@resqlink.test');

        AuditRepository::record($admin, 'LOGIN', 'user', $admin, ['channel' => 'web'], '127.0.0.1', 'seed');
        AuditRepository::record($citizen1, 'CREATE_REQUEST', 'emergency_request', 1, ['category' => 'MEDICAL', 'priority' => 'CRITICAL'], '127.0.0.1', 'seed');
        AuditRepository::record($admin, 'VERIFY_REQUEST', 'emergency_request', 2, ['from' => 'CREATED', 'to' => 'VERIFIED'], '127.0.0.1', 'seed');
        AuditRepository::record($volunteer2, 'ACCEPT_MATCH', 'match', 1, ['request_id' => 1], '127.0.0.1', 'seed');
        AuditRepository::record($volunteer2, 'UPDATE_REQUEST_STATUS', 'emergency_request', 4, ['from' => 'ACCEPTED', 'to' => 'IN_PROGRESS'], '127.0.0.1', 'seed');
        AuditRepository::record($admin, 'COMPLETE_MATCH', 'match', 5, ['request_id' => 5], '127.0.0.1', 'seed');

        $this->say('  audit_logs: 6');
    }
}
