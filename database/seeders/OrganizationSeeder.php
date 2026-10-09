<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\OrganizationRepository;
use App\Models\UserRepository;

/**
 * Seeds organization accounts (role ORGANIZATION) plus their organizations.
 */
final class OrganizationSeeder extends Seeder
{
    /** @var array<int, array<string, mixed>> */
    public const ORGANIZATIONS = [
        [
            'email'    => 'org1@resqlink.test',
            'user'     => 'Community Food Center',
            'name'     => 'Community Food Center',
            'type'     => 'FOOD_PROVIDER',
            'contact'  => '9000000101',
            'desc'     => 'Neighbourhood food distribution and relief meals.',
            'status'   => 'VERIFIED',
            'lat'      => 20.39210000,
            'lng'      => 72.91340000,
            'address'  => 'Sector 4, Demo City',
        ],
        [
            'email'    => 'org2@resqlink.test',
            'user'     => 'City Care Hospital',
            'name'     => 'City Care Hospital',
            'type'     => 'HOSPITAL',
            'contact'  => '9000000102',
            'desc'     => 'Emergency medical support and first-aid teams.',
            'status'   => 'VERIFIED',
            'lat'      => 20.38640000,
            'lng'      => 72.90520000,
            'address'  => 'Ring Road, Demo City',
        ],
        [
            'email'    => 'org3@resqlink.test',
            'user'     => 'Safe Haven Shelter',
            'name'     => 'Safe Haven Shelter',
            'type'     => 'SHELTER',
            'contact'  => '9000000103',
            'desc'     => 'Temporary shelter and blankets for displaced families.',
            'status'   => 'PENDING',
            'lat'      => 20.38020000,
            'lng'      => 72.92010000,
            'address'  => 'Old Market, Demo City',
        ],
    ];

    public function run(): void
    {
        $hash = self::hash(self::DEMO_PASSWORD);

        foreach (self::ORGANIZATIONS as $org) {
            $userId = UserRepository::create([
                'name'          => $org['user'],
                'email'         => $org['email'],
                'phone'         => $org['contact'],
                'password_hash' => $hash,
                'role'          => 'ORGANIZATION',
                'status'        => 'ACTIVE',
            ]);

            OrganizationRepository::create([
                'user_id'             => $userId,
                'name'                => $org['name'],
                'type'                => $org['type'],
                'contact'             => $org['contact'],
                'description'         => $org['desc'],
                'verification_status' => $org['status'],
                'latitude'            => $org['lat'],
                'longitude'           => $org['lng'],
                'address_text'        => $org['address'],
            ]);
        }

        $this->say('  organizations: 3');
    }
}
