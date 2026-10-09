<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Repository;

/**
 * Persistence for the `emergency_attachments` table.
 *
 * Only metadata and the storage path are stored — never binary content
 * (files live in storage/uploads, outside the web root).
 */
final class AttachmentRepository extends Repository
{
    protected static string $table = 'emergency_attachments';

    /** @return array<int, array> */
    public static function forRequest(int $requestId): array
    {
        return Database::select(
            'SELECT * FROM emergency_attachments WHERE request_id = :request_id ORDER BY created_at ASC',
            ['request_id' => $requestId]
        );
    }

    /**
     * Record an uploaded file's metadata.
     */
    public static function record(
        int $requestId,
        int $uploadedBy,
        string $fileName,
        string $storagePath,
        string $mimeType,
        int $fileSize
    ): int {
        return self::create([
            'request_id'   => $requestId,
            'uploaded_by'  => $uploadedBy,
            'file_name'    => $fileName,
            'storage_path' => $storagePath,
            'mime_type'    => $mimeType,
            'file_size'    => $fileSize,
        ]);
    }
}
