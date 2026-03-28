<?php

namespace Database\Factories;

use App\Models\Motion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Motion>
 */
class MotionFactory extends Factory
{
    private static array $motionTexts = [
        'This house believes that social media does more harm than good',
        'هذا البيت يؤمن بأن التعليم عن بعد أفضل من التعليم التقليدي',
        'This house would make voting mandatory in all democracies',
        'هذا البيت يؤيد حظر وسائل التواصل الاجتماعي للأطفال دون الثالثة عشرة',
        'This house believes that artificial intelligence poses an existential threat',
        'هذا البيت يرى أن الرياضة الاحترافية تعزز القيم الإيجابية في المجتمع',
        'This house would ban fossil fuels by 2035',
        'هذا البيت يؤمن بأن المرأة يجب أن تتولى نصف المناصب القيادية',
        'This house believes that economic growth should be prioritized over environmental protection',
        'هذا البيت يرى أن الجامعات يجب أن تلغي نظام التقديرات',
        'This house would legalize all drugs',
        'هذا البيت يؤيد إلزامية الخدمة المدنية للشباب',
        'This house believes that free trade does more harm than good to developing nations',
        'هذا البيت يرى أن وسائل الإعلام تشكل خطراً على الديمقراطية',
        'This house would abolish nuclear weapons globally',
        'هذا البيت يؤمن بأن الذكاء الاصطناعي سيحل محل معظم الوظائف البشرية',
        'This house believes that space exploration should be privatized',
        'هذا البيت يرى أن الاندماج الثقافي يقوي المجتمعات',
        'This house would implement a universal basic income',
        'هذا البيت يؤيد فرض ضريبة على الثروات الطائلة',
        'This house believes that democracy is the best form of governance',
        'هذا البيت يرى أن الرياضة الإلكترونية رياضة حقيقية',
        'This house would ban advertising directed at children',
        'هذا البيت يؤمن بأن التنوع الثقافي يعزز الابتكار',
        'This house believes that religion has no place in public policy',
    ];

    private static int $motionIndex = 0;

    public function definition(): array
    {
        $text = self::$motionTexts[self::$motionIndex % count(self::$motionTexts)];
        self::$motionIndex++;

        return [
            'added_by' => User::factory(),
            'text'     => $text,
        ];
    }
}
