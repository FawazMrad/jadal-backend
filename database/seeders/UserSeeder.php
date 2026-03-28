<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        try {
            // Fixed admin account
            $admin = User::firstOrCreate(
                ['email' => 'admin@jadal.sa'],
                [
                    'name'              => 'مدير النظام',
                    'password'          => bcrypt('password'),
                    'role'              => 'admin',
                    'status'            => 'active',
                    'points'            => 0,
                    'email_verified_at' => now(),
                ]
            );
            $admin->assignRole('admin');

            // 4 more admins
            User::factory()->admin()->count(4)->create()->each(fn ($u) => $u->assignRole('admin'));

            // 10 trainers
            User::factory()->trainer()->count(10)->create()->each(fn ($u) => $u->assignRole('trainer'));

            // 8 judges
            User::factory()->judge()->count(8)->create()->each(fn ($u) => $u->assignRole('judge'));

            // 30 debaters
            User::factory()->debater()->count(30)->create()->each(fn ($u) => $u->assignRole('debater'));

            $this->command->info('✓ Users seeded: 5 admins, 10 trainers, 8 judges, 30 debaters.');
        } catch (\Throwable $e) {
            $this->command->error('UserSeeder failed: ' . $e->getMessage());
        }
    }
}
