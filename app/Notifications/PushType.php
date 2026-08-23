<?php

namespace App\Notifications;

/**
 * The push notification catalogue — the single source of truth for the wire
 * contract: type string, deep-link target, and the ar/en copy with its
 * placeholders.
 *
 * Placeholders are `:name` style, substituted via strtr(). Titles are kept
 * short because Android truncates around 40 characters in the collapsed
 * shade; debate and survey titles are wrapped in «…» so a long title stays
 * visibly delimited from the surrounding sentence.
 *
 * NOTE: `debate_created` is defined here but is never sent — see
 * DebateNotifier::debateCreated() for why it was retired. Its copy is kept so
 * the constant remains safe to reference.
 *
 * Two types have copy that varies by a DATA VALUE rather than only by
 * placeholders, so they carry a `variants` map instead of flat ar/en:
 *   #7 team_join_result — accepted vs refused read differently in Arabic
 *      (different verb and tone), so one templated sentence does not work.
 *   #8 blog_weekly_digest — Arabic has four plural buckets for the count.
 * Everything else uses the flat `ar`/`en` shape.
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

    /** Shared body for every #8 plural bucket. */
    private const DIGEST_BODY_EN = "Catch up on what's new in the Jadal blog.";
    private const DIGEST_BODY_AR = 'اطّلع على أحدث ما نُشر في مدونة جدل.';

    /**
     * #1 `:stage_label` — keyed on the raw `debates.status` enum value
     *.
     *
     * These are NOT the app's filter-tab captions and must not be replaced with
     * them: tab captions are standalone nouns ("Registration", "Done") that
     * read badly inside the sentence frame. These are rewritten to fit
     * "«X» is now …".
     *
     * The Arabic is deliberately in the FEMININE form to agree with مناظرة.
     * If these are ever reused in a different sentence frame, the agreement
     * has to be rechecked — they are not gender-neutral tokens.
     */
    private const STAGE_LABELS = [
        'scheduled'      => ['en' => 'open for registration', 'ar' => 'مفتوحة للتسجيل'],
        'announced'      => ['en' => 'announced',             'ar' => 'معلنة'],
        'teams-selected' => ['en' => 'in preparation',        'ar' => 'في مرحلة التحضير'],
        'live'           => ['en' => 'live',                  'ar' => 'مباشرة'],
        'completed'      => ['en' => 'finished',              'ar' => 'منتهية'],
        'cancelled'      => ['en' => 'cancelled',             'ar' => 'ملغاة'],
    ];

    /**
     * type => [deep_link, and either flat ar/en, or a `variants` map]
     */
    public static function catalogue(): array
    {
        return [
            /**
             * #1 — now names the stage, using the labels the frontend supplied
             *.
             *
             * `:stage_label` cannot be resolved by the caller: one
             * `$replacements` array is shared across every recipient device,
             * and those devices may be in different languages. So the RAW
             * status is passed in and the label is looked up per-locale inside
             * copy() — see below.
             *
             * The `fallback` row is retained as a safety net for a status with
             * no label (e.g. a new enum value added later). Better a slightly
             * vague notification than "«X» is now ." with a hole in it.
             */
            self::DEBATE_STATE_CHANGED => [
                'deep_link' => 'debate_details',
                'variants'  => [
                    'labelled' => [
                        'ar' => [
                            'title' => 'تحديث المناظرة',
                            'body'  => '«:debate_title» أصبحت الآن :stage_label.',
                        ],
                        'en' => [
                            'title' => 'Debate update',
                            'body'  => '«:debate_title» is now :stage_label.',
                        ],
                    ],
                    'fallback' => [
                        'ar' => [
                            'title' => 'تحديث المناظرة',
                            'body'  => '«:debate_title» انتقلت إلى مرحلة جديدة. اضغط للتفاصيل.',
                        ],
                        'en' => [
                            'title' => 'Debate update',
                            'body'  => '«:debate_title» has moved to a new stage. Tap for details.',
                        ],
                    ],
                ],
            ],

            self::DEBATE_ACCEPTED => [
                'deep_link' => 'debate_details',
                'ar' => [
                    'title' => 'تم قبولك في المناظرة',
                    'body'  => 'تم اختيارك للمشاركة في «:debate_title». اضغط للتفاصيل.',
                ],
                'en' => [
                    'title' => "You're in the debate",
                    'body'  => "You've been selected for «:debate_title». Tap for details.",
                ],
            ],

            self::PREP_REMINDER => [
                'deep_link' => 'debate_details',
                'ar' => [
                    'title' => 'التحضير يبدأ بعد ساعة',
                    'body'  => '«:debate_title» — اختيار الأطراف يبدأ بعد ساعة. استعد.',
                ],
                'en' => [
                    'title' => 'Preparation starts in an hour',
                    'body'  => '«:debate_title» — side selection opens in one hour. Get ready.',
                ],
            ],

            self::MOTION_REVEALED => [
                'deep_link' => 'debate_details',
                'ar' => [
                    'title' => 'تم الإعلان عن القضية',
                    'body'  => 'تم الكشف عن قضية «:debate_title». اضغط لقراءتها.',
                ],
                'en' => [
                    'title' => 'The motion is out',
                    'body'  => 'The motion for «:debate_title» has been revealed. Tap to read it.',
                ],
            ],

            // Copy says "open for registration" because the trigger is now
            // restricted to that case — see DebateNotifier::debateCreated().
            self::DEBATE_CREATED => [
                'deep_link' => 'debate_details',
                'ar' => [
                    'title' => 'مناظرة جديدة مفتوحة للتسجيل',
                    'body'  => '«:debate_title» مفتوحة للتسجيل — سجّل قبل اكتمال العدد.',
                ],
                'en' => [
                    'title' => 'New debate open for registration',
                    'body'  => '«:debate_title» is open — register before places fill up.',
                ],
            ],

            self::SURVEY_CREATED => [
                'deep_link' => 'survey',
                'ar' => [
                    'title' => 'استطلاع جديد',
                    'body'  => '«:survey_title» بانتظار رأيك.',
                ],
                'en' => [
                    'title' => 'New survey',
                    'body'  => '«:survey_title» is waiting for your response.',
                ],
            ],

            // #7 — keyed on the `result` data value. The data
            // payload itself is unchanged; this only selects which copy row
            // is used.
            self::TEAM_JOIN_RESULT => [
                'deep_link' => 'team',
                'variants'  => [
                    'accepted' => [
                        'ar' => [
                            'title' => 'أهلاً بك في :team_name',
                            'body'  => 'تم قبول طلب انضمامك إلى :team_name.',
                        ],
                        'en' => [
                            'title' => 'Welcome to :team_name',
                            'body'  => 'Your request to join :team_name was accepted.',
                        ],
                    ],
                    'refused' => [
                        'ar' => [
                            'title' => 'لم يُقبل طلب الانضمام',
                            'body'  => 'لم يتم قبول طلب انضمامك إلى :team_name هذه المرة.',
                        ],
                        'en' => [
                            'title' => 'Team request declined',
                            'body'  => "Your request to join :team_name wasn't accepted this time.",
                        ],
                    ],
                ],
            ],

            // #8 — Arabic plural buckets. Body is identical
            // across buckets; only the title changes. Count 0 never reaches
            // here (SendBlogWeeklyDigest skips the send entirely).
            self::BLOG_WEEKLY_DIGEST => [
                'deep_link' => 'blog',
                'variants'  => [
                    'one' => [
                        'ar' => ['title' => 'مقال جديد هذا الأسبوع',    'body' => self::DIGEST_BODY_AR],
                        'en' => ['title' => '1 new article this week',   'body' => self::DIGEST_BODY_EN],
                    ],
                    'two' => [
                        'ar' => ['title' => 'مقالان جديدان هذا الأسبوع', 'body' => self::DIGEST_BODY_AR],
                        'en' => ['title' => '2 new articles this week',  'body' => self::DIGEST_BODY_EN],
                    ],
                    'few' => [ // 3–10
                        'ar' => ['title' => ':count مقالات جديدة هذا الأسبوع', 'body' => self::DIGEST_BODY_AR],
                        'en' => ['title' => ':count new articles this week',   'body' => self::DIGEST_BODY_EN],
                    ],
                    'many' => [ // 11+
                        'ar' => ['title' => ':count مقالاً جديداً هذا الأسبوع', 'body' => self::DIGEST_BODY_AR],
                        'en' => ['title' => ':count new articles this week',    'body' => self::DIGEST_BODY_EN],
                    ],
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
     * For the two variant types the row is chosen from the replacements:
     * #7 from `result`, #8 from `count`.
     *
     * @return array{title: string, body: string}
     */
    public static function copy(string $type, string $locale, array $replacements = []): array
    {
        $entry = self::catalogue()[$type] ?? null;

        if ($entry === null) {
            return ['title' => '', 'body' => ''];
        }

        // #1 — resolve :stage_label for THIS device's language. Done here
        // rather than in the caller because a single $replacements array is
        // shared across recipients whose devices may differ in locale.
        if ($type === self::DEBATE_STATE_CHANGED) {
            $label = self::stageLabel((string) ($replacements['status'] ?? ''), $locale);
            if ($label !== null) {
                $replacements['stage_label'] = $label;
            }
        }

        if (isset($entry['variants'])) {
            $variantKey = match ($type) {
                self::DEBATE_STATE_CHANGED => isset($replacements['stage_label']) ? 'labelled' : 'fallback',
                self::TEAM_JOIN_RESULT   => ($replacements['result'] ?? null) === 'accepted' ? 'accepted' : 'refused',
                self::BLOG_WEEKLY_DIGEST => self::digestBucket((int) ($replacements['count'] ?? 0)),
                default                  => array_key_first($entry['variants']),
            };

            $variant = $entry['variants'][$variantKey];
            // Anything that is not explicitly Arabic falls back to English.
            $strings = $variant[$locale] ?? $variant['en'];
        } else {
            $strings = $entry[$locale] ?? $entry['en'];
        }

        $map = [];
        foreach ($replacements as $key => $value) {
            $map[':' . $key] = (string) $value;
        }

        return [
            'title' => strtr($strings['title'], $map),
            'body'  => strtr($strings['body'], $map),
        ];
    }

    /**
     * Localized stage name for a raw `debates.status` value, or null if the
     * status has no label (which makes #1 fall back to the vaguer copy rather
     * than rendering an empty slot).
     */
    public static function stageLabel(string $status, string $locale): ?string
    {
        $labels = self::STAGE_LABELS[$status] ?? null;

        // Anything that is not explicitly Arabic falls back to English.
        return $labels === null ? null : ($labels[$locale] ?? $labels['en']);
    }

    /**
     * Arabic plural bucket for the weekly digest:
     * 1 → singular, 2 → dual, 3–10 → paucal, 11+ → the accusative singular
     * form Arabic uses after large numbers.
     */
    private static function digestBucket(int $count): string
    {
        return match (true) {
            $count <= 1  => 'one',
            $count === 2 => 'two',
            $count <= 10 => 'few',
            default      => 'many',
        };
    }

    public static function deepLink(string $type): ?string
    {
        return self::catalogue()[$type]['deep_link'] ?? null;
    }
}
