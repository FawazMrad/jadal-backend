<?php

namespace App\Notifications;

/**
 * The 8 push notification types (frontend spec §7.2) — the single source of
 * truth for the wire contract: type string, deep-link target, and the ar/en
 * copy with its placeholders.
 *
 * Copy is a FIRST DRAFT by the backend and expected to be corrected by the
 * frontend, who has the better view of tone and how strings render in the UI.
 * Placeholders are `:name` style and substituted via strtr().
 *
 * Data payloads are string-only (an FCM constraint), so every id is cast to a
 * string before sending — see PushService::send().
 */
final class PushType
{
    public const DEBATE_STATE_CHANGED = 'debate_state_changed';
    public const DEBATE_ACCEPTED      = 'debate_accepted';
    public const PREP_REMINDER        = 'prep_reminder';
    public const MOTION_REVEALED      = 'motion_revealed';
    public const DEBATE_CREATED       = 'debate_created';
    public const SURVEY_CREATED       = 'survey_created';
    public const TEAM_JOIN_RESULT     = 'team_join_result';
    public const BLOG_WEEKLY_DIGEST   = 'blog_weekly_digest';

    /**
     * type => [deep_link, ar => [title, body], en => [title, body]]
     *
     * @return array<string, array{deep_link: string, ar: array{title: string, body: string}, en: array{title: string, body: string}}>
     */
    public static function catalogue(): array
    {
        return [
            self::DEBATE_STATE_CHANGED => [
                'deep_link' => 'debate_details',
                'ar' => [
                    'title' => 'تحديث في المناظرة',
                    'body'  => 'تغيّرت حالة مناظرة ":debate_title".',
                ],
                'en' => [
                    'title' => 'Debate updated',
                    'body'  => 'The state of ":debate_title" has changed.',
                ],
            ],

            self::DEBATE_ACCEPTED => [
                'deep_link' => 'debate_details',
                'ar' => [
                    'title' => 'تم قبولك في المناظرة',
                    'body'  => 'تم اختيارك للمشاركة في ":debate_title".',
                ],
                'en' => [
                    'title' => 'You are in',
                    'body'  => 'You have been selected to take part in ":debate_title".',
                ],
            ],

            self::PREP_REMINDER => [
                'deep_link' => 'debate_details',
                'ar' => [
                    'title' => 'التحضير على وشك البدء',
                    'body'  => 'يبدأ التحضير لمناظرة ":debate_title" بعد ساعة.',
                ],
                'en' => [
                    'title' => 'Preparation starts soon',
                    'body'  => 'Preparation for ":debate_title" starts in one hour.',
                ],
            ],

            self::MOTION_REVEALED => [
                'deep_link' => 'debate_details',
                'ar' => [
                    'title' => 'تم كشف القضية',
                    'body'  => 'ظهرت قضية مناظرة ":debate_title".',
                ],
                'en' => [
                    'title' => 'Motion revealed',
                    'body'  => 'The motion for ":debate_title" is now available.',
                ],
            ],

            self::DEBATE_CREATED => [
                'deep_link' => 'debate_details',
                'ar' => [
                    'title' => 'مناظرة جديدة',
                    'body'  => 'أضيفت مناظرة جديدة: ":debate_title".',
                ],
                'en' => [
                    'title' => 'New debate',
                    'body'  => 'A new debate has been added: ":debate_title".',
                ],
            ],

            self::SURVEY_CREATED => [
                'deep_link' => 'survey',
                'ar' => [
                    'title' => 'استبيان جديد',
                    'body'  => 'هناك استبيان جديد بانتظار رأيك: ":survey_title".',
                ],
                'en' => [
                    'title' => 'New survey',
                    'body'  => 'A new survey is waiting for your input: ":survey_title".',
                ],
            ],

            self::TEAM_JOIN_RESULT => [
                'deep_link' => 'team',
                'ar' => [
                    'title' => 'نتيجة طلب الانضمام',
                    'body'  => 'تم :result_ar طلب انضمامك إلى فريق ":team_name".',
                ],
                'en' => [
                    'title' => 'Join request result',
                    'body'  => 'Your request to join ":team_name" was :result_en.',
                ],
            ],

            self::BLOG_WEEKLY_DIGEST => [
                'deep_link' => 'blog',
                'ar' => [
                    'title' => 'جديد المدونة',
                    'body'  => 'نُشرت :count مقالة جديدة هذا الأسبوع.',
                ],
                'en' => [
                    'title' => 'This week on the blog',
                    'body'  => ':count new article(s) were published this week.',
                ],
            ],
        ];
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::catalogue());
    }

    /**
     * Localized title/body for a type, with :placeholders substituted.
     *
     * @return array{title: string, body: string}
     */
    public static function copy(string $type, string $locale, array $replacements = []): array
    {
        $entry = self::catalogue()[$type] ?? null;

        if ($entry === null) {
            return ['title' => '', 'body' => ''];
        }

        // Anything that is not explicitly Arabic falls back to English.
        $strings = $entry[$locale] ?? $entry['en'];

        $map = [];
        foreach ($replacements as $key => $value) {
            $map[':' . $key] = (string) $value;
        }

        return [
            'title' => strtr($strings['title'], $map),
            'body'  => strtr($strings['body'], $map),
        ];
    }

    public static function deepLink(string $type): ?string
    {
        return self::catalogue()[$type]['deep_link'] ?? null;
    }
}
