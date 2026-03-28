<?php

namespace Database\Seeders;

use App\Models\Debate;
use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Database\Seeder;

class EvaluationSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $trainers         = User::where('role', 'trainer')->get();
            $debaters         = User::where('role', 'debater')->get();
            $completedDebates = Debate::where('status', 'completed')->get();
            $count            = 0;

            foreach ($trainers as $trainer) {
                // Each trainer evaluates 3–5 debaters
                $evalDebaters = $debaters->random(min(rand(3, 5), $debaters->count()));

                foreach ($evalDebaters as $debater) {
                    $debate = rand(0, 1) && $completedDebates->isNotEmpty()
                        ? $completedDebates->random()
                        : null;

                    Evaluation::create([
                        'trainer_id' => $trainer->id,
                        'debater_id' => $debater->id,
                        'debate_id'  => $debate?->id,
                        'notes'      => $this->randomEvaluationNote(),
                        'scores'     => [
                            'critical_thinking' => rand(1, 10),
                            'public_speaking'   => rand(1, 10),
                            'research_skills'   => rand(1, 10),
                            'teamwork'          => rand(1, 10),
                            'overall'           => rand(1, 10),
                        ],
                    ]);
                    $count++;
                }
            }

            $this->command->info("✓ Evaluations seeded: {$count} evaluation records.");
        } catch (\Throwable $e) {
            $this->command->error('EvaluationSeeder failed: ' . $e->getMessage());
        }
    }

    private function randomEvaluationNote(): string
    {
        $notes = [
            'المتدرب يُظهر تقدماً ملحوظاً في مهارات التفكير النقدي وبناء الحجة. يُنصح بالتركيز أكثر على مهارات الإلقاء والتواصل مع الجمهور.',
            'أداء جيد بشكل عام. المتدرب لديه قدرة تحليلية جيدة لكن يحتاج إلى تطوير ثقته بنفسه أثناء الحديث العلني.',
            'تحسن واضح منذ بداية البرنامج. ينصح بقراءة المزيد لتوسيع قاعدة المعرفة والاستشهاد بمصادر متنوعة.',
            'The debater shows strong logical reasoning skills. Needs improvement in time management during speeches.',
            'Good understanding of the topic with well-structured arguments. Work on rebuttal techniques and responding under pressure.',
        ];

        return fake()->randomElement($notes);
    }
}
