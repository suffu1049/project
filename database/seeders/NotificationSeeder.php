<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\NotificationRepository;

/**
 * Seeds in-app notifications so dashboards are meaningful during the demo.
 */
final class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $admin     = self::userId('admin@resqlink.test');
        $citizen1  = self::userId('citizen1@resqlink.test');
        $citizen2  = self::userId('citizen2@resqlink.test');
        $citizen4  = self::userId('citizen4@resqlink.test');
        $volunteer2 = self::userId('volunteer2@resqlink.test');
        $volunteer3 = self::userId('volunteer3@resqlink.test');

        NotificationRepository::notify($citizen1, 'MATCH_FOUND', 'Help is on the way', 'A nearby volunteer matched with your medical emergency.', 'emergency_request', 1);
        NotificationRepository::notify($citizen2, 'MATCH_ACCEPTED', 'Volunteer accepted', 'A volunteer accepted your flood assistance request.', 'emergency_request', 2);
        NotificationRepository::notify($citizen4, 'STATUS_CHANGED', 'Assistance in progress', 'Your transport request is now in progress.', 'emergency_request', 4);
        NotificationRepository::notify($volunteer2, 'MATCH_FOUND', 'New emergency near you', 'A medical emergency matches your skills and service radius.', 'emergency_request', 1);
        NotificationRepository::notify($volunteer3, 'MATCH_ACCEPTED', 'Assignment confirmed', 'You have been assigned to a transport request.', 'emergency_request', 4);
        NotificationRepository::notify($admin, 'REQUEST_CREATED', 'New critical request', 'A CRITICAL medical emergency was just created.', 'emergency_request', 1);

        $this->say('  notifications: 6');
    }
}
