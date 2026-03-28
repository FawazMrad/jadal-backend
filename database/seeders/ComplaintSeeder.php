<?php

namespace Database\Seeders;

use App\Models\Complaint;
use App\Models\Debate;
use App\Models\User;
use Illuminate\Database\Seeder;

class ComplaintSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $users   = User::where('role', '!=', 'admin')->get();
            $debates = Debate::whereIn('status', ['completed', 'cancelled'])->get();

            $complaints = [
                [
                    'description' => 'المحكم لم يكن محايداً في تقييمه وأعطى نقاطاً غير عادلة للفريق المنافس.',
                    'status'      => 'resolved',
                    'response'    => 'تمت مراجعة التقييم وثبت أن المحكم اتبع المعايير المعتمدة. نشكرك على تقديم الشكوى.',
                ],
                [
                    'description' => 'تم انتهاك الوقت المخصص للمتحدث وسمح له بالاستمرار لأكثر من دقيقتين إضافيتين.',
                    'status'      => 'dismissed',
                    'response'    => 'بعد مراجعة السجلات، وجدنا أن الوقت الإضافي لم يتجاوز 30 ثانية وهو ضمن الهامش المسموح.',
                ],
                [
                    'description' => 'استخدم أحد المتنافسين أسلوباً غير لائق وهجوم شخصي خلال المناظرة.',
                    'status'      => 'under_review',
                    'response'    => null,
                ],
                [
                    'description' => 'مشاكل تقنية أثرت على أدائي خلال المناظرة ولم يتم منحي وقتاً إضافياً.',
                    'status'      => 'open',
                    'response'    => null,
                ],
                [
                    'description' => 'الموضوع المختار كان منحازاً ولم يعطِ الفريق المعارض فرصة عادلة للدفاع.',
                    'status'      => 'resolved',
                    'response'    => 'تمت مناقشة الموضوع مع لجنة المناظرات وسيُؤخذ هذا بعين الاعتبار في الجلسات القادمة.',
                ],
            ];

            foreach ($complaints as $data) {
                Complaint::create([
                    'filed_by'       => $users->random()->id,
                    'debate_id'      => rand(0, 1) && $debates->isNotEmpty() ? $debates->random()->id : null,
                    'description'    => $data['description'],
                    'status'         => $data['status'],
                    'admin_response' => $data['response'],
                ]);
            }

            $this->command->info('✓ Complaints seeded: ' . count($complaints) . ' complaints.');
        } catch (\Throwable $e) {
            $this->command->error('ComplaintSeeder failed: ' . $e->getMessage());
        }
    }
}
