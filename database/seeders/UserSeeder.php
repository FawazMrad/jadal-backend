<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $guardName = config('auth.defaults.guard', 'web');

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

            // Get role with correct guard
            $adminRole = Role::findByName('admin', $guardName);
            $admin->assignRole($adminRole);

            // 4 more admins
            User::factory()->admin()->count(4)->create()->each(function ($u) use ($guardName) {
                $role = Role::findByName('admin', $guardName);
                $u->assignRole($role);
            });

            // 10 trainers
            User::factory()->trainer()->count(10)->create()->each(function ($u) use ($guardName) {
                $role = Role::findByName('trainer', $guardName);
                $u->assignRole($role);
            });

            // 8 judges
            User::factory()->judge()->count(8)->create()->each(function ($u) use ($guardName) {
                $role = Role::findByName('judge', $guardName);
                $u->assignRole($role);
            });

            // 30 debaters
            User::factory()->debater()->count(30)->create()->each(function ($u) use ($guardName) {
                $role = Role::findByName('debater', $guardName);
                $u->assignRole($role);
            });

            $this->command->info('✓ Users seeded: 5 admins, 10 trainers, 8 judges, 30 debaters.');
        } catch (\Throwable $e) {
            $this->command->error('UserSeeder failed: ' . $e->getMessage());
        }
    }
}
