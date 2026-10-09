<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;
use App\Models\MatchRepository;

/**
 * Seeds matches for the demo requests: volunteer matches for open/accepted/
 * resolved requests and resource matches for supply requests.
 *
 * Requests are referenced by their insertion order (AUTO_INCREMENT resets on
 * truncate), which keeps the demo deterministic.
 */
final class MatchSeeder extends Seeder
{
    public function run(): void
    {
        $requests = array_values(Database::select('SELECT id FROM emergency_requests ORDER BY id'));

        if ($requests === []) {
            return;
        }

        $requestId = static fn (int $index): ?int => isset($requests[$index]) ? (int) $requests[$index]['id'] : null;

        $resources = array_values(Database::select('SELECT id FROM resources ORDER BY id'));
        $resourceId = static fn (int $index): ?int => isset($resources[$index]) ? (int) $resources[$index]['id'] : null;

        // [request index, volunteer email|null, resource index|null, distance, capability, score, status]
        $spec = [
            [0, 'volunteer2@resqlink.test', null, 2.14, 90.00, 88.00, 'NOTIFIED'],
            [0, null, 3,                        4.20, 85.00, 80.00, 'SUGGESTED'],
            [1, 'volunteer5@resqlink.test', null, 3.80, 86.00, 84.00, 'ACCEPTED'],
            [3, 'volunteer3@resqlink.test', null, 2.60, 92.00, 90.00, 'ACCEPTED'],
            [4, 'volunteer8@resqlink.test', null, 4.10, 94.00, 92.00, 'COMPLETED'],
            [5, null, 6,                        5.30, 80.00, 76.00, 'NOTIFIED'],
            [6, 'volunteer6@resqlink.test', null, 1.90, 88.00, 86.00, 'ACCEPTED'],
            [6, null, 3,                        4.60, 82.00, 78.00, 'ACCEPTED'],
            [7, 'volunteer5@resqlink.test', null, 3.10, 89.00, 87.00, 'NOTIFIED'],
            [8, 'volunteer2@resqlink.test', null, 1.70, 90.00, 89.00, 'COMPLETED'],
        ];

        foreach ($spec as [$index, $volunteerEmail, $resourceIndex, $distance, $capability, $score, $status]) {
            $id = $requestId($index);

            if ($id === null) {
                continue;
            }

            $volunteerId = $volunteerEmail !== null ? self::volunteerProfileId($volunteerEmail) : null;
            $resId = $resourceIndex !== null ? $resourceId((int) $resourceIndex) : null;

            if ($volunteerId === null && $resId === null) {
                continue;
            }

            MatchRepository::createMatch([
                'request_id'       => $id,
                'volunteer_id'     => $volunteerId,
                'resource_id'      => $resId,
                'distance_km'      => $distance,
                'capability_score' => $capability,
                'match_score'      => $score,
                'status'           => $status,
            ]);
        }

        $this->say('  matches: ' . count($spec) . ' records');
    }
}
