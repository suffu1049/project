<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Repository;

/**
 * Persistence for the `notifications` table (in-app notifications, Phase 8).
 *
 * Read APIs follow API.md §43–§46: owner-scoped listing with unread filter and
 * pagination, unread count, and IDOR-safe mark-read helpers.
 */
final class NotificationRepository extends Repository
{
    protected static string $table = 'notifications';

    /**
     * Notifications for a user, newest first.
     *
     * @return array<int, array>
     */
    public static function forUser(int $userId, bool $unreadOnly = false, int $page = 1, int $limit = 30): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $offset = ($page - 1) * $limit;

        $sql = 'SELECT * FROM notifications WHERE user_id = :user_id';
        $bindings = ['user_id' => $userId];

        if ($unreadOnly) {
            $sql .= ' AND read_status = 0';
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        return Database::select($sql, $bindings);
    }

    /**
     * Total notifications for a user (optionally unread only).
     */
    public static function countForUser(int $userId, bool $unreadOnly = false): int
    {
        $sql = 'SELECT COUNT(*) AS aggregate FROM notifications WHERE user_id = :user_id';
        $bindings = ['user_id' => $userId];

        if ($unreadOnly) {
            $sql .= ' AND read_status = 0';
        }

        $row = Database::selectOne($sql, $bindings);

        return (int) ($row['aggregate'] ?? 0);
    }

    public static function unreadCount(int $userId): int
    {
        return self::countForUser($userId, true);
    }

    /**
     * Mark a single notification as read, scoped to its owner (prevents IDOR).
     */
    public static function markRead(int $notificationId, int $userId): int
    {
        return Database::execute(
            'UPDATE notifications SET read_status = 1 WHERE id = :id AND user_id = :user_id AND read_status = 0',
            ['id' => $notificationId, 'user_id' => $userId]
        );
    }

    public static function markAllRead(int $userId): int
    {
        return Database::execute(
            'UPDATE notifications SET read_status = 1 WHERE user_id = :user_id AND read_status = 0',
            ['user_id' => $userId]
        );
    }

    /**
     * Create a notification. Returns the new id.
     */
    public static function notify(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $entityType = null,
        ?int $entityId = null
    ): int {
        return self::create([
            'user_id'             => $userId,
            'type'                => $type,
            'title'               => $title,
            'message'             => $message,
            'related_entity_type' => $entityType,
            'related_entity_id'   => $entityId,
            'read_status'         => 0,
        ]);
    }
}
