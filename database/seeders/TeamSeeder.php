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
                ]);

                // Add 3–5 members
                $memberCount   = rand(3, 5);
                $teamDebaters  = $debaters->where('id', '!=', $leader->id)->random(min($memberCount - 1, $debaters->count() - 1));

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
