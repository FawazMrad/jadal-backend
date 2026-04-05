<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $debaters = User::where('role', 'debater')->get();
            $admins   = User::where('role', 'admin')->get();

            // CRITICAL: Check if we have enough users
            if ($debaters->isEmpty()) {
                $this->command->error('No debaters found. Cannot create teams.');
                return;
            }

            if ($admins->isEmpty()) {
                $this->command->error('No admins found. Cannot create teams.');
                return;
            }

            // Need at least 2 debaters per team (1 leader + 1 member minimum)
            if ($debaters->count() < 2) {
                $this->command->error("Need at least 2 debaters, found {$debaters->count()}. Cannot create teams.");
                return;
            }

            $teamNames = [
                'فريق الفجر', 'فريق النسر', 'فريق الصقر', 'فريق البرق',
                'فريق الأمل', 'فريق الريادة', 'فريق القمة', 'فريق التحدي',
                'Team Phoenix', 'Team Vanguard',
            ];

            foreach ($teamNames as $index => $name) {
                $leader  = $debaters->random();
                $creator = $admins->random();

                $team = Team::create([
                    'name'       => $name,
                    'leader_id'  => $leader->id,
                    'created_by' => $creator->id,
                    'status'     => $index < 8 ? 'active' : 'inactive',
                    'is_random'  => false, // lowercase false
                ]);

                // Add 3–5 members
                $memberCount = rand(3, 5);

                // Get available debaters (excluding leader)
                $availableDebaters = $debaters->where('id', '!=', $leader->id);

                if ($availableDebaters->isEmpty()) {
                    $this->command->warn("Not enough debaters for team {$name}, skipping members.");
                    continue;
                }

                // Calculate how many we can actually pick
                $needed = min($memberCount - 1, $availableDebaters->count());
                $teamDebaters = $availableDebaters->random($needed);

                // Leader is always member at priority 1
                TeamMember::create([
                    'team_id'  => $team->id,
                    'user_id'  => $leader->id,
                    'priority' => 1,
                    'status'   => 'current',
                ]);

                $priority = 2;
                foreach ($teamDebaters as $debater) {
                    TeamMember::firstOrCreate(
                        ['team_id' => $team->id, 'user_id' => $debater->id],
                        ['priority' => $priority++, 'status' => 'current']
                    );
                }
            }

            $this->command->info('✓ Teams seeded: ' . count($teamNames) . ' teams with 3–5 members each.');
        } catch (\Throwable $e) {
            $this->command->error('TeamSeeder failed: ' . $e->getMessage());
        }
    }
}
