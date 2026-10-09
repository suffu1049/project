<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Repository;

/**
 * Analytics aggregate queries (Phase 11).
 *
 * Read-only. Every metric is returned by a single aggregate SQL query (no
 * N+1, no loading whole tables into PHP). Time-bucket granularity and the
 * DATE_FORMAT pattern are whitelisted by AnalyticsService before they reach
 * this class; the window boundary is always bound as a parameter and
 * LIMIT/OFFSET-style integers are never taken from request input here.
 *
 * All-time KPIs (active/resolved/total) live in EmergencyRequestRepository;
 * this repository owns the *time-windowed* series and breakdowns plus the
 * resolution-time and volunteer-activity aggregates.
 */
final class AnalyticsRepository extends Repository
{
    protected static string $table = 'emergency_requests';

    /** Statuses that count as "open" operational work (mirrors EmergencyRequestRepository). */
    private const ACTIVE_STATUSES = ['CREATED', 'VERIFIED', 'MATCHING', 'ACCEPTED', 'IN_PROGRESS', 'ESCALATED'];

    /**
     * Request counts grouped by a time bucket.
     *
     * `$format` must be a MySQL DATE_FORMAT pattern produced from the
     * whitelisted granularity map in AnalyticsService — never user input.
     *
     * @return array<string, int> bucket key => count
     */
    public static function countsByBucket(string $format, ?string $from = null): array
    {
        $where = $from !== null ? ' WHERE created_at >= :from' : '';
        $bindings = $from !== null ? ['from' => $from] : [];

        $rows = Database::select(
            "SELECT DATE_FORMAT(created_at, :format) AS bucket, COUNT(*) AS total
             FROM emergency_requests" . $where . '
             GROUP BY bucket',
            $bindings + ['format' => $format]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['bucket']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Request counts grouped by category within the window.
     *
     * @return array<string, int>
     */
    public static function countsByCategory(?string $from = null): array
    {
        return self::groupedCount('category', $from);
    }

    /**
     * Request counts grouped by priority within the window.
     *
     * @return array<string, int>
     */
    public static function countsByPriority(?string $from = null): array
    {
        return self::groupedCount('priority', $from);
    }

    /**
     * Request counts grouped by status within the window.
     *
     * @return array<string, int>
     */
    public static function countsByStatus(?string $from = null): array
    {
        return self::groupedCount('status', $from);
    }

    /**
     * Total requests created within the window.
     */
    public static function totalRequests(?string $from = null): int
    {
        $where = $from !== null ? ' WHERE created_at >= :from' : '';
        $bindings = $from !== null ? ['from' => $from] : [];

        $row = Database::selectOne('SELECT COUNT(*) AS aggregate FROM emergency_requests' . $where, $bindings);

        return (int) ($row['aggregate'] ?? 0);
    }

    /**
     * Requests created within the window that are still open work.
     */
    public static function activeRequests(?string $from = null): int
    {
        $placeholders = implode(', ', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
        $where = "status IN ({$placeholders})";

        if ($from !== null) {
            return (int) (Database::selectOne(
                "SELECT COUNT(*) AS aggregate FROM emergency_requests WHERE {$where} AND created_at >= ?",
                array_merge(self::ACTIVE_STATUSES, [$from])
            )['aggregate'] ?? 0);
        }

        return (int) (Database::selectOne(
            "SELECT COUNT(*) AS aggregate FROM emergency_requests WHERE {$where}",
            self::ACTIVE_STATUSES
        )['aggregate'] ?? 0);
    }

    /**
     * Resolution-time statistics for RESOLVED requests.
     *
     * Durations are measured in whole minutes and returned as hours. Only
     * rows with both timestamps are counted, so a missing resolved_at can never
     * skew the average.
     *
     * @return array{resolved:int, tracked:int, average_hours:float|null, fastest_hours:float|null, slowest_hours:float|null}
     */
    public static function resolutionStats(): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS resolved,
                    COUNT(resolved_at) AS tracked,
                    AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) AS avg_minutes,
                    MIN(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) AS min_minutes,
                    MAX(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) AS max_minutes
             FROM emergency_requests
             WHERE status = 'RESOLVED' AND resolved_at IS NOT NULL"
        );

        return [
            'resolved'      => (int) ($row['resolved'] ?? 0),
            'tracked'       => (int) ($row['tracked'] ?? 0),
            'average_hours' => self::hours($row['avg_minutes'] ?? null),
            'fastest_hours' => self::hours($row['min_minutes'] ?? null),
            'slowest_hours' => self::hours($row['max_minutes'] ?? null),
        ];
    }

    /**
     * Volunteer assignment activity derived from the matches table.
     *
     * @return array{accepted:int, completed:int, rejected:int, expired:int}
     */
    public static function volunteerTaskCounts(): array
    {
        $row = Database::selectOne(
            "SELECT COALESCE(SUM(status = 'ACCEPTED'), 0) AS accepted,
                    COALESCE(SUM(status = 'COMPLETED'), 0) AS completed,
                    COALESCE(SUM(status = 'REJECTED'), 0) AS rejected,
                    COALESCE(SUM(status = 'EXPIRED'), 0) AS expired
             FROM matches
             WHERE volunteer_id IS NOT NULL"
        );

        return [
            'accepted'  => (int) ($row['accepted'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'rejected'  => (int) ($row['rejected'] ?? 0),
            'expired'   => (int) ($row['expired'] ?? 0),
        ];
    }

    /**
     * @return array<string, int> value => count
     */
    private static function groupedCount(string $column, ?string $from): array
    {
        // Column is a literal from the callers above — never request input.
        $where = $from !== null ? ' WHERE created_at >= :from' : '';
        $bindings = $from !== null ? ['from' => $from] : [];

        $rows = Database::select(
            "SELECT `{$column}` AS value, COUNT(*) AS total FROM emergency_requests" . $where . ' GROUP BY value',
            $bindings
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['value']] = (int) $row['total'];
        }

        return $counts;
    }

    private static function hours(mixed $minutes): ?float
    {
        if ($minutes === null || $minutes === '') {
            return null;
        }

        return round((float) $minutes / 60, 1);
    }
}
