<?php

declare(strict_types=1);

/**
 * Matching engine configuration (Phase 7).
 *
 * Weights are configurable; defaults follow the master prompt conceptual
 * scoring model. Scores are normalized to 0–100 for presentation.
 */
return [
    'weights' => [
        'capability'   => 0.50,
        'availability' => 0.20,
        'distance'     => 0.20,
        'verification' => 0.10,
    ],

    // Maximum persisted suggestions per request (volunteers + resources).
    'max_suggestions' => 10,

    // Score used when distance cannot be computed (missing coordinates).
    'distance_unknown_score' => 0.5,

    // Resource type compatibility: emergency category → resource types.
    // An empty array means "any available resource type is acceptable".
    'resource_compatibility' => [
        'MEDICAL'          => ['MEDICAL_SUPPORT'],
        'ACCIDENT'         => ['MEDICAL_SUPPORT', 'TRANSPORT'],
        'FLOOD'            => ['TRANSPORT', 'FOOD', 'WATER', 'SHELTER', 'BLANKETS'],
        'FIRE'             => ['TRANSPORT', 'WATER', 'MEDICAL_SUPPORT', 'BLANKETS'],
        'FOOD_WATER'       => ['FOOD', 'WATER'],
        'SHELTER'          => ['SHELTER', 'BLANKETS', 'FOOD', 'WATER'],
        'TRANSPORT'        => ['TRANSPORT'],
        'MISSING_STRANDED' => ['TRANSPORT', 'WATER', 'FOOD'],
        'OTHER'            => [],
    ],
];
