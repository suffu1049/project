<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\UserRepository;
use App\Models\VolunteerRepository;

/**
 * Seeds 10 volunteers (accounts + profiles) with varied skills, vehicles,
 * availability, service radius, verification and location.
 *
 * Skills/equipment are stored as comma-separated tokens, e.g. "FIRST_AID,DRIVING".
 */
final class VolunteerSeeder extends Seeder
{
    /** @var array<int, array<string, mixed>> */
    public const VOLUNTEERS = [
        ['name' => 'Vikram Rao',     'email' => 'volunteer1@resqlink.test',  'skills' => 'FIRST_AID,DRIVING',              'vehicle' => 'CAR',     'equipment' => 'FIRST_AID_KIT',        'availability' => 'AVAILABLE', 'radius' => 10.0, 'verify' => 'VERIFIED', 'lat' => 20.38810000, 'lng' => 72.90900000],
        ['name' => 'Neha Joshi',     'email' => 'volunteer2@resqlink.test',  'skills' => 'MEDICAL,FIRST_AID',              'vehicle' => 'NONE',    'equipment' => 'MEDICAL_KIT',          'availability' => 'AVAILABLE', 'radius' => 8.0,  'verify' => 'VERIFIED', 'lat' => 20.38530000, 'lng' => 72.91210000],
        ['name' => 'Sameer Khan',    'email' => 'volunteer3@resqlink.test',  'skills' => 'TRANSPORT,DRIVING',              'vehicle' => 'VAN',     'equipment' => 'NONE',                 'availability' => 'AVAILABLE', 'radius' => 20.0, 'verify' => 'VERIFIED', 'lat' => 20.39120000, 'lng' => 72.90450000],
        ['name' => 'Ananya Iyer',    'email' => 'volunteer4@resqlink.test',  'skills' => 'FOOD_DISTRIBUTION',              'vehicle' => 'BIKE',    'equipment' => 'NONE',                 'availability' => 'BUSY',      'radius' => 6.0,  'verify' => 'VERIFIED', 'lat' => 20.38390000, 'lng' => 72.91670000],
        ['name' => 'Karan Patel',    'email' => 'volunteer5@resqlink.test',  'skills' => 'SEARCH_SUPPORT,TRANSPORT',       'vehicle' => 'CAR',     'equipment' => 'TORCH,ROPE',           'availability' => 'AVAILABLE', 'radius' => 15.0, 'verify' => 'VERIFIED', 'lat' => 20.37980000, 'lng' => 72.90780000],
        ['name' => 'Meera Shah',     'email' => 'volunteer6@resqlink.test',  'skills' => 'FIRST_AID,FOOD_DISTRIBUTION',    'vehicle' => 'NONE',    'equipment' => 'FIRST_AID_KIT',        'availability' => 'AVAILABLE', 'radius' => 5.0,  'verify' => 'VERIFIED', 'lat' => 20.39360000, 'lng' => 72.91890000],
        ['name' => 'Aditya Kulkarni','email' => 'volunteer7@resqlink.test',  'skills' => 'GENERAL,DRIVING',               'vehicle' => 'BIKE',    'equipment' => 'NONE',                 'availability' => 'OFFLINE',   'radius' => 12.0, 'verify' => 'PENDING',  'lat' => 20.38770000, 'lng' => 72.90120000],
        ['name' => 'Fatima Ansari',  'email' => 'volunteer8@resqlink.test',  'skills' => 'MEDICAL,FIRST_AID,TRANSPORT',    'vehicle' => 'AMBULANCE','equipment' => 'MEDICAL_KIT,OXYGEN',   'availability' => 'AVAILABLE', 'radius' => 25.0, 'verify' => 'VERIFIED', 'lat' => 20.38410000, 'lng' => 72.92330000],
        ['name' => 'Nikhil Verma',   'email' => 'volunteer9@resqlink.test',  'skills' => 'FOOD_DISTRIBUTION,GENERAL',      'vehicle' => 'VAN',     'equipment' => 'NONE',                 'availability' => 'BUSY',      'radius' => 10.0, 'verify' => 'VERIFIED', 'lat' => 20.38150000, 'lng' => 72.90880000],
        ['name' => 'Riya Sen',       'email' => 'volunteer10@resqlink.test', 'skills' => 'SEARCH_SUPPORT,FIRST_AID',       'vehicle' => 'NONE',    'equipment' => 'FIRST_AID_KIT,ROPE',   'availability' => 'OFFLINE',   'radius' => 7.0,  'verify' => 'PENDING',  'lat' => 20.39040000, 'lng' => 72.91560000],
    ];

    public function run(): void
    {
        $hash = self::hash(self::DEMO_PASSWORD);

        foreach (self::VOLUNTEERS as $volunteer) {
            $userId = UserRepository::create([
                'name'          => $volunteer['name'],
                'email'         => $volunteer['email'],
                'phone'         => null,
                'password_hash' => $hash,
                'role'          => 'VOLUNTEER',
                'status'        => 'ACTIVE',
            ]);

            VolunteerRepository::create([
                'user_id'             => $userId,
                'skills'              => $volunteer['skills'],
                'vehicle'             => $volunteer['vehicle'],
                'equipment'           => $volunteer['equipment'],
                'availability'        => $volunteer['availability'],
                'service_radius_km'   => $volunteer['radius'],
                'latitude'            => $volunteer['lat'],
                'longitude'           => $volunteer['lng'],
                'verification_status' => $volunteer['verify'],
            ]);
        }

        $this->say('  volunteers: 10 accounts + 10 profiles');
    }
}
