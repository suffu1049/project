<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Seeds the administrator and citizen accounts.
 *
 * All data is fictional. Emails use the reserved `.test` TLD.
 */
final class UsersSeeder extends Seeder
{
    /** @var array<int, array{name:string,email:string,phone:string}> */
    public const CITIZENS = [
        ['name' => 'Aarav Mehta',    'email' => 'citizen1@resqlink.test', 'phone' => '9000000001'],
        ['name' => 'Priya Nair',     'email' => 'citizen2@resqlink.test', 'phone' => '9000000002'],
        ['name' => 'Rohan Desai',    'email' => 'citizen3@resqlink.test', 'phone' => '9000000003'],
        ['name' => 'Sneha Kulkarni', 'email' => 'citizen4@resqlink.test', 'phone' => '9000000004'],
        ['name' => 'Imran Sheikh',   'email' => 'citizen5@resqlink.test', 'phone' => '9000000005'],
    ];

    public function run(): void
    {
        $hash = self::hash(self::DEMO_PASSWORD);

        self::insertMany('users', [
            [
                'name'          => 'Control Center Admin',
                'email'         => 'admin@resqlink.test',
                'phone'         => '9000000000',
                'password_hash' => $hash,
                'role'          => 'ADMIN',
                'status'        => 'ACTIVE',
            ],
        ]);

        $rows = [];

        foreach (self::CITIZENS as $citizen) {
            $rows[] = [
                'name'          => $citizen['name'],
                'email'         => $citizen['email'],
                'phone'         => $citizen['phone'],
                'password_hash' => $hash,
                'role'          => 'CITIZEN',
                'status'        => 'ACTIVE',
            ];
        }

        self::insertMany('users', $rows);
        $this->say('  users: 1 admin + 5 citizens');
    }
}
