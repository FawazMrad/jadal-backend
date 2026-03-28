<?php

namespace Database\Seeders;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DebateParticipantSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $debates  = Debate::all();
            $debaters = User::where('role', 'debater')->get();
            $judges   = User::where('role', 'judge')->get();
            $trainers = User::where('role', 'trainer')->get();
            $teams    = Team::all();

            $seededCount = 0;

            foreach ($debates as $debate) {
                if (in_array($debate->status, ['cancelled'])) {
                    continue;
                }

                $propTeam = $teams->isNotEmpty() ? $teams->random() : null;
                $oppTeam  = $teams->count() > 1 ? $teams->where('id', '!=', $propTeam?->id)->random() : null;

                // 3 proposition debaters
                $propDebaters = $debaters->random(min(3, $debaters->count()));
                foreach ($propDebaters as $order => $debater) {
                    DebateParticipant::firstOrCreate(
                        ['debate_id' => $debate->id, 'user_id' => $debater->id],
                        [
                            'team_id'             => $propTeam?->id,
                            'role'                => 'debater',
                            'side'                => 'proposition',
                            'status'              => 'approved',
                            'is_chair'            => false,
                            'is_attended'         => in_array($debate->status, ['completed', 'live']),
                            'speaking_phase_order' => $order + 1,
                        ]
                    );
                    $seededCount++;
                }

                // 3 opposition debaters
                $remainingDebaters = $debaters->whereNotIn('id', $propDebaters->pluck('id'));
                $oppDebaters = $remainingDebaters->count() >= 3
                    ? $remainingDebaters->random(3)
                    : $debaters->random(min(3, $debaters->count()));

                foreach ($oppDebaters as $order => $debater) {
                    DebateParticipant::firstOrCreate(
                        ['debate_id' => $debate->id, 'user_id' => $debater->id],
                        [
                            'team_id'             => $oppTeam?->id,
                            'role'                => 'debater',
                            'side'                => 'opposition',
                            'status'              => 'approved',
                            'is_chair'            => false,
                            'is_attended'         => in_array($debate->status, ['completed', 'live']),
                            'speaking_phase_order' => $order + 1,
                        ]
                    );
                    $seededCount++;
                }

                // 1–2 judges (first is chair)
                $debateJudges = $judges->random(min(2, $judges->count()));
                foreach ($debateJudges as $jIdx => $judge) {
                    DebateParticipant::firstOrCreate(
                        ['debate_id' => $debate->id, 'user_id' => $judge->id],
                        [
                            'team_id'             => null,
                            'role'                => 'judge',
                            'side'                => 'judge',
                            'status'              => 'approved',
                            'is_chair'            => $jIdx === 0,
                            'is_attended'         => in_array($debate->status, ['completed', 'live']),
                            'speaking_phase_order' => null,
                        ]
                    );
                    $seededCount++;
                }

                // 1 trainer per side
                if ($trainers->isNotEmpty()) {
                    $trainer = $trainers->random();
                    DebateParticipant::firstOrCreate(
                        ['debate_id' => $debate->id, 'user_id' => $trainer->id],
                        [
                            'team_id' => null,
                            'role'    => 'trainer',
                            'side'    => 'trainer',
                            'status'  => 'approved',
                            'is_chair' => false,
                            'is_attended' => true,
                            'speaking_phase_order' => null,
                        ]
                    );
                    $seededCount++;
                }
            }

            $this->command->info("✓ Debate participants seeded: {$seededCount} records.");
        } catch (\Throwable $e) {
            $this->command->error('DebateParticipantSeeder failed: ' . $e->getMessage());
        }
    }
}
