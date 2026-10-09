<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
use App\Models\AnalyticsRepository;
use App\Models\EmergencyRequestRepository;
use App\Models\ResourceRepository;
use App\Models\VolunteerRepository;
use DateTimeImmutable;

/**
 * Operational analytics (Phase 11).
 *
 * Turns the aggregate queries in AnalyticsRepository into a single dashboard
 * payload for the admin analytics page and the `GET /admin/analytics` API
 * (API.md §58). Read-only: it never mutates state, so it writes no audit rows.
 *
 * `period` is validated against a whitelist and mapped to a time window +
 * bucket granularity; an invalid value degrades to the default (`week`) rather
 * than failing, and no request input ever reaches SQL — the bucket format is
 * chosen from a fixed map and the window boundary is bound as a parameter.
 */
final class AnalyticsService extends Service
{
    /** Allowed `period` values (API.md §58). First entry is the default. */
    public const PERIODS = ['week', 'day', 'month', 'year'];

    /** Buckets each period is rendered with. */
    private const PERIOD_SHAPE = [
        'day'   => ['count' => 24, 'step' => '+1 hour',  'key' => 'Y-m-d H:00:00', 'label' => 'H:00'],
        'week'  => ['count' => 7,  'step' => '+1 day',   'key' => 'Y-m-d',         'label' => 'D'],
        'month' => ['count' => 30, 'step' => '+1 day',   'key' => 'Y-m-d',         'label' => 'j M'],
        'year'  => ['count' => 12, 'step' => '+1 month', 'key' => 'Y-m-01',        'label' => 'M Y'],
    ];

    /**
     * Full analytics payload for a validated period.
     *
     * @return array<string, mixed>
     */
    public function overview(string $period): array
    {
        $period = $this->normalisePeriod($period);
        $spec = $this->periodSpec($period);

        $byCategory = AnalyticsRepository::countsByCategory($spec['from']);
        $byPriority = AnalyticsRepository::countsByPriority($spec['from']);
        $byStatus = AnalyticsRepository::countsByStatus($spec['from']);
        $byBucket = AnalyticsRepository::countsByBucket($spec['format'], $spec['from']);

        $series = [];
        $seriesTotal = 0;

        foreach ($spec['buckets'] as $bucket) {
            $count = $byBucket[$bucket['key']] ?? 0;
            $series[] = $count;
            $seriesTotal += $count;
        }

        $resourceStats = ResourceRepository::adminStats();
        $resourceByType = ResourceRepository::summaryByType();
        $volunteerTasks = AnalyticsRepository::volunteerTaskCounts();

        return [
            'period'   => $period,
            'periods'  => self::PERIODS,
            'window'   => [
                'from' => $spec['from'],
                'to'   => $spec['to'],
                'from_label' => $spec['from_label'],
            ],
            'requests' => [
                'total'       => AnalyticsRepository::totalRequests($spec['from']),
                'active'      => AnalyticsRepository::activeRequests($spec['from']),
                'by_category' => $byCategory,
                'by_priority' => $byPriority,
                'by_status'   => $byStatus,
                'over_time'   => [
                    'labels' => $spec['labels'],
                    'values' => $series,
                    'total'  => $seriesTotal,
                ],
                'resolution'  => AnalyticsRepository::resolutionStats(),
            ],
            'resources' => [
                'stats'        => $resourceStats,
                'by_type'      => $resourceByType,
                'allocated'    => max(0, (int) $resourceStats['total_quantity'] - (int) $resourceStats['available_quantity']),
                'availability' => $this->resourceAvailability($resourceByType),
            ],
            'volunteers' => [
                'available' => VolunteerRepository::countAvailableVerified(),
                'pending'   => VolunteerRepository::countByVerification('PENDING'),
                'accepted'  => $volunteerTasks['accepted'],
                'completed' => $volunteerTasks['completed'],
                'rejected'  => $volunteerTasks['rejected'],
            ],
            'all_time' => [
                'total'    => EmergencyRequestRepository::totalCount(),
                'active'   => EmergencyRequestRepository::activeCount(),
                'resolved' => EmergencyRequestRepository::resolvedCount(),
            ],
        ];
    }

