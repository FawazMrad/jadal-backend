<?php

namespace Database\Seeders;

use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Database\Seeder;

class SurveySeeder extends Seeder
{
    public function run(): void
    {
        try {
            $admins = User::where('role', 'admin')->get();

            if ($admins->isEmpty()) {
                $this->command->error('No admins found. Cannot create surveys.');
                return;
            }

            $admin = $admins->first();
            $surveys = $this->getSurveysData();

            foreach ($surveys as $surveyData) {
                $survey = Survey::create([
                    'created_by'   => $admin->id,
                    'title'        => $surveyData['title'],
                    'description'  => $surveyData['description'] ?? null,
                    'target_roles' => $surveyData['target_roles'],
                    'closes_at'    => now()->addDays(30),
                ]);

                foreach ($surveyData['questions'] as $order => $qData) {
                    SurveyQuestion::create([
                        'survey_id'     => $survey->id,
                        'question_text' => $qData['text'],
                        'type'          => $qData['type'],
                        'options'       => $qData['options'] ?? null,
                        'order_index'   => $order + 1,
                    ]);
                }

                // Seed responses from 60% of eligible users
                $eligibleUsers = User::whereIn('role', $surveyData['target_roles'])->get();

                if ($eligibleUsers->isEmpty()) {
                    $this->command->warn("No eligible users for survey: {$surveyData['title']}");
                    continue;
                }

                $respondentCount = max(1, (int) ($eligibleUsers->count() * 0.6));
                $respondents = $eligibleUsers->random(min($respondentCount, $eligibleUsers->count()));

                foreach ($respondents as $user) {
                    $answers = [];
                    foreach ($surveyData['questions'] as $qIdx => $qData) {
                        $key = 'q' . ($qIdx + 1);
                        $answers[$key] = match ($qData['type']) {
                            'mcq'       => fake()->randomElement($qData['options'] ?? ['موافق', 'محايد', 'غير موافق']),
                            'rating'    => (string) rand(1, 10),
                            'open_text' => fake()->randomElement([
                                'تجربة رائعة ومفيدة جداً.',
                                'البرنامج يحتاج إلى بعض التحسينات.',
                                'أرجو تنظيم المزيد من الفعاليات.',
                                'Great program with excellent trainers.',
                                'Looking forward to more advanced sessions.',
                            ]),
                        };
                    }

                    SurveyResponse::firstOrCreate(
                        ['survey_id' => $survey->id, 'user_id' => $user->id],
                        [
                            'answers'    => $answers,
                            'created_at' => now()->subDays(rand(0, 20)),
                        ]
                    );
                }
            }

            $this->command->info('✓ Surveys seeded: ' . count($surveys) . ' surveys with questions and responses (~60% participation).');
        } catch (\Throwable $e) {
            $this->command->error('SurveySeeder failed: ' . $e->getMessage());
        }
    }

    private function getSurveysData(): array
    {
        return [
            [
                'title'        => 'تقييم تجربة نادي جدل - الفصل الأول',
                'description'  => 'استبيان لتقييم تجربة المتدربين خلال الفصل الأول من برنامج نادي جدل.',
                'target_roles' => ['debater'],
                'questions'    => [
                    [
                        'text'    => 'كيف تقيّم جودة التدريب الذي تلقيته خلال هذا الفصل؟',
                        'type'    => 'rating',
                        'options' => ['min' => 1, 'max' => 10, 'step' => 1],
                    ],
                    [
                        'text'    => 'هل شعرت بتحسن في مهاراتك الخطابية؟',
                        'type'    => 'mcq',
                        'options' => ['نعم، تحسناً كبيراً', 'نعم، تحسناً ملحوظاً', 'تحسن بسيط', 'لم ألاحظ تحسناً'],
                    ],
                    [
                        'text'    => 'ما الجانب الذي استفدت منه أكثر في هذا الفصل؟',
                        'type'    => 'open_text',
                        'options' => null,
                    ],
                    [
                        'text'    => 'هل توصي بالنادي لأصدقائك أو زملائك؟',
                        'type'    => 'mcq',
                        'options' => ['بالتأكيد', 'غالباً', 'ربما', 'لا'],
                    ],
                    [
                        'text'    => 'ما مقترحاتك لتحسين البرنامج؟',
                        'type'    => 'open_text',
                        'options' => null,
                    ],
                ],
            ],
            [
                'title'        => 'Trainer Effectiveness Survey',
                'description'  => 'Help us improve our training team by sharing your honest feedback.',
                'target_roles' => ['debater', 'judge'],
                'questions'    => [
                    [
                        'text'    => 'How well did the trainer explain debate techniques?',
                        'type'    => 'rating',
                        'options' => ['min' => 1, 'max' => 10, 'step' => 1],
                    ],
                    [
                        'text'    => 'Did the trainer provide constructive feedback?',
                        'type'    => 'mcq',
                        'options' => ['Always', 'Usually', 'Sometimes', 'Rarely', 'Never'],
                    ],
                    [
                        'text'    => 'What could the trainer improve in future sessions?',
                        'type'    => 'open_text',
                        'options' => null,
                    ],
                    [
                        'text'    => 'Overall trainer satisfaction score',
                        'type'    => 'rating',
                        'options' => ['min' => 1, 'max' => 10, 'step' => 1],
                    ],
                ],
            ],
            [
                'title'        => 'استبيان تقييم التجربة الرقمية للمنصة',
                'description'  => 'آراؤكم تساعدنا في تطوير منصة جدل الرقمية.',
                'target_roles' => ['debater', 'trainer', 'judge', 'admin'],
                'questions'    => [
                    [
                        'text'    => 'كيف تقيّم سهولة استخدام منصة جدل؟',
                        'type'    => 'rating',
                        'options' => ['min' => 1, 'max' => 10, 'step' => 1],
                    ],
                    [
                        'text'    => 'هل تواجه أي مشاكل تقنية عند استخدام المنصة؟',
                        'type'    => 'mcq',
                        'options' => ['دائماً', 'أحياناً', 'نادراً', 'أبداً'],
                    ],
                    [
                        'text'    => 'ما الميزة التي تتمنى إضافتها إلى المنصة؟',
                        'type'    => 'open_text',
                        'options' => null,
                    ],
                ],
            ],
        ];
    }
}
