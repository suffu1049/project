<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ResourceRepository;

/**
 * Seeds 8 resources across the 3 organizations, covering AVAILABLE, LIMITED
 * and UNAVAILABLE states.
 */
final class ResourceSeeder extends Seeder
{
    public function run(): void
    {
        $food    = self::organizationId('org1@resqlink.test');
        $hospital = self::organizationId('org2@resqlink.test');
        $shelter = self::organizationId('org3@resqlink.test');

        $resources = [
            // Community Food Center
            ['org' => $food,     'type' => 'FOOD',              'name' => 'Food Packets',       'qty' => 500, 'avail' => 320, 'unit' => 'packets', 'lat' => 20.39210000, 'lng' => 72.91340000],
            ['org' => $food,     'type' => 'WATER',             'name' => 'Drinking Water',     'qty' => 1000, 'avail' => 800, 'unit' => 'litres',  'lat' => 20.39210000, 'lng' => 72.91340000],
            ['org' => $food,     'type' => 'FOOD',              'name' => 'Ready-to-eat Meals','qty' => 200, 'avail' => 120, 'unit' => 'meals',   'lat' => 20.39210000, 'lng' => 72.91340000],

            // City Care Hospital
            ['org' => $hospital, 'type' => 'MEDICAL_SUPPORT',   'name' => 'First Aid Kits',     'qty' => 100, 'avail' => 60,  'unit' => 'kits',    'lat' => 20.38640000, 'lng' => 72.90520000],
            ['org' => $hospital, 'type' => 'TRANSPORT',         'name' => 'Ambulance Vans',     'qty' => 4,   'avail' => 1,   'unit' => 'vans',    'lat' => 20.38640000, 'lng' => 72.90520000],
            ['org' => $hospital, 'type' => 'MEDICAL_SUPPORT',   'name' => 'Oxygen Cylinders',   'qty' => 40,  'avail' => 0,   'unit' => 'cylinders','lat' => 20.38640000, 'lng' => 72.90520000],

            // Safe Haven Shelter
            ['org' => $shelter,  'type' => 'SHELTER',           'name' => 'Shelter Spaces',     'qty' => 100, 'avail' => 33,  'unit' => 'spaces',  'lat' => 20.38020000, 'lng' => 72.92010000],
            ['org' => $shelter,  'type' => 'BLANKETS',          'name' => 'Relief Blankets',    'qty' => 300, 'avail' => 45,  'unit' => 'blankets','lat' => 20.38020000, 'lng' => 72.92010000],
        ];

        $rows = [];

        foreach ($resources as $resource) {
            $rows[] = [
                'organization_id'    => $resource['org'],
                'resource_type'      => $resource['type'],
                'resource_name'      => $resource['name'],
                'quantity'           => $resource['qty'],
                'available_quantity' => $resource['avail'],
                'unit'               => $resource['unit'],
                'status'             => ResourceRepository::statusForQuantity($resource['avail'], $resource['qty']),
                'latitude'           => $resource['lat'],
                'longitude'          => $resource['lng'],
            ];
        }

        self::insertMany('resources', $rows);
        $this->say('  resources: 8 (available / limited / unavailable)');
    }
}
