<?php

namespace Database\Seeders;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Models\DebateResult;
use App\Models\Feedbacks;
use App\Models\Motion;
use App\Models\MotionFramework;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Realistic test data for frontend integration testing on real devices.
 *
 * OPT-IN ONLY — deliberately not wired into DatabaseSeeder. Run with:
 *   php artisan db:seed --class=ComprehensiveTestDataSeeder
 *
 * ─── Cleanup ────────────────────────────────────────────────────────────────
 * Every row this seeder creates hangs off a user whose email ends in
 * @jadal-seed-test.local, via an ON DELETE CASCADE path:
 *
 *   users -> teams.created_by/leader_id  -> team_members
 *         -> debates.created_by          -> debate_phases, debate_participants,
 *                                           debate_results, feedbacks
 *         -> motions.added_by            -> motion_framework_pivot
 *
 * So the whole dataset is removed by deleting those users. The two debate
 * formats are the one exception (they belong to no user):
 *
 *   User::where('email', 'like', '%@jadal-seed-test.local')->delete();
 *   DebateFormat::where('name', 'like', '[SEED]%')->delete();
 *
 * Nothing pre-existing is read for mutation or written to — this is purely
 * additive.
 *
 * ─── Schema notes worth keeping (verified against the live schema) ──────────
 * - debate_results.scores is {"stages":[{stage_order,participant_id,user_id,
 *   score}],"notes":…}. Several older fixtures in this repo use a by-side
 *   {"proposition":…,"opposition":…} shape; that shape is dead and is NOT
 *   what LiveDebateController::submitResult writes.
 * - debates.tag and debates.livekit_room_name are NOT NULL with no default.
 * - debate_phases has no `role` column: side comes from order_index parity
 *   (odd = proposition) and inverts for the two reply stages.
 * - The 6 pre-existing debate_formats carry a legacy phase_config that makes
 *   DebateFormat::deriveStages() blow up, so this seeder ships its own.
 */
class ComprehensiveTestDataSeeder extends Seeder
{
    private const DOMAIN      = '@jadal-seed-test.local';
    private const PASSWORD    = 'Test1234!';
    private const FORMAT_MARK = '[SEED]';

    /** Deterministic output so reruns after a cleanup produce the same data. */
    private const RANDOM_SEED = 20260811;

    private const FIRST_NAMES = [
        'أحمد', 'محمد', 'عمر', 'خالد', 'يوسف', 'إبراهيم', 'حسن', 'علي', 'زيد', 'طارق',
        'سامر', 'رامي', 'باسل', 'مروان', 'ياسر', 'فادي', 'نبيل', 'وسيم', 'أيمن', 'جميل',
        'لينا', 'سارة', 'نور', 'ريم', 'هبة', 'دانا', 'مايا', 'رنا', 'ليلى', 'سلمى',
        'فاطمة', 'عائشة', 'خديجة', 'جمانة', 'راما', 'تالا', 'يارا', 'زينة', 'ملك', 'جود',
    ];

    private const LAST_NAMES = [
        'الخطيب', 'العلي', 'الحسن', 'المصري', 'الشامي', 'الحلبي', 'النجار', 'الحداد',
        'السيد', 'العمري', 'الزعبي', 'القاسم', 'الرشيد', 'الصالح', 'الدرويش', 'الحاج',
        'أبو زيد', 'أبو سالم', 'بن عمار', 'الطويل', 'القيسي', 'الأنصاري', 'البيطار', 'الكيلاني',
    ];

    private const TEAM_NAMES = [
        'فريق البيان', 'فريق الحجة', 'فريق المنطق', 'فريق البرهان',
        'فريق الفصاحة', 'فريق الإقناع', 'فريق النقاش', 'فريق الرأي',
    ];

