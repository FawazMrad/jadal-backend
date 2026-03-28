<?php

namespace Database\Seeders;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebatePhase;
use Illuminate\Database\Seeder;

class DebatePhaseSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $debates = Debate::whereIn('status', ['live', 'completed'])->with('format')->get();
            $count   = 0;

            foreach ($debates as $debate) {
                $phaseConfig = $debate->format->phase_config ?? [];
                $phases      = $phaseConfig['phases'] ?? $this->defaultPhases();

                foreach ($phases as $index => $phaseData) {
                    $phaseStatus = match ($debate->status) {
                        'completed' => 'completed',
                        'live'      => $index === 0 ? 'active' : ($index < 2 ? 'completed' : 'pending'),
                        default     => 'pending',
                    };

                    $startedAt = null;
                    $endedAt   = null;

                    if (in_array($phaseStatus, ['active', 'completed']) && $debate->started_at) {
                        $offset    = $index * ($phaseData['duration'] ?? 420);
                        $startedAt = (clone $debate->started_at)->modify("+{$offset} seconds");
                    }

                    if ($phaseStatus === 'completed' && $startedAt) {
                        $duration = $phaseData['duration'] ?? 420;
                        $endedAt  = (clone $startedAt)->modify("+{$duration} seconds");
                    }

                    DebatePhase::create([
                        'debate_id'        => $debate->id,
                        'name'             => $phaseData['name'],
                        'order_index'      => $index + 1,
                        'duration_seconds' => $phaseData['duration'] ?? 420,
                        'status'           => $phaseStatus,
                        'started_at'       => $startedAt,
                        'ended_at'         => $endedAt,
                    ]);

                    $count++;
                }
            }

            $this->command->info("✓ Debate phases seeded: {$count} phases across " . $debates->count() . ' debates.');
        } catch (\Throwable $e) {
            $this->command->error('DebatePhaseSeeder failed: ' . $e->getMessage());
        }
    }

    private function defaultPhases(): array
    {
        return [
            ['name' => 'كلمة الافتتاح - المؤيد', 'duration' => 420],
            ['name' => 'كلمة الافتتاح - المعارض', 'duration' => 420],
            ['name' => 'الرد الأول - المؤيد', 'duration' => 300],
            ['name' => 'الرد الأول - المعارض', 'duration' => 300],
            ['name' => 'الخاتمة - المعارض', 'duration' => 240],
            ['name' => 'الخاتمة - المؤيد', 'duration' => 240],
        ];
    }
}
