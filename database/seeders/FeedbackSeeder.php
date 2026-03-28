<?php

namespace Database\Seeders;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Feedback;
use Illuminate\Database\Seeder;

class FeedbackSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $completedDebates = Debate::where('status', 'completed')->get();
            $count            = 0;

            foreach ($completedDebates as $debate) {
                $participants = DebateParticipant::where('debate_id', $debate->id)
                    ->where('status', 'approved')
                    ->get();

                $judges   = $participants->where('role', 'judge');
                $trainers = $participants->where('role', 'trainer');
                $debaters = $participants->where('role', 'debater');

                // Judges give feedback to each debater
                foreach ($judges as $judge) {
                    foreach ($debaters->take(3) as $debater) {
                        Feedback::create([
                            'debate_id'    => $debate->id,
                            'from_user_id' => $judge->user_id,
                            'to_user_id'   => $debater->user_id,
                            'type'         => 'judge_to_debater',
                            'content'      => $this->randomFeedbackContent('judge'),
                            'scores'       => [
                                'argumentation' => rand(1, 10),
                                'delivery'      => rand(1, 10),
                                'evidence'      => rand(1, 10),
                                'rebuttal'      => rand(1, 10),
                            ],
                        ]);
                        $count++;
                    }
                }

                // Trainers give feedback to debaters
                foreach ($trainers as $trainer) {
                    foreach ($debaters->take(2) as $debater) {
                        Feedback::create([
                            'debate_id'    => $debate->id,
                            'from_user_id' => $trainer->user_id,
                            'to_user_id'   => $debater->user_id,
                            'type'         => 'trainer_to_debater',
                            'content'      => $this->randomFeedbackContent('trainer'),
                            'scores'       => [
                                'critical_thinking' => rand(1, 10),
                                'public_speaking'   => rand(1, 10),
                                'teamwork'          => rand(1, 10),
                            ],
                        ]);
                        $count++;
                    }
                }

                // Debaters give session feedback
                foreach ($debaters->take(2) as $debater) {
                    Feedback::create([
                        'debate_id'    => $debate->id,
                        'from_user_id' => $debater->user_id,
                        'to_user_id'   => null,
                        'type'         => 'debater_on_session',
                        'content'      => $this->randomFeedbackContent('session'),
                        'scores'       => null,
                    ]);
                    $count++;
                }
            }

            $this->command->info("✓ Feedbacks seeded: {$count} feedback records.");
        } catch (\Throwable $e) {
            $this->command->error('FeedbackSeeder failed: ' . $e->getMessage());
        }
    }

    private function randomFeedbackContent(string $type): string
    {
        $contents = [
            'judge' => [
                'قدمت حججاً قوية ومنظمة، وكان أسلوبك في الإقناع ممتازاً. تحتاج إلى تطوير ردودك على حجج الخصم.',
                'أداؤك كان جيداً في العموم. كانت حججك واضحة لكن تحتاج إلى دعم أقوى بالأدلة.',
                'عرضت موقفك بوضوح وثقة. اعمل على تحسين مهارة الإنصات والرد الفوري.',
            ],
            'trainer' => [
                'أرى تحسناً ملحوظاً في مهاراتك منذ آخر مناظرة. تابع العمل على بناء الحجج المنطقية.',
                'كانت مداخلاتك في الوقت المناسب. ركز أكثر على التواصل البصري مع الجمهور.',
                'أظهرت فهماً جيداً للموضوع. في المرة القادمة حاول استخدام إحصائيات ودراسات داعمة.',
            ],
            'session' => [
                'كانت المناظرة ممتازة وتنظيمها رائع. أتمنى المزيد من هذه الجلسات.',
                'استفدت كثيراً من هذه التجربة. الجو التنافسي كان محفزاً وبناءً.',
                'تجربة رائعة، القضاة كانوا منصفين وتقييماتهم مفيدة جداً.',
            ],
        ];

        return fake()->randomElement($contents[$type]);
    }
}
