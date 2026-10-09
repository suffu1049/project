<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EmergencyRequestRepository;
use App\Models\RequestEventRepository;

/**
 * Seeds 10 emergency requests spread across priorities and lifecycle statuses,
 * each with a matching request_events timeline.
 */
final class EmergencyRequestSeeder extends Seeder
{
    /**
     * Priority score ranges by priority band (transparent, deterministic).
     */
    private const BASE_SCORE = [
        'CRITICAL' => 85,
        'HIGH'     => 68,
        'MEDIUM'   => 48,
        'LOW'      => 25,
    ];

    /** @var array<int, array<string, mixed>> */
    public const REQUESTS = [
        ['email' => 'citizen1@resqlink.test', 'category' => 'MEDICAL',          'priority' => 'CRITICAL', 'status' => 'MATCHING',    'people' => 3, 'desc' => 'Three people injured in a building collapse, urgent medical help needed.', 'lat' => 20.38930000, 'lng' => 72.91060000, 'address' => 'Demo City Central'],
        ['email' => 'citizen2@resqlink.test', 'category' => 'FLOOD',            'priority' => 'HIGH',     'status' => 'ACCEPTED',    'people' => 5, 'desc' => 'Five people stranded by rising flood water near the river bank.',          'lat' => 20.38420000, 'lng' => 72.91800000, 'address' => 'River Bank Colony'],
        ['email' => 'citizen3@resqlink.test', 'category' => 'FOOD_WATER',       'priority' => 'MEDIUM',   'status' => 'CREATED',     'people' => 2, 'desc' => 'Family with no food or drinking water after supply disruption.',           'lat' => 20.39180000, 'lng' => 72.90550000, 'address' => 'Sector 7'],
        ['email' => 'citizen4@resqlink.test', 'category' => 'TRANSPORT',        'priority' => 'HIGH',     'status' => 'IN_PROGRESS', 'people' => 1, 'desc' => 'Elderly person needs transport to the hospital for treatment.',            'lat' => 20.38710000, 'lng' => 72.91430000, 'address' => 'Green Park'],
        ['email' => 'citizen5@resqlink.test', 'category' => 'FIRE',             'priority' => 'CRITICAL', 'status' => 'RESOLVED',    'people' => 4, 'desc' => 'Small fire in a residential block, residents evacuated safely.',           'lat' => 20.38290000, 'lng' => 72.90710000, 'address' => 'Old Town'],
        ['email' => 'citizen1@resqlink.test', 'category' => 'SHELTER',          'priority' => 'MEDIUM',   'status' => 'MATCHING',    'people' => 6, 'desc' => 'Six displaced people need temporary shelter for the night.',               'lat' => 20.39060000, 'lng' => 72.92070000, 'address' => 'Market Road'],
        ['email' => 'citizen2@resqlink.test', 'category' => 'ACCIDENT',         'priority' => 'HIGH',     'status' => 'ACCEPTED',    'people' => 2, 'desc' => 'Road accident with two injured people awaiting first aid.',                'lat' => 20.38630000, 'lng' => 72.90290000, 'address' => 'Ring Road Junction'],
        ['email' => 'citizen3@resqlink.test', 'category' => 'MISSING_STRANDED', 'priority' => 'CRITICAL', 'status' => 'MATCHING',    'people' => 1, 'desc' => 'Child separated from family in a crowded relief camp.',                    'lat' => 20.39310000, 'lng' => 72.91640000, 'address' => 'Relief Camp A'],
        ['email' => 'citizen4@resqlink.test', 'category' => 'MEDICAL',          'priority' => 'LOW',      'status' => 'RESOLVED',    'people' => 1, 'desc' => 'Person recovered after receiving basic medical assistance.',               'lat' => 20.38550000, 'lng' => 72.91180000, 'address' => 'Hill View'],
        ['email' => 'citizen5@resqlink.test', 'category' => 'OTHER',            'priority' => 'LOW',      'status' => 'CANCELLED',   'people' => 1, 'desc' => 'Request withdrawn after help arrived from another source.',                'lat' => 20.38870000, 'lng' => 72.90990000, 'address' => 'Lake Side'],
    ];

    public function run(): void
    {
        foreach (self::REQUESTS as $request) {
            $userId = self::userId($request['email']);
            $resolvedAt = in_array($request['status'], ['RESOLVED', 'CANCELLED'], true) ? date('Y-m-d H:i:s') : null;

            $requestId = EmergencyRequestRepository::create([
                'user_id'       => $userId,
                'category'      => $request['category'],
                'description'   => $request['desc'],
                'people_affected' => $request['people'],
                'priority'      => $request['priority'],
                'priority_score' => self::BASE_SCORE[$request['priority']],
                'priority_reason' => self::reason($request),
                'status'        => $request['status'],
                'latitude'      => $request['lat'],
                'longitude'     => $request['lng'],
                'address_text'  => $request['address'],
                'resolved_at'   => $resolvedAt,
            ]);

            $this->seedTimeline($requestId, $userId, $request['status']);
        }

        $this->say('  emergency_requests: 10 + timelines');
    }

    private static function reason(array $request): string
    {
        $reasons = [
            'CRITICAL' => 'Immediate danger reported with multiple people affected.',
            'HIGH'     => 'Urgent assistance requested; situation may worsen quickly.',
            'MEDIUM'   => 'Assistance needed soon but no immediate life-threatening risk.',
            'LOW'      => 'Non-urgent assistance request.',
        ];

        return $reasons[$request['priority']] . ' Category: ' . $request['category'] . '.';
    }

    /**
     * Build a request_events chain that reflects the current status.
     */
    private function seedTimeline(int $requestId, int $userId, string $status): void
    {
        RequestEventRepository::record($requestId, 'REQUEST_CREATED', $userId, null, 'CREATED', 'Emergency request created.');
        RequestEventRepository::record($requestId, 'PRIORITY_CALCULATED', null, null, 'CREATED', 'Priority calculated by the priority engine.');

        $chain = [
            'MATCHING'    => ['CREATED', 'MATCHING'],
            'ACCEPTED'    => ['CREATED', 'MATCHING', 'ACCEPTED'],
            'IN_PROGRESS' => ['CREATED', 'MATCHING', 'ACCEPTED', 'IN_PROGRESS'],
            'RESOLVED'    => ['CREATED', 'MATCHING', 'ACCEPTED', 'IN_PROGRESS', 'RESOLVED'],
            'CANCELLED'   => ['CREATED', 'CANCELLED'],
        ];

        $states = $chain[$status] ?? null;

        if ($states === null) {
            return;
        }

        $previous = null;

        foreach ($states as $state) {
            if ($previous !== null) {
                RequestEventRepository::record(
                    $requestId,
                    'STATUS_CHANGED',
                    $userId,
                    $previous,
                    $state,
                    'Status changed to ' . $state . '.'
                );
            }

            $previous = $state;
        }
    }
}
