<?php

namespace App\Notifications;

/**
 * The 8 push notification types (frontend spec §7.2) — the single source of
 * truth for the wire contract: type string, deep-link target, and the ar/en
 * copy with its placeholders.
 *
 * Copy is the frontend's, from BACKEND_PUSH_HANDOFF.md §4.2–§4.4, replacing the
 * backend's earlier first draft. Placeholders are `:name` style, substituted
 * via strtr(). Titles are kept short because Android truncates around 40 chars
 * in the collapsed shade; debate/survey titles are wrapped in «…» so a long
 * title stays visibly delimited from the sentence.
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

    /** Shared body for every #8 plural bucket (handoff §4.4). */
    private const DIGEST_BODY_EN = "Catch up on what's new in the Jadal blog.";
    private const DIGEST_BODY_AR = 'اطّلع على أحدث ما نُشر في مدونة جدل.';

    /**
     * type => [deep_link, and either flat ar/en, or a `variants` map]
     */
    public static function catalogue(): array
    {
        return [
            /**
             * #1 — uses the handoff's §4.2 "1f" FALLBACK row, not the
             * :stage_label row. There is no localized human-readable name for
             * a debate status anywhere in this codebase (statuses are raw
             * enum strings like `teams-selected`, and the project has no
             * localization layer at all), so :stage_label would mean inventing
             * a 12-string ar/en status map the frontend has not supplied.
             * See HANDOFF_TO_ARCHITECT.md for the full reasoning.
             *
             * TODO: switch to the non-fallback row if a localized status label
             * is ever introduced — the copy is already agreed:
             *   en  «:debate_title» is now :stage_label.
             *   ar  «:debate_title» أصبحت الآن :stage_label.
             */
            self::DEBATE_STATE_CHANGED => [
                'deep_link' => 'debate_details',
                'ar' => [
                    'title' => 'تحديث المناظرة',
                    'body'  => '«:debate_title» انتقلت إلى مرحلة جديدة. اضغط للتفاصيل.',
                ],
                'en' => [
                    'title' => 'Debate update',
                    'body'  => '«:debate_title» has moved to a new stage. Tap for details.',
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

            // #7 — keyed on the `result` data value (handoff §4.3). The data
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

            // #8 — Arabic plural buckets (handoff §4.4). Body is identical
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

        if (isset($entry['variants'])) {
            $variantKey = match ($type) {
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
     * Arabic plural bucket for the weekly digest (handoff §4.4):
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
