<?php

namespace Database\Seeders;

use App\Models\Debate;
use App\Models\DebateResult;
use App\Models\DebateParticipant;
use Illuminate\Database\Seeder;

class DebateResultSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $completedDebates = Debate::where('status', 'completed')->get();
            $count            = 0;

            foreach ($completedDebates as $debate) {
                if (DebateResult::where('debate_id', $debate->id)->exists()) {
                    continue;
                }

                // Find the chair judge for this debate
                $chairParticipant = DebateParticipant::where('debate_id', $debate->id)
                    ->where('role', 'judge')
                    ->where('is_chair', true)
                    ->first();

                // Fallback to any judge
                $judgeParticipant = $chairParticipant ?? DebateParticipant::where('debate_id', $debate->id)
                    ->where('role', 'judge')
                    ->first();

                if (! $judgeParticipant) {
                    continue;
                }

                $winningSide = fake()->randomElement(['proposition', 'opposition', 'draw']);

                DebateResult::create([
                    'debate_id'    => $debate->id,
                    'judge_id'     => $judgeParticipant->user_id,
                    'winning_side' => $winningSide,
                    'scores'       => [
                        'proposition' => [
                            'content'  => rand(60, 100),
                            'delivery' => rand(60, 100),
                            'strategy' => rand(60, 100),
                        ],
                        'opposition' => [
                            'content'  => rand(60, 100),
                            'delivery' => rand(60, 100),
                            'strategy' => rand(60, 100),
                        ],
                    ],
                    'summary_notes' => rand(0, 1)
                        ? 'كانت المناظرة متوازنة وقدم الفريقان حججاً قوية. ' . fake()->sentence(15)
                        : null,
                    'submitted_at' => $debate->ended_at ?? now(),
                ]);

                $count++;
            }

            $this->command->info("✓ Debate results seeded: {$count} results for completed debates.");
        } catch (\Throwable $e) {
            $this->command->error('DebateResultSeeder failed: ' . $e->getMessage());
        }
    }
}
