<?php

namespace Database\Seeders;

use App\Models\Debate;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $faker   = \Faker\Factory::create();
            $users   = User::all();
            $debates = Debate::all();
            $count   = 0;

            $notifTemplates = [
                [
                    'type'  => 'debate_scheduled',
                    'title' => 'تمت جدولة مناظرة جديدة',
                    'body'  => 'تم جدولة مناظرة جديدة بعنوان "%s". انضم الآن لتأكيد مشاركتك.',
                ],
                [
                    'type'  => 'debate_result',
                    'title' => 'نتيجة المناظرة',
                    'body'  => 'تم الإعلان عن نتيجة مناظرة "%s". اطلع على التفاصيل.',
                ],
                [
                    'type'  => 'feedback_received',
                    'title' => 'تلقيت تقييماً جديداً',
                    'body'  => 'قام المحكم بتقييم أدائك في المناظرة الأخيرة. اطلع على التغذية الراجعة.',
                ],
                [
                    'type'  => 'team_invitation',
                    'title' => 'دعوة للانضمام إلى فريق',
                    'body'  => 'تلقيت دعوة للانضمام إلى فريق جديد. راجع تفاصيل الدعوة.',
                ],
                [
                    'type'  => 'survey_available',
                    'title' => 'استبيان جديد متاح',
                    'body'  => 'يوجد استبيان جديد بانتظار إجابتك. مشاركتك مهمة لتطوير البرنامج.',
                ],
            ];

            foreach ($users as $user) {
                // 2–5 notifications per user
                $notifCount = rand(2, 5);
                for ($i = 0; $i < $notifCount; $i++) {
                    $template = $faker->randomElement($notifTemplates);
                    $debate   = $debates->isNotEmpty() ? $debates->random() : null;
                    $body     = str_contains($template['body'], '%s') && $debate
                        ? sprintf($template['body'], $debate->title)
                        : $template['body'];

                    Notification::create([
                        'user_id'    => $user->id,
                        'type'       => $template['type'],
                        'title'      => $template['title'],
                        'body'       => $body,
                        'data'       => $debate ? ['debate_id' => $debate->id] : null,
                        'read_at'    => rand(0, 1) ? now()->subDays(rand(0, 30)) : null,
                        'created_at' => now()->subDays(rand(0, 60)),
                    ]);
                    $count++;
                }
            }

            $this->command->info("✓ Notifications seeded: {$count} notifications.");
        } catch (\Throwable $e) {
            $this->command->error('NotificationSeeder failed: ' . $e->getMessage());
        }
    }
}
