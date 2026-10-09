<?php

declare(strict_types=1);

/**
 * Validation rule sets.
 *
 * These mirror the data validation rules in DATABASE.md §27 and are consumed
 * by controllers via the `validator()` helper, e.g.:
 *
 *   $v = validator($request->all(), config('validation.emergency_request'));
 *
 * Field labels improve user-facing messages.
 */
return [
    'user' => [
        'rules' => [
            'name'     => 'required|string|min:2|max:120',
            'email'    => 'required|email|max:190',
            'phone'    => 'nullable|string|max:20',
            'password' => 'required|string|min:8',
            'role'     => 'required|in:CITIZEN,VOLUNTEER,ORGANIZATION',
        ],
        'labels' => [
            'name'     => 'Full name',
            'email'    => 'Email address',
            'password' => 'Password',
            'role'     => 'Role',
        ],
    ],

    'login' => [
        'rules' => [
            'email'    => 'required|email',
            'password' => 'required|string',
        ],
        'labels' => [
            'email'    => 'Email address',
            'password' => 'Password',
        ],
    ],

    'emergency_request' => [
        'rules' => [
            'category'        => 'required|in:MEDICAL,ACCIDENT,FLOOD,FIRE,FOOD_WATER,SHELTER,TRANSPORT,MISSING_STRANDED,OTHER',
            'description'     => 'required|string|min:10|max:5000',
            'people_affected' => 'required|integer|min:1|max:100000',
            'latitude'                => 'nullable|numeric|between:-90,90',
            'longitude'               => 'nullable|numeric|between:-180,180',
            'location_accuracy_m'     => 'nullable|numeric|min:0|max:100000',
            'address_text'            => 'nullable|string|max:500',
        ],
        'labels' => [
            'category'        => 'Emergency category',
            'description'     => 'Description',
            'people_affected' => 'People affected',
            'latitude'        => 'Latitude',
            'longitude'       => 'Longitude',
            'address_text'    => 'Address',
        ],
    ],

    'volunteer_profile' => [
        'rules' => [
            'skills'            => 'nullable|string|max:1000',
            'vehicle'           => 'nullable|string|max:100',
            'equipment'         => 'nullable|string|max:1000',
            'availability'      => 'nullable|in:AVAILABLE,BUSY,OFFLINE',
            'service_radius_km' => 'nullable|numeric|min:0.1|max:1000',
            'latitude'          => 'nullable|numeric|between:-90,90',
            'longitude'         => 'nullable|numeric|between:-180,180',
        ],
        'labels' => [
            'availability'      => 'Availability',
            'service_radius_km' => 'Service radius (km)',
        ],
    ],

    'volunteer_availability' => [
        'rules' => [
            'availability' => 'required|in:AVAILABLE,BUSY,OFFLINE',
        ],
        'labels' => [
            'availability' => 'Availability',
        ],
    ],

    'organization' => [
        'rules' => [
            'name'         => 'required|string|min:2|max:180',
            'type'         => 'required|in:NGO,HOSPITAL,SHELTER,FOOD_PROVIDER,COMMUNITY_GROUP,OTHER',
            'contact'      => 'nullable|string|max:100',
            'description'  => 'nullable|string|max:2000',
            'address_text' => 'nullable|string|max:500',
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
        ],
        'labels' => [
            'name'         => 'Organization name',
            'type'         => 'Organization type',
            'contact'      => 'Contact number',
            'description'  => 'Description',
            'address_text' => 'Address',
        ],
    ],

    // Partial (PATCH / edit form) organization writes: every field optional,
    // but `name` and `type` are still rejected when explicitly cleared.
    'organization_update' => [
        'rules' => [
            'name'         => 'nullable|string|min:2|max:180',
            'type'         => 'nullable|in:NGO,HOSPITAL,SHELTER,FOOD_PROVIDER,COMMUNITY_GROUP,OTHER',
            'contact'      => 'nullable|string|max:100',
            'description'  => 'nullable|string|max:2000',
            'address_text' => 'nullable|string|max:500',
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
        ],
        'labels' => [
            'name'         => 'Organization name',
            'type'         => 'Organization type',
            'contact'      => 'Contact number',
            'description'  => 'Description',
            'address_text' => 'Address',
        ],
    ],

    'resource' => [
        'rules' => [
            'resource_type'      => 'required|in:FOOD,WATER,SHELTER,TRANSPORT,MEDICAL_SUPPORT,BLANKETS,OTHER',
            'resource_name'      => 'required|string|min:2|max:150',
            'quantity'           => 'required|integer|min:0',
            'available_quantity' => 'required|integer|min:0',
            'unit'               => 'nullable|string|max:50',
            'latitude'           => 'nullable|numeric|between:-90,90',
            'longitude'          => 'nullable|numeric|between:-180,180',
        ],
        'labels' => [
            'resource_type'      => 'Resource type',
            'resource_name'      => 'Resource name',
            'quantity'           => 'Total quantity',
            'available_quantity' => 'Available quantity',
            'unit'               => 'Unit',
        ],
    ],

    // Partial (PATCH / edit form) resource writes: every field optional, but
    // still bounded by the same constraints as a full resource payload.
    'resource_update' => [
        'rules' => [
            'resource_type'      => 'nullable|in:FOOD,WATER,SHELTER,TRANSPORT,MEDICAL_SUPPORT,BLANKETS,OTHER',
            'resource_name'      => 'nullable|string|min:2|max:150',
            'quantity'           => 'nullable|integer|min:0',
            'available_quantity' => 'nullable|integer|min:0',
            'unit'               => 'nullable|string|max:50',
            'status'             => 'nullable|in:AVAILABLE,LIMITED,UNAVAILABLE',
            'latitude'           => 'nullable|numeric|between:-90,90',
            'longitude'          => 'nullable|numeric|between:-180,180',
        ],
        'labels' => [
            'resource_type'      => 'Resource type',
            'resource_name'      => 'Resource name',
            'quantity'           => 'Total quantity',
            'available_quantity' => 'Available quantity',
            'unit'               => 'Unit',
            'status'             => 'Status',
        ],
    ],

    'resource_allocation' => [
        'rules' => [
            'request_id' => 'required|integer|min:1',
            'quantity'   => 'required|integer|min:1',
        ],
        'labels' => [
            'request_id' => 'Emergency request',
            'quantity'   => 'Quantity to allocate',
        ],
    ],
];
