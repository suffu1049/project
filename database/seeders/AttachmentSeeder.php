<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;
use App\Models\AttachmentRepository;

/**
 * Seeds attachment metadata for a couple of requests.
 *
 * Only metadata/paths are stored — no binary content and no real files.
 */
final class AttachmentSeeder extends Seeder
{
    public function run(): void
    {
        $requests = array_values(Database::select('SELECT id, user_id FROM emergency_requests ORDER BY id'));

        if ($requests === []) {
            return;
        }

        $first = $requests[0];
        $count = 0;

        AttachmentRepository::record(
            (int) $first['id'],
            (int) $first['user_id'],
            'emergency-site.jpg',
            'storage/uploads/emergency/demo-emergency-site.jpg',
            'image/jpeg',
            184320
        );
        $count++;

        if (isset($requests[1])) {
            $second = $requests[1];
            AttachmentRepository::record(
                (int) $second['id'],
                (int) $second['user_id'],
                'flood-area.png',
                'storage/uploads/emergency/demo-flood-area.png',
                'image/png',
                262144
            );
            $count++;
        }

        $this->say("  emergency_attachments: {$count} (metadata only)");
    }
}