    private const MOTION_TEXTS = [
        'هذا المجلس يرى أن التعليم عن بعد يجب أن يكون خياراً دائماً في الجامعات',
        'هذا المجلس يدعم فرض ضريبة على الشركات التي تعتمد على الأتمتة الكاملة',
        'هذا المجلس يرى أن وسائل التواصل الاجتماعي تضر أكثر مما تنفع بالنقاش العام',
        'هذا المجلس يؤيد منح المدن الكبرى صلاحيات مالية مستقلة عن الحكومة المركزية',
        'هذا المجلس يرى أن الذكاء الاصطناعي يجب أن يخضع لترخيص حكومي قبل النشر',
        'هذا المجلس يعارض استخدام الخوارزميات في اتخاذ قرارات التوظيف',
        'هذا المجلس يرى أن الرياضة الاحترافية للناشئين تضر بمصلحتهم الفضلى',
        'هذا المجلس يدعم إلزام الشركات بالإفصاح الكامل عن أثرها البيئي',
        'هذا المجلس يرى أن حرية التعبير يجب أن تشمل خطاب الكراهية',
        'هذا المجلس يؤيد استبدال الامتحانات النهائية بالتقييم المستمر',
        'هذا المجلس يرى أن الدول الغنية ملزمة أخلاقياً باستقبال اللاجئين المناخيين',
        'هذا المجلس يعارض ملكية وسائل الإعلام من قبل رجال الأعمال',
        'هذا المجلس يرى أن العمل عن بعد يضعف التماسك المؤسسي',
        'هذا المجلس يدعم تخصيص حصة للنساء في المجالس المنتخبة',
        'هذا المجلس يرى أن حماية البيانات الشخصية تعلو على المصلحة الأمنية',
    ];

    private const DEBATE_TAGS = ['بطولة', 'ودية', 'تدريبية', 'تصفيات', 'نهائيات'];

    private const RATING_NOTES_DEBATE = [
        'نقاش قوي ومنظم، استفدت كثيراً.',
        'المستوى كان جيداً لكن إدارة الوقت تحتاج تحسيناً.',
        'من أفضل المناظرات التي شاركت فيها.',
        'الطرح كان متوازناً بين الفريقين.',
        'أتمنى لو كان هناك وقت أطول للأسئلة.',
    ];

    private const RATING_NOTES_JUDGE = [
        'تحكيم عادل وتعليل واضح للقرار.',
        'الملاحظات كانت مفيدة ومحددة.',
        'قرار موفق مع شرح مقنع.',
        'كنت أتمنى تفصيلاً أكثر في التغذية الراجعة.',
    ];

    /** @var array<string,int> */
    private array $counts = [];

    /** Current roster per team id, so debates can draw speakers without re-querying. */
    private array $teamRosters = [];

    public function run(): void
    {
        mt_srand(self::RANDOM_SEED);

        $existing = User::where('email', 'like', '%' . self::DOMAIN)->count();
        if ($existing > 0) {
            $this->command->error("Aborted: {$existing} seeded users already exist.");
            $this->command->warn('This seeder is additive and would duplicate the dataset.');
            $this->command->line('Clean up first:');
            $this->command->line("  User::where('email','like','%" . self::DOMAIN . "')->delete();");
            $this->command->line("  DebateFormat::where('name','like','" . self::FORMAT_MARK . "%')->delete();");

            return;
        }

        DB::transaction(fn () => $this->seed());

        $this->report();
    }

    private function seed(): void
    {
        $debaters = $this->makeUsers('debater', 40);
        $judges   = $this->makeUsers('judge', 10);
        $trainers = $this->makeUsers('trainer', 8);
        $admins   = $this->makeUsers('admin', 2);

        $teams      = $this->makeTeams($trainers, $debaters);
        $frameworks = $this->ensureFrameworks($admins[0]);
        $motions    = $this->makeMotions($admins[0], $frameworks);
        $formats    = $this->makeFormats();

        $this->makeCompletedDebates($teams, $judges, $motions, $formats, $admins[0]);
        $this->makeLifecycleDebates($teams, $judges, $motions, $formats, $admins[0]);
    }

    // ── Users ───────────────────────────────────────────────────────────────

    /** @return array<int,User> */
    private function makeUsers(string $role, int $count): array
    {
        $users = [];

        for ($i = 1; $i <= $count; $i++) {
            $name = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)]
                . ' ' . self::LAST_NAMES[array_rand(self::LAST_NAMES)];

            // Spread over the last 6 months so "member since" and any
            // cohort-style filtering have something to bite on.
            $createdAt = Carbon::now()->subDays(mt_rand(5, 180))->setTime(mt_rand(8, 21), mt_rand(0, 59));

