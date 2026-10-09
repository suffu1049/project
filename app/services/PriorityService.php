<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;

/**
 * Deterministic, rule-based priority engine (Phase 4).
 *
 * AI must NEVER make the final priority decision. Every score is produced
 * from transparent rules so the UI can explain why a request received its
 * priority. Weights are class constants and can be re-tuned without touching
 * controllers or the database.
 *
 * Output contract (stored on emergency_requests):
 *   priority        LOW | MEDIUM | HIGH | CRITICAL
 *   priority_score  integer score used for the threshold mapping
 *   priority_reason human-readable bullet list of contributing factors
 */
final class PriorityService extends Service
{
    /** Base score contributed by the emergency category. */
    private const CATEGORY_SCORES = [
        'MEDICAL'          => 40,
        'FIRE'             => 50,
        'ACCIDENT'         => 35,
        'FLOOD'            => 25,
        'MISSING_STRANDED' => 30,
        'FOOD_WATER'       => 15,
        'SHELTER'          => 12,
        'TRANSPORT'        => 10,
        'OTHER'            => 10,
    ];

    /** Human labels for category factors in the reason list. */
    private const CATEGORY_LABELS = [
        'MEDICAL'          => 'Medical emergency',
        'FIRE'             => 'Fire or active danger',
        'ACCIDENT'         => 'Accident',
        'FLOOD'            => 'Flood',
        'MISSING_STRANDED' => 'Missing or stranded person',
        'FOOD_WATER'       => 'Food or water need',
        'SHELTER'          => 'Shelter need',
        'TRANSPORT'        => 'Transport need',
        'OTHER'            => 'General emergency',
    ];

    /** Score brackets by people affected count. */
    private const PEOPLE_BRACKETS = [
        // [minPeople, score, label]
        [10, 25, '10 or more people affected'],
        [6, 20, '6–9 people affected'],
        [4, 15, '4–5 people affected'],
        [2, 10, '2–3 people affected'],
        [1, 0, 'Single person affected'],
    ];

    /** Urgency keywords scanned in the description (case-insensitive). */
    private const URGENCY_KEYWORDS = [
        'immediate' => 15,
        'urgent' => 15,
        'critical' => 15,
        'life-threatening' => 15,
        'life threatening' => 15,
        'unconscious' => 15,
        'bleeding' => 12,
        'can\'t breathe' => 15,
        'cannot breathe' => 15,
        'trapped' => 12,
    ];

    /** Vulnerability keywords scanned in the description. */
    private const VULNERABILITY_KEYWORDS = [
        'child' => 10,
        'children' => 10,
        'baby' => 10,
        'infant' => 10,
        'elderly' => 10,
        'pregnant' => 10,
        'disabled' => 10,
        'handicap' => 10,
    ];

    /** Score → priority thresholds (score must be >= threshold). */
    private const THRESHOLDS = [
        'CRITICAL' => 70,
        'HIGH'     => 50,
        'MEDIUM'   => 30,
        // anything below 30 is LOW
    ];

    /**
     * Calculate priority for an emergency request payload.
     *
     * @param array{
     *     category: string,
     *     description: string,
     *     people_affected?: int|string|null
     * } $input
     * @return array{priority: string, priority_score: int, priority_reason: string, reasons: array<int, string>}
     */
    public function calculate(array $input): array
    {
        $category = strtoupper(trim((string) ($input['category'] ?? 'OTHER')));
        $description = (string) ($input['description'] ?? '');
        $people = max(1, (int) ($input['people_affected'] ?? 1));

        $score = 0;
        $reasons = [];

        // 1. Category
        $categoryScore = self::CATEGORY_SCORES[$category] ?? self::CATEGORY_SCORES['OTHER'];
        $score += $categoryScore;
        $reasons[] = (self::CATEGORY_LABELS[$category] ?? self::CATEGORY_LABELS['OTHER'])
            . sprintf(' (+%d)', $categoryScore);

        // 2. People affected
        foreach (self::PEOPLE_BRACKETS as [$min, $points, $label]) {
            if ($people >= $min) {
                $score += $points;

                if ($points > 0) {
                    $reasons[] = $label . sprintf(' (+%d)', $points);
                } else {
                    $reasons[] = $label;
                }

                break;
            }
        }

        // 3. Urgency keywords (deterministic text scan)
        $lower = mb_strtolower($description);
        $urgencyHit = 0;

        foreach (self::URGENCY_KEYWORDS as $keyword => $points) {
            if ($urgencyHit > 0) {
                break; // count urgency only once to keep scoring predictable
            }

            if ($keyword !== '' && str_contains($lower, $keyword)) {
                $urgencyHit = $points;
                $score += $points;
                $reasons[] = sprintf('Immediate urgency indicators (+%d)', $points);
            }
        }

        // 4. Vulnerability keywords
        $vulnerabilityHit = 0;

        foreach (self::VULNERABILITY_KEYWORDS as $keyword => $points) {
            if ($vulnerabilityHit > 0) {
                break;
            }

            if ($keyword !== '' && str_contains($lower, $keyword)) {
                $vulnerabilityHit = $points;
                $score += $points;
                $reasons[] = sprintf('Vulnerable people indicated (+%d)', $points);
            }
        }

        // 5. Nearby-resource scarcity is not evaluated until matching (Phase 7).
        $priority = $this->mapScoreToPriority($score);

        return [
            'priority'        => $priority,
            'priority_score'  => $score,
            'priority_reason' => implode("\n", $reasons),
            'reasons'         => $reasons,
        ];
    }

    /**
     * Map a numeric score onto LOW / MEDIUM / HIGH / CRITICAL.
     */
    public function mapScoreToPriority(int $score): string
    {
        foreach (self::THRESHOLDS as $priority => $threshold) {
            if ($score >= $threshold) {
                return $priority;
            }
        }

        return 'LOW';
    }

    /**
     * Exposed for tests / configurability checks.
     *
     * @return array<string, int>
     */
    public static function categoryScores(): array
    {
        return self::CATEGORY_SCORES;
    }

    /**
     * @return array<string, int>
     */
    public static function thresholds(): array
    {
        return self::THRESHOLDS;
    }
}
