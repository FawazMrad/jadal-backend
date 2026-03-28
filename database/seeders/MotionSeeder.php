<?php

namespace Database\Seeders;

use App\Models\Motion;
use App\Models\MotionFramework;
use App\Models\User;
use Illuminate\Database\Seeder;

class MotionSeeder extends Seeder
{
    private array $motionTexts = [
        'This house believes that social media does more harm than good',
        'هذا البيت يؤمن بأن التعليم عن بعد أفضل من التعليم التقليدي',
        'This house would make voting mandatory in all democracies',
        'هذا البيت يؤيد حظر وسائل التواصل الاجتماعي للأطفال دون الثالثة عشرة',
        'This house believes that artificial intelligence poses an existential threat to humanity',
        'هذا البيت يرى أن الرياضة الاحترافية تعزز القيم الإيجابية في المجتمع',
        'This house would ban fossil fuels by 2035',
        'هذا البيت يؤمن بأن المرأة يجب أن تتولى نصف المناصب القيادية',
        'This house believes that economic growth should be prioritized over environmental protection',
        'هذا البيت يرى أن الجامعات يجب أن تلغي نظام التقديرات',
        'This house would legalize the recreational use of cannabis',
        'هذا البيت يؤيد إلزامية الخدمة المدنية للشباب',
        'This house believes that free trade does more harm than good to developing nations',
        'هذا البيت يرى أن وسائل الإعلام تشكل خطراً على الديمقراطية',
        'This house would abolish nuclear weapons globally',
        'هذا البيت يؤمن بأن الذكاء الاصطناعي سيحل محل معظم الوظائف البشرية',
        'This house believes that space exploration should be privatized',
        'هذا البيت يرى أن الاندماج الثقافي يقوي المجتمعات ولا يضعفها',
        'This house would implement a universal basic income',
        'هذا البيت يؤيد فرض ضريبة تصاعدية على الثروات الطائلة',
        'This house believes that democracy is the best form of governance for all societies',
        'هذا البيت يرى أن الرياضة الإلكترونية رياضة حقيقية تستحق الاعتراف الرسمي',
        'This house would ban advertising directed at children under 12',
        'هذا البيت يؤمن بأن التنوع الثقافي يعزز الابتكار والإبداع',
        'This house believes that religion should have no place in public policy',
    ];

    public function run(): void
    {
        try {
            $admins     = User::where('role', 'admin')->pluck('id');
            $trainers   = User::where('role', 'trainer')->pluck('id');
            $adders     = $admins->merge($trainers);
            $frameworks = MotionFramework::all();

            foreach ($this->motionTexts as $text) {
                $motion = Motion::firstOrCreate(
                    ['text' => $text],
                    ['added_by' => $adders->random()]
                );

                // Attach 1–3 random frameworks
                if ($frameworks->isNotEmpty()) {
                    $motion->frameworks()->sync(
                        $frameworks->random(min(rand(1, 3), $frameworks->count()))->pluck('id')->toArray()
                    );
                }
            }

            $this->command->info('✓ Motions seeded: ' . count($this->motionTexts) . ' motions with framework tags.');
        } catch (\Throwable $e) {
            $this->command->error('MotionSeeder failed: ' . $e->getMessage());
        }
    }
}