            // forceCreate: created_at / email_verified_at are not in $fillable.
            $users[] = User::forceCreate([
                'name'              => $name,
                'email'             => "{$role}{$i}" . self::DOMAIN,
                'password'          => Hash::make(self::PASSWORD),
                'role'              => $role,
                'status'            => 'active',
                'phone'             => '+9627' . mt_rand(10000000, 99999999),
                'points'            => $role === 'debater' ? mt_rand(0, 400) : 0,
                'location'          => 'عمّان، الأردن',
                'email_verified_at' => $createdAt,
                'created_at'        => $createdAt,
                'updated_at'        => $createdAt,
            ]);
        }

        $this->counts["users ({$role})"] = $count;

        return $users;
    }

    // ── Teams ───────────────────────────────────────────────────────────────

    /**
     * @param  array<int,User>  $trainers
     * @param  array<int,User>  $debaters
     * @return array<int,Team>
     */
    private function makeTeams(array $trainers, array $debaters): array
    {
        $teams   = [];
        $pool    = $debaters;
        $current = 0;
        $past    = 0;

        // Deterministic allocation. Random sizes would overdraw the 40-debater
        // pool and leave later teams with fewer than the 3 speakers a debate
        // needs. 8 teams x 4 base = 32, then these extras spend the remaining 8
        // exactly, giving a 4-6 spread.
        $extras   = [2, 2, 1, 1, 0, 0, 1, 1];
        $allocated = [];

        foreach (self::TEAM_NAMES as $index => $teamName) {
            $allocated[$index] = array_splice($pool, 0, 4 + $extras[$index]);
        }

        foreach (self::TEAM_NAMES as $index => $teamName) {
            $members = $allocated[$index];

            // leader_id is NOT NULL, so the roster has to be picked before the
            // team row exists. Leader is always one of its own members.
            $leader  = $members[0];
            $trainer = $trainers[$index % count($trainers)];

            $team = Team::create([
                'name'       => $teamName,
                'leader_id'  => $leader->id,
                'created_by' => $trainer->id,
                'is_random'  => false,
                'status'     => 'active',
                'points'     => mt_rand(0, 300),
            ]);

            foreach ($members as $priority => $member) {
                TeamMember::create([
                    'team_id'  => $team->id,
                    'user_id'  => $member->id,
                    'priority' => $priority + 1,
                    'status'   => 'current',
                ]);
                $current++;
            }

            // Two teams also carry departed members, so "past members are
            // excluded from active rosters" has something to exclude. These are
            // modelled as transfers — someone currently on a later team who
            // used to be here — rather than spare users, because the 40-debater
            // pool is fully allocated above.
            if ($index < 2) {
                $donor   = $allocated[6 + $index];
                $leavers = array_slice($donor, -mt_rand(1, 2));

                foreach ($leavers as $offset => $former) {
                    TeamMember::create([
                        'team_id'  => $team->id,
                        'user_id'  => $former->id,
                        'priority' => 90 + $offset,
                        'status'   => 'past',
                    ]);
                    $past++;
                }
            }

            $this->teamRosters[$team->id] = $members;
            $teams[] = $team;
        }

        $this->counts['teams']                 = count($teams);
        $this->counts['team_members (current)'] = $current;
        $this->counts['team_members (past)']    = $past;

        return $teams;
    }

    // ── Reference data ──────────────────────────────────────────────────────

    /** @return array<int,MotionFramework> */
    private function ensureFrameworks(User $admin): array
    {
        $existing = MotionFramework::all();
        if ($existing->count() >= 4) {
            $this->counts['motion_frameworks (reused)'] = $existing->count();

            return $existing->all();
        }

        $created = [];
        foreach (['Policy', 'Value', 'Fact', 'Comparative Advantage'] as $i => $name) {
            $created[] = MotionFramework::firstOrCreate(
                ['name' => $name],
                ['color_hex' => sprintf('#%06X', mt_rand(0x333333, 0xCCCCCC))]
            );
        }

        $this->counts['motion_frameworks (created)'] = count($created);

        return $created;
    }

    /**
     * @param  array<int,MotionFramework>  $frameworks
     * @return array<int,Motion>
     */
    private function makeMotions(User $admin, array $frameworks): array
    {
        $motions = [];

        foreach (self::MOTION_TEXTS as $text) {
            $motion = Motion::create([
                'added_by' => $admin->id,
                'text'     => $text,
            ]);

            // 1-2 frameworks each, so framework filtering has variety.
            $picked = (array) array_rand($frameworks, min(mt_rand(1, 2), count($frameworks)));
            foreach ($picked as $key) {
                DB::table('motion_framework_pivot')->insert([
                    'motion_id'    => $motion->id,
                    'framework_id' => $frameworks[$key]->id,
                ]);
            }

            $motions[] = $motion;
        }

        $this->counts['motions'] = count($motions);

        return $motions;
    }

    /**
     * The 6 pre-existing formats store a legacy phase_config that makes
     * deriveStages() throw, so they cannot be reused. These two carry the
     * shape StoreDebateFormatRequest actually validates.
     *
     * @return array<string,DebateFormat>
     */
    private function makeFormats(): array
    {
        $noReply = DebateFormat::create([
            'name'         => self::FORMAT_MARK . ' ثلاثة متحدثين — بدون رد',
            'description'  => 'Seeded format: 3 speakers per side, 6 stages, no reply speech.',
            'phase_config' => [
                'speech_time_seconds'          => 420,
                'has_reply_speech'             => false,
                'motion_reveal_offset_hours'   => 24,
                'prep_rooms_open_offset_hours' => 1,
            ],
        ]);

        $withReply = DebateFormat::create([
            'name'         => self::FORMAT_MARK . ' ثلاثة متحدثين — مع رد',
            'description'  => 'Seeded format: 3 speakers per side, 8 stages including replies.',
            'phase_config' => [
                'speech_time_seconds'          => 420,
                'has_reply_speech'             => true,
                'reply_time_seconds'           => 240,
                'motion_reveal_offset_hours'   => 24,
                'prep_rooms_open_offset_hours' => 1,
            ],
        ]);

        $this->counts['debate_formats'] = 2;

        return ['no_reply' => $noReply, 'with_reply' => $withReply];
    }

    // ── Completed debates ───────────────────────────────────────────────────

    /**
     * @param  array<int,Team>            $teams
     * @param  array<int,User>            $judges
     * @param  array<int,Motion>          $motions
     * @param  array<string,DebateFormat> $formats
     */
    private function makeCompletedDebates(array $teams, array $judges, array $motions, array $formats, User $admin): void
    {
        $pairs           = $this->rotatePairs(count($teams), 30);
        $phaseCount      = 0;
        $participantCt   = 0;
        $bestSpeakerDebs = [3, 11, 22]; // guaranteed clear best-speaker signal
        $debates         = [];

        foreach ($pairs as $i => [$propIdx, $oppIdx]) {
            $format = $i % 3 === 0 ? $formats['with_reply'] : $formats['no_reply'];
            $stages = $format->deriveStages();

            $scheduledAt = $this->clusteredPastDate();

            // Rosters are resolved BEFORE the insert so the speaker orders can
            // go in with it. See the note below on why this must be one INSERT.
            $propSpeakers = $this->rosterFor($teams[$propIdx], 3);
            $oppSpeakers  = $this->rosterFor($teams[$oppIdx], 3);

            // forceCreate: created_at/updated_at are backdated here and are not
            // in Debate::$fillable.
            //
            // Everything about this row is written in ONE statement on purpose.
            // debates.scheduled_at is `timestamp NOT NULL DEFAULT
            // current_timestamp() ON UPDATE current_timestamp()`, so ANY later
            // UPDATE that doesn't name scheduled_at silently resets it to
            // DB-now — which is how a previous version of this seeder ended up
            // with all 30 debates scheduled on the same day.
            $debate = Debate::forceCreate([
                'format_id'              => $format->id,
                'motion_id'              => $motions[$i % count($motions)]->id,
                'proposition_team_id'    => $teams[$propIdx]->id,
                'opposition_team_id'     => $teams[$oppIdx]->id,
                'created_by'             => $admin->id,
                'title'                  => 'مناظرة ' . $teams[$propIdx]->name . ' ضد ' . $teams[$oppIdx]->name,
                'description'            => 'مناظرة مكتملة ضمن بيانات الاختبار.',
                // tag + livekit_room_name are NOT NULL with no DB default.
                'tag'                    => self::DEBATE_TAGS[$i % count(self::DEBATE_TAGS)],
                'status'                 => 'completed',
                'livekit_room_name'      => "seed-debate-{$i}-main",
                'prop_room_name'         => "seed-debate-{$i}-prop",
                'opp_room_name'          => "seed-debate-{$i}-opp",
                'result_room_name'       => "seed-debate-{$i}-result",
                'scheduled_at'           => $scheduledAt,
                'motion_revealed_at'     => (clone $scheduledAt)->subHours(24),
                'prep_rooms_opened_at'   => (clone $scheduledAt)->subHour(),
                'live_started_at'        => $scheduledAt,
                'started_at'             => $scheduledAt,
                'speeches_completed_at'  => (clone $scheduledAt)->addMinutes(75),
                'ended_at'               => (clone $scheduledAt)->addMinutes(75),
                'result_revealed_at'     => (clone $scheduledAt)->addMinutes(95),
                'current_stage'          => count($stages) + 1, // past-last-speech marker
                'timer_is_paused'        => false,
                'prop_speaker_order'     => array_map(fn ($u) => $u->id, $propSpeakers),
                'opp_speaker_order'      => array_map(fn ($u) => $u->id, $oppSpeakers),
                'created_at'             => $scheduledAt,
                'updated_at'             => $scheduledAt,
            ]);

            $bySide = [
                'proposition' => $this->makeSpeakers($debate, $teams[$propIdx], $propSpeakers, 'proposition'),
                'opposition'  => $this->makeSpeakers($debate, $teams[$oppIdx], $oppSpeakers, 'opposition'),
            ];
            $participantCt += 6;

            $panel = $this->makeJudges($debate, $judges, mt_rand(2, 3), $scheduledAt);
            $participantCt += count($panel);

            $forceBest = in_array($i, $bestSpeakerDebs, true);
            $stageData = $this->makePhasesAndScores($debate, $stages, $bySide, $scheduledAt, $forceBest);
            $phaseCount += count($stages);

            $this->makeResult($debate, $panel, $stageData, $scheduledAt);

            $debates[] = ['debate' => $debate, 'panel' => $panel, 'sides' => $bySide];
        }

        $this->counts['debates (completed + revealed)'] = count($pairs);
        $this->counts['debate_phases']                  = $phaseCount;
        $this->counts['debate_participants']            = $participantCt;
        $this->counts['debate_results']                 = count($pairs);

        $this->makeFeedback(array_slice($debates, 0, 15));
    }

    /**
     * Rotating pairings so the same two teams don't keep meeting and no team
     * is systematically absent.
     *
     * @return array<int,array{0:int,1:int}>
     */
    private function rotatePairs(int $teamCount, int $total): array
    {
        $pairs = [];
        $a = 0;
        $b = 1;

        for ($i = 0; $i < $total; $i++) {
            $pairs[] = [$a % $teamCount, $b % $teamCount];
            $a += 1;
            $b += ($i % 3 === 0) ? 2 : 3;

            if ($a % $teamCount === $b % $teamCount) {
                $b++;
            }
        }

        return $pairs;
    }

    /** More debates in recent months than older ones — matters for monthly bucketing. */
    private function clusteredPastDate(): Carbon
    {
        $roll = mt_rand(1, 100);

        $daysAgo = match (true) {
            $roll <= 45 => mt_rand(1, 60),    // ~45% in the last 2 months
            $roll <= 75 => mt_rand(61, 120),  // ~30% in months 3-4
            default     => mt_rand(121, 180), // ~25% in months 5-6
        };

        return Carbon::now()->subDays($daysAgo)->setTime(mt_rand(16, 20), [0, 15, 30][mt_rand(0, 2)]);
    }

    /** @return array<int,User> */
    private function rosterFor(Team $team, int $n): array
    {
        return array_slice($this->teamRosters[$team->id], 0, $n);
    }

    /**
     * @param  array<int,User>  $speakers
     * @return array<int,DebateParticipant>
     */
    private function makeSpeakers(Debate $debate, Team $team, array $speakers, string $side): array
    {
        $out = [];

        foreach ($speakers as $slot => $user) {
            $out[] = DebateParticipant::create([
                'debate_id'            => $debate->id,
                'user_id'              => $user->id,
                'team_id'              => $team->id,
                'role'                 => 'debater',
                'side'                 => $side,
                'status'               => 'approved',
                'is_attended'          => true,
                'first_attended_at'    => $debate->scheduled_at,
                'prep_attended_at'     => (clone $debate->scheduled_at)->subMinutes(30),
                'speaking_phase_order' => $slot + 1,
                // Reply speaker defaults to slot 1, matching the lifecycle command.
                'is_reply_speaker'     => $slot === 0,
            ]);
        }

        return $out;
    }

    /** @return array<int,DebateParticipant> */
    private function makeJudges(Debate $debate, array $judges, int $n, Carbon $at): array
    {
        $picked = collect($judges)->shuffle()->take($n)->values();
        $panel  = [];

        foreach ($picked as $order => $judge) {
            $panel[] = DebateParticipant::create([
                'debate_id'         => $debate->id,
                'user_id'           => $judge->id,
                'role'              => 'judge',
                'side'              => 'judge',
                'status'            => 'approved',
                'is_chair'          => $order === 0,
                'is_attended'       => true,
                'first_attended_at' => $at,
                'judge_order'       => $order + 1,
            ]);
        }

        return $panel;
    }

    /**
     * Creates the phases and returns the scores[] payload for debate_results.
     *
     * Phase -> speaker mapping mirrors LiveDebateController::resolveStageSpeaker:
     * odd order_index = proposition, even = opposition, slot = ceil(order/2);
     * reply stages resolve by name and go to the is_reply_speaker.
     *
     * @param  array<string,array<int,DebateParticipant>>  $bySide
     * @return array<int,array<string,mixed>>
     */
    private function makePhasesAndScores(Debate $debate, array $stages, array $bySide, Carbon $at, bool $forceBest): array
    {
        $stageData = [];
        $cursor    = (clone $at);

        foreach ($stages as $stage) {
            $order   = $stage['order_index'];
            $isReply = $stage['is_reply'];

            if ($isReply) {
                $side        = str_contains(strtolower($stage['name']), 'opposition') ? 'opposition' : 'proposition';
                $participant = collect($bySide[$side])->firstWhere('is_reply_speaker', true) ?? $bySide[$side][0];
            } else {
                $side        = $order % 2 === 1 ? 'proposition' : 'opposition';
                $participant = $bySide[$side][(int) ceil($order / 2) - 1];
            }

            $startedAt = (clone $cursor);
            $endedAt   = (clone $cursor)->addSeconds($stage['duration_seconds']);
            $cursor    = (clone $endedAt)->addMinutes(2);

            DebatePhase::create([
                'debate_id'        => $debate->id,
                'participant_id'   => $participant->id,
                'name'             => $stage['name'],
                'order_index'      => $order,
                'duration_seconds' => $stage['duration_seconds'],
                'status'           => 'completed',
                'started_at'       => $startedAt,
                'ended_at'         => $endedAt,
                'poi_raised_count' => mt_rand(0, 4),
                'poi_answered_count' => mt_rand(0, 2),
                'is_reply'         => $isReply,
            ]);

            $stageData[] = [
                'stage_order'    => $order,
                'participant_id' => $participant->id,
                'user_id'        => $participant->user_id,
                'score'          => $this->realisticScore(),
            ];
        }

        if ($forceBest) {
            // One unambiguous standout, well clear of the rest.
            $target = array_rand($stageData);
            foreach ($stageData as $k => $row) {
                if ($k !== $target && $row['score'] > 88) {
                    $stageData[$k]['score'] = mt_rand(70, 84);
                }
            }
            $stageData[$target]['score'] = mt_rand(95, 99);
        }

        return $stageData;
    }

    /** Mostly 55-85, with genuine tails rather than a flat spread. */
    private function realisticScore(): int
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 6  => mt_rand(38, 54),  // weak outlier
            $roll <= 88 => mt_rand(55, 85),  // the bulk
            default     => mt_rand(86, 96),  // strong outlier
        };
    }

    /**
     * @param  array<int,DebateParticipant>       $panel
     * @param  array<int,array<string,mixed>>     $stageData
     */
    private function makeResult(Debate $debate, array $panel, array $stageData, Carbon $at): void
    {
        $propTotal = 0;
        $oppTotal  = 0;

        foreach ($stageData as $row) {
            // Odd stages are proposition; the two reply stages invert.
            $isOdd  = $row['stage_order'] % 2 === 1;
            $isProp = $row['stage_order'] <= 6 ? $isOdd : ! $isOdd;
            $isProp ? $propTotal += $row['score'] : $oppTotal += $row['score'];
        }

        $winner = match (true) {
            abs($propTotal - $oppTotal) <= 2 => 'draw',
            $propTotal > $oppTotal           => 'proposition',
            default                          => 'opposition',
        };

        $chair = collect($panel)->firstWhere('is_chair', true) ?? $panel[0];

        $contributing = collect($panel)->map(fn ($j) => [
            'user_id'     => (int) $j->user_id,
            'judge_order' => $j->judge_order,
            'is_chair'    => (bool) $j->is_chair,
        ])->values()->all();

        $notes = 'قرار الهيئة بعد المداولة. الفارق في الأدلة كان حاسماً.';

        DebateResult::create([
            'debate_id'           => $debate->id,
            'judge_id'            => $chair->user_id,
            'contributing_judges' => $contributing,
            'winning_side'        => $winner,
            // Shape mirrors LiveDebateController::submitResult exactly.
            'scores'              => ['stages' => $stageData, 'notes' => $notes],
            'summary_notes'       => $notes,
            'submitted_at'        => (clone $at)->addMinutes(90),
        ]);
    }

    // ── Non-completed lifecycle states ──────────────────────────────────────

    /**
     * @param  array<int,Team>            $teams
     * @param  array<string,DebateFormat> $formats
     */
    private function makeLifecycleDebates(array $teams, array $judges, array $motions, array $formats, User $admin): void
    {
        $format = $formats['no_reply'];
        $n      = 0;

        // 2 scheduled — motion assigned but not revealed, nobody assigned yet.
        for ($i = 0; $i < 2; $i++) {
            $at = Carbon::now()->addDays(mt_rand(6, 20));
            $this->baseDebate($format, $motions, $admin, $teams, "sched-{$i}", $at, [
                'status'              => 'scheduled',
                'proposition_team_id' => null,
                'opposition_team_id'  => null,
            ]);
            $n++;
        }

        // 2 announced — rosters pooled with side=null/pending (what announce()
        // actually does; sides are only chosen when prep rooms open), judges on.
        for ($i = 0; $i < 2; $i++) {
            $at     = Carbon::now()->addDays(mt_rand(3, 6));
            $propT  = $teams[$i * 2];
            $oppT   = $teams[$i * 2 + 1];
            $debate = $this->baseDebate($format, $motions, $admin, $teams, "ann-{$i}", $at, [
                'status'              => 'announced',
                'proposition_team_id' => $propT->id,
                'opposition_team_id'  => $oppT->id,
                'motion_revealed_at'  => Carbon::now()->subHours(2),
            ]);

            foreach ([$propT, $oppT] as $team) {
                foreach ($this->rosterFor($team, 3) as $user) {
                    DebateParticipant::create([
                        'debate_id' => $debate->id,
                        'user_id'   => $user->id,
                        'team_id'   => $team->id,
                        'role'      => 'debater',
                        'side'      => null,
                        'status'    => 'pending',
                    ]);
                }
            }
            $this->makeJudges($debate, $judges, 2, Carbon::now());
            $n++;
        }

        // 1 teams-selected — sides resolved, roster approved, phases laid out.
        $at           = Carbon::now()->addHours(6);
        $propT        = $teams[4];
        $oppT         = $teams[5];
        $propSpeakers = $this->rosterFor($propT, 3);
        $oppSpeakers  = $this->rosterFor($oppT, 3);

        // Speaker orders go in with the insert — see the ON UPDATE note above.
        $debate = $this->baseDebate($format, $motions, $admin, $teams, 'tsel', $at, [
            'status'               => 'teams-selected',
            'proposition_team_id'  => $propT->id,
            'opposition_team_id'   => $oppT->id,
            'motion_revealed_at'   => Carbon::now()->subHours(18),
            'prep_rooms_opened_at' => Carbon::now()->subMinutes(30),
            'prop_speaker_order'   => array_map(fn ($u) => $u->id, $propSpeakers),
            'opp_speaker_order'    => array_map(fn ($u) => $u->id, $oppSpeakers),
        ]);

        $this->makeSpeakers($debate, $propT, $propSpeakers, 'proposition');
        $this->makeSpeakers($debate, $oppT, $oppSpeakers, 'opposition');
        $this->makeJudges($debate, $judges, 3, Carbon::now());

        foreach ($format->deriveStages() as $stage) {
            DebatePhase::create([
                'debate_id'        => $debate->id,
                'name'             => $stage['name'],
                'order_index'      => $stage['order_index'],
                'duration_seconds' => $stage['duration_seconds'],
                'status'           => 'pending',
                'is_reply'         => $stage['is_reply'],
            ]);
        }
        $n++;

        // 1 cancelled.
        $this->baseDebate($format, $motions, $admin, $teams, 'cancel', Carbon::now()->subDays(3), [
            'status'              => 'cancelled',
            'proposition_team_id' => $teams[6]->id,
            'opposition_team_id'  => $teams[7]->id,
            'motion_revealed_at'  => Carbon::now()->subDays(4),
            'cancellation_reason' => 'انسحاب أحد الفريقين قبل موعد المناظرة بيوم واحد.',
        ]);
        $n++;

        $this->counts['debates (non-completed states)'] = $n;
    }

    /** @param array<string,mixed> $overrides */
    private function baseDebate(DebateFormat $format, array $motions, User $admin, array $teams, string $slug, Carbon $at, array $overrides): Debate
    {
        return Debate::create(array_merge([
            'format_id'         => $format->id,
            'motion_id'         => $motions[array_rand($motions)]->id,
            'created_by'        => $admin->id,
            'title'             => 'مناظرة قادمة ضمن بيانات الاختبار',
            'description'       => 'مناظرة ضمن بيانات الاختبار.',
            'tag'               => self::DEBATE_TAGS[array_rand(self::DEBATE_TAGS)],
            'livekit_room_name' => "seed-{$slug}-main",
            'prop_room_name'    => "seed-{$slug}-prop",
            'opp_room_name'     => "seed-{$slug}-opp",
            'result_room_name'  => "seed-{$slug}-result",
            'scheduled_at'      => $at,
            'current_stage'     => 0,
        ], $overrides));
    }

    // ── Feedback ────────────────────────────────────────────────────────────

    /** @param array<int,array<string,mixed>> $debates */
    private function makeFeedback(array $debates): void
    {
        $rated  = 0;
        $judged = 0;

        foreach ($debates as $entry) {
            /** @var Debate $debate */
            $debate = $entry['debate'];
            $panel  = $entry['panel'];
            $all    = array_merge($entry['sides']['proposition'], $entry['sides']['opposition']);
            $at     = (clone $debate->scheduled_at)->addMinutes(100);

            // rating_debate — 1-2 participants rate the debate itself.
            foreach (collect($all)->shuffle()->take(mt_rand(1, 2)) as $p) {
                Feedbacks::forceCreate([
                    'debate_id'    => $debate->id,
                    'from_user_id' => $p->user_id,
                    'to_user_id'   => null,
                    'type'         => 'rating_debate',
                    'content'      => self::RATING_NOTES_DEBATE[array_rand(self::RATING_NOTES_DEBATE)],
                    'scores'       => ['rating' => $this->skewedRating()],
                    'created_at'   => $at,
                    'updated_at'   => $at,
                ]);
                $rated++;
            }

            // rating_judgement — targets one real judge from this debate's panel.
            $judge = $panel[array_rand($panel)];
            $rater = $all[array_rand($all)];

            Feedbacks::forceCreate([
                'debate_id'    => $debate->id,
                'from_user_id' => $rater->user_id,
                'to_user_id'   => $judge->user_id,
                'type'         => 'rating_judgement',
                'content'      => self::RATING_NOTES_JUDGE[array_rand(self::RATING_NOTES_JUDGE)],
                'scores'       => ['rating' => $this->skewedRating()],
                'created_at'   => $at,
                'updated_at'   => $at,
            ]);
            $judged++;
        }

        $this->counts['feedbacks (rating_debate)']    = $rated;
        $this->counts['feedbacks (rating_judgement)'] = $judged;
    }

    /** 1-5, skewed high the way real rating data is. */
    private function skewedRating(): int
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 4  => 1,
            $roll <= 12 => 2,
            $roll <= 30 => 3,
            $roll <= 65 => 4,
            default     => 5,
        };
    }

    // ── Summary ─────────────────────────────────────────────────────────────

    private function report(): void
    {
        $this->command->newLine();
        $this->command->info('Comprehensive test data seeded.');
        $this->command->newLine();

        $rows = [];
        foreach ($this->counts as $label => $n) {
            $rows[] = [$label, $n];
        }
        $this->command->table(['Entity', 'Created'], $rows);

        $this->command->newLine();
        $this->command->info('Sample logins (password for ALL seeded accounts: ' . self::PASSWORD . ')');
        $this->command->table(
            ['Role', 'Email', 'Password'],
            [
                ['debater', 'debater1' . self::DOMAIN, self::PASSWORD],
                ['judge',   'judge1' . self::DOMAIN,   self::PASSWORD],
                ['trainer', 'trainer1' . self::DOMAIN, self::PASSWORD],
                ['admin',   'admin1' . self::DOMAIN,   self::PASSWORD],
            ]
        );

        $this->command->newLine();
        $this->command->warn('Cleanup (removes everything above via FK cascade):');
        $this->command->line("  User::where('email','like','%" . self::DOMAIN . "')->delete();");
        $this->command->line("  DebateFormat::where('name','like','" . self::FORMAT_MARK . "%')->delete();");
        $this->command->newLine();
    }
}
