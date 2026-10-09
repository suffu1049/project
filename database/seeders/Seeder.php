<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;
use App\Models\OrganizationRepository;
use App\Models\UserRepository;
use App\Models\VolunteerRepository;

/**
 * Base seeder.
 *
 * Seeders use the same repositories as the application, so seeded rows always
 * satisfy the same constraints the app does. Seed data is clearly fictional
 * (emails use the reserved `.test` TLD).
 */
abstract class Seeder
{
    /** Documented local development password (never use in production). */
    public const DEMO_PASSWORD = 'Password123!';

    abstract public function run(): void;

    /**
     * Truncate the given tables in a foreign-key-safe way.
     *
     * @param array<int, string> $tables
     */
    protected static function truncate(array $tables): void
    {
        $pdo = Database::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            // Table names come from an internal fixed list, not user input.
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Hash a plaintext password for storage.
     */
    protected static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    /**
     * Insert many rows into a table using a single prepared statement.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    protected static function insertMany(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = array_keys($rows[0]);
        $quoted = array_map(static fn (string $c): string => '`' . $c . '`', $columns);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', $quoted),
            implode(', ', $placeholders)
        );

        $statement = Database::pdo()->prepare($sql);

        foreach ($rows as $row) {
            $statement->execute($row);
        }
    }

    protected function say(string $message): void
    {
        echo $message . PHP_EOL;
    }

    /**
     * Resolve a seeded user id by email.
     */
    protected static function userId(string $email): int
    {
        $user = UserRepository::findByEmail($email);

        if ($user === null) {
            throw new \RuntimeException("Seed user not found: {$email}");
        }

        return (int) $user['id'];
    }

    /**
     * Resolve an organization id from its account email.
     */
    protected static function organizationId(string $email): int
    {
        $organization = OrganizationRepository::findByUserId(self::userId($email));

        if ($organization === null) {
            throw new \RuntimeException("Seed organization not found: {$email}");
        }

        return (int) $organization['id'];
    }

    /**
     * Resolve a volunteer profile id from its account email.
     */
    protected static function volunteerProfileId(string $email): int
    {
        $profile = VolunteerRepository::findByUserId(self::userId($email));

        if ($profile === null) {
            throw new \RuntimeException("Seed volunteer profile not found: {$email}");
        }

        return (int) $profile['id'];
    }
}