    /**
     * Flattened payload matching the documented API.md §58 shape.
     *
     * @param array<string, mixed> $overview
     * @return array<string, mixed>
     */
    public function apiPayload(array $overview): array
    {
        return [
            'period'               => $overview['period'],
            'window'               => $overview['window'],
            'requests_by_category' => $overview['requests']['by_category'],
            'requests_by_priority' => $overview['requests']['by_priority'],
            'requests_by_status'   => $overview['requests']['by_status'],
            'requests_over_time'   => $overview['requests']['over_time'],
            'requests_total'       => $overview['requests']['total'],
            'resolution'           => $overview['requests']['resolution'],
            'resources'            => $overview['resources'],
            'volunteers'           => $overview['volunteers'],
        ];
    }

    /**
     * Validate a period value, degrading safely to the default.
     */
    public function normalisePeriod(string $period): string
    {
        $period = strtolower(trim($period));

        return in_array($period, self::PERIODS, true) ? $period : self::PERIODS[0];
    }

    /**
     * Resolve a period into a window, bucket key list, labels and a MySQL
     * DATE_FORMAT pattern. The pattern comes from the fixed map above.
     *
     * @return array{from:string,to:string,from_label:string,format:string,labels:array<int,string>,buckets:array<int,array{key:string,label:string}>}
     */
    private function periodSpec(string $period): array
    {
        $shape = self::PERIOD_SHAPE[$period];
        $now = new DateTimeImmutable('now');
        $to = $now->format('Y-m-d H:i:s');

        // Anchor the window to the newest bucket so it always includes "now".
        $anchor = match ($period) {
            'day'   => $now->setTime((int) $now->format('H'), 0, 0),
            'month' => $now->setTime(0, 0, 0),
            'year'  => $now->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)->setTime(0, 0, 0),
            default => $now->setTime(0, 0, 0),
        };

        // Walk backwards (count - 1) steps to find the first bucket.
        $first = $anchor;
        for ($i = 1; $i < $shape['count']; $i++) {
            $first = $first->modify($shape['step'] === '+1 month' ? '-1 month' : '-' . ltrim($shape['step'], '+'));
        }

        $labels = [];
        $buckets = [];
        $cursor = $first;

        for ($i = 0; $i < $shape['count']; $i++) {
            $labels[] = $cursor->format($shape['label']);
            $buckets[] = [
                'key'   => $cursor->format($shape['key']),
                'label' => $cursor->format($shape['label']),
            ];
            $cursor = $cursor->modify($shape['step']);
        }

        return [
            'from'       => $first->format('Y-m-d H:i:s'),
            'to'         => $to,
            'from_label' => $first->format('d M Y, H:i'),
            'format'     => $this->mysqlFormat($shape['key']),
            'labels'     => $labels,
            'buckets'    => $buckets,
        ];
    }

    /**
     * PHP date format => matching MySQL DATE_FORMAT pattern.
     */
    private function mysqlFormat(string $phpFormat): string
    {
        return match ($phpFormat) {
            'Y-m-d H:00:00' => '%Y-%m-%d %H:00:00',
            'Y-m-01'        => '%Y-%m-01',
            default         => '%Y-%m-%d',
        };
    }

    /**
     * Chart-ready resource availability per type.
     *
     * @param array<string, array{total:int, available:int}> $byType
     * @return array{labels:array<int,string>,available:array<int,int>,total:array<int,int>}
     */
    private function resourceAvailability(array $byType): array
    {
        $labels = [];
        $available = [];
        $total = [];

        foreach ($byType as $type => $row) {
            $labels[] = str_replace('_', ' ', (string) $type);
            $available[] = (int) $row['available'];
            $total[] = (int) $row['total'];
        }

        return [
            'labels'    => $labels,
            'available' => $available,
            'total'     => $total,
        ];
    }
}
