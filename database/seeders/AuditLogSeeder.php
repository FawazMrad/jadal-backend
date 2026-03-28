<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Debate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class AuditLogSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $admins  = User::where('role', 'admin')->get();
            $users   = User::all();
            $debates = Debate::all();
            $teams   = Team::all();
            $count   = 0;

            $ipPool = ['192.168.1.1', '10.0.0.5', '172.16.0.3', '192.168.100.50', '10.10.0.1'];

            // User registration logs
            foreach ($users->take(20) as $user) {
                AuditLog::create([
                    'user_id'     => $admins->random()->id,
                    'action'      => 'created',
                    'entity_type' => 'User',
                    'entity_id'   => $user->id,
                    'old_values'  => null,
                    'new_values'  => ['name' => $user->name, 'role' => $user->role, 'status' => 'active'],
                    'ip_address'  => fake()->randomElement($ipPool),
                    'created_at'  => $user->created_at ?? now(),
                ]);
                $count++;
            }

            // Status change logs
            foreach ($users->where('status', '!=', 'active')->take(5) as $user) {
                AuditLog::create([
                    'user_id'     => $admins->random()->id,
                    'action'      => 'status_changed',
                    'entity_type' => 'User',
                    'entity_id'   => $user->id,
                    'old_values'  => ['status' => 'active'],
                    'new_values'  => ['status' => $user->status],
                    'ip_address'  => fake()->randomElement($ipPool),
                    'created_at'  => now()->subDays(rand(1, 30)),
                ]);
                $count++;
            }

            // Debate creation logs
            foreach ($debates as $debate) {
                AuditLog::create([
                    'user_id'     => $debate->created_by,
                    'action'      => 'created',
                    'entity_type' => 'Debate',
                    'entity_id'   => $debate->id,
                    'old_values'  => null,
                    'new_values'  => ['title' => $debate->title, 'status' => $debate->status],
                    'ip_address'  => fake()->randomElement($ipPool),
                    'created_at'  => $debate->created_at ?? now(),
                ]);
                $count++;
            }

            // Debate status change logs
            foreach ($debates->whereIn('status', ['completed', 'cancelled', 'live']) as $debate) {
                AuditLog::create([
                    'user_id'     => $admins->random()->id,
                    'action'      => 'status_changed',
                    'entity_type' => 'Debate',
                    'entity_id'   => $debate->id,
                    'old_values'  => ['status' => 'scheduled'],
                    'new_values'  => ['status' => $debate->status],
                    'ip_address'  => fake()->randomElement($ipPool),
                    'created_at'  => now()->subDays(rand(1, 60)),
                ]);
                $count++;
            }

            // Team creation logs
            foreach ($teams as $team) {
                AuditLog::create([
                    'user_id'     => $team->created_by,
                    'action'      => 'created',
                    'entity_type' => 'Team',
                    'entity_id'   => $team->id,
                    'old_values'  => null,
                    'new_values'  => ['name' => $team->name, 'status' => $team->status],
                    'ip_address'  => fake()->randomElement($ipPool),
                    'created_at'  => $team->created_at ?? now(),
                ]);
                $count++;
            }

            // Login logs
            foreach ($users->random(min(20, $users->count())) as $user) {
                AuditLog::create([
                    'user_id'     => $user->id,
                    'action'      => 'login',
                    'entity_type' => 'User',
                    'entity_id'   => $user->id,
                    'old_values'  => null,
                    'new_values'  => ['last_login' => now()->subDays(rand(0, 7))->toDateTimeString()],
                    'ip_address'  => fake()->randomElement($ipPool),
                    'created_at'  => now()->subDays(rand(0, 7)),
                ]);
                $count++;
            }

            $this->command->info("✓ Audit logs seeded: {$count} log entries.");
        } catch (\Throwable $e) {
            $this->command->error('AuditLogSeeder failed: ' . $e->getMessage());
        }
    }
}
