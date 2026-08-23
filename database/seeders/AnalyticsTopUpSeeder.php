<?php

namespace Database\Seeders;

use App\Models\Complaint;
use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Models\DebateResult;
use App\Models\Feedbacks;
use App\Models\Motion;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TOP-UP seeder for the analytics surfaces — coach/team analysis, judge
 * ratings, and complaint accountability.
 *
 * OPT-IN ONLY. Run with:
 *   php artisan db:seed --class=AnalyticsTopUpSeeder
 *
 * This is a top-up, NOT a rebuild. Every block queries the current state of
 * its target and inserts only what is missing to reach the threshold. No
 * existing row is ever updated or deleted, so running it twice is safe: the
 * second run finds the thresholds already met and inserts nothing.
 *
 * Operates entirely on the @jadal-seed-test.local dataset created by
 * ComprehensiveTestDataSeeder, so the same cleanup still removes everything:
 *   User::where('email','like','%@jadal-seed-test.local')->delete();
 *
 * ─── Why the debate-construction code below is duplicated ───────────────────
 * Reusing ComprehensiveTestDataSeeder's methods directly is not possible.
 * Every one of them (makeSpeakers / makeJudges / makePhasesAndScores /
 * makeResult / realisticScore) is declared `private`, so no separate class can
 * call them, and widening them would mean editing a second file. They are
 * therefore reproduced here verbatim. The part that actually matters — the
 * debate_results.scores JSON shape, {"stages":[{stage_order, participant_id,
 * user_id, score}], "notes":…} — is byte-identical, as is the phase/participant
 * construction. If those methods are ever made protected, this class should
 * extend ComprehensiveTestDataSeeder and drop the copies.
 *
 * ─── Verified against the consuming services before writing ─────────────────
 * - TeamStatsService::participationRows() finds a team's debates through
 *   debates.proposition_team_id / opposition_team_id (NOT
 *   debate_participants.team_id), requires status='completed' AND
 *   result_revealed_at, and attributes stage scores to the team through
 *   debate_participants.team_id + role='debater'.
 * - TeamParticipationRow::speakerIds() sorts and de-duplicates, so
 *   TeamStatsService::combinations() groups on the SET of user ids that have a
 *   scored stage entry — order-insensitive.
 * - JudgeRatingStatsService buckets by debate.scheduled_at (not the feedback's
 *   own timestamp) and counts debates_judged as approved judge participants on
 *   completed + revealed debates.
 * - ComplaintAccountabilityService drops any (user, role) entry whose
 *   completed-debate exposure in that capacity is below min_debates_involved,
 *   which DEFAULTS TO 3. A complaint target therefore needs >= 3 completed
 *   debates in the matching capacity or it never appears in the report.
 */
class AnalyticsTopUpSeeder extends Seeder
{
    private const DOMAIN = '@jadal-seed-test.local';

    private const TRAINER_RICH   = 'trainer1' . self::DOMAIN;
    private const TRAINER_SPARSE = 'trainer8' . self::DOMAIN;
    private const JUDGE_RICH     = 'judge1' . self::DOMAIN;
    private const JUDGE_SPARSE   = 'judge2' . self::DOMAIN;
    private const TRAINER_BLAMED = 'trainer2' . self::DOMAIN;

    /** Coverage thresholds this seeder tops up to. */
    private const TEAMS_TARGET          = 3;
    private const LARGEST_TEAM_MEMBERS  = 8;
    private const TEAM_DEBATES_TARGET   = 12;
    private const JUDGE_RATINGS_TARGET  = 15;
    private const JUDGE_DEBATES_TARGET  = 6;
    private const MIN_DEBATES_INVOLVED  = 3;   // ComplaintAccountabilityService default

    private const RANDOM_SEED = 20260812;

    /** @var array<string,int> */
    private array $counts = [];

    /** @var array<int,string> */
    private array $notes = [];

    private ?Team $largestTeam = null;

    public function run(): void
    {
        mt_srand(self::RANDOM_SEED);

        $missing = [];
        foreach ([self::TRAINER_RICH, self::TRAINER_SPARSE, self::JUDGE_RICH, self::JUDGE_SPARSE] as $email) {
            if (! User::where('email', $email)->exists()) {
                $missing[] = $email;
            }
        }

        if (! empty($missing)) {
            $this->command->error('Aborted — target users not found: ' . implode(', ', $missing));
            $this->command->warn('Run ComprehensiveTestDataSeeder first.');

            return;
        }

        DB::transaction(function (): void {
            $this->taskOneCoachAnalysis();
            $this->taskTwoJudgeRatings();
            $this->taskThreeComplaints();
            $this->confirmSparseCases();
        });

        $this->report();
    }

    // ════════════════════════════════════════════════════════════════════════
    // TASK 1 — coach / team analysis for trainer1
    // ════════════════════════════════════════════════════════════════════════

    private function taskOneCoachAnalysis(): void
    {
        $trainer = $this->user(self::TRAINER_RICH);
        $admin   = User::where('role', 'admin')->where('email', 'like', '%' . self::DOMAIN)->orderBy('id')->first()
            ?? User::where('role', 'admin')->orderBy('id')->firstOrFail();

        // The largest team is resolved BEFORE any new teams are created, so
        // topping it up cannot be confused by the teams added below.
        $this->largestTeam = $this->resolveLargestTeam($trainer);

        $this->topUpLargestTeamMembers($this->largestTeam);
        $this->topUpTeamDebates($this->largestTeam, $trainer, $admin);
        $this->topUpTeamCount($trainer);
    }

    private function resolveLargestTeam(User $trainer): Team
    {
        $teams = Team::where('created_by', $trainer->id)->where('is_random', false)->orderBy('id')->get();

        if ($teams->isEmpty()) {
            // Nothing to build on — create the anchor team.
            $team = $this->createTeam($trainer, 'فريق التحليل', 'active', 4);
            $this->note("created anchor team [{$team->id}] for {$trainer->email} (had none)");

            return $team;
        }

        $best = null;
        $max  = -1;
        foreach ($teams as $team) {
            $n = TeamMember::where('team_id', $team->id)->where('status', 'current')->count();
            if ($n > $max) {
                $max  = $n;
                $best = $team;
            }
        }

        return $best;
    }

    /** Bring the largest team to >= 8 current members. */
    private function topUpLargestTeamMembers(Team $team): void
    {
        $current = TeamMember::where('team_id', $team->id)->where('status', 'current')->count();
        $needed  = max(0, self::LARGEST_TEAM_MEMBERS - $current);

        if ($needed === 0) {
            $this->note("team [{$team->id}] already has {$current} current members");

            return;
        }

        foreach ($this->availableDebaters($team, $needed) as $i => $user) {
            TeamMember::create([
                'team_id'  => $team->id,
                'user_id'  => $user->id,
                'priority' => 50 + $i,
                'status'   => 'current',
            ]);
            $this->bump('team_members (current, added to largest team)');
        }
    }

    /**
     * Debaters usable as members of $team: seeded debaters who hold no row on
     * this team at all (current or past), lowest id first for determinism.
     *
     * @return \Illuminate\Support\Collection<int,User>
     */
    private function availableDebaters(Team $team, int $n, array $excludeIds = []): \Illuminate\Support\Collection
    {
        $taken = TeamMember::where('team_id', $team->id)->pluck('user_id')->all();

        return User::where('role', 'debater')
            ->where('email', 'like', '%' . self::DOMAIN)
            ->whereNotIn('id', array_merge($taken, $excludeIds))
            ->orderBy('id')
            ->limit($n)
            ->get();
    }

    /**
     * Bring the team to >= 12 completed + revealed debates, while satisfying
     * the line-up-variety requirement that combinations() groups on.
     */
    private function topUpTeamDebates(Team $team, User $trainer, User $admin): void
    {
        $existing = $this->completedDebatesFor($team);
        $needed   = max(0, self::TEAM_DEBATES_TARGET - $existing->count());

        $this->note("team [{$team->id}] had {$existing->count()} completed+revealed debates");

        if ($needed === 0 && $this->lineupSetsFor($team)['distinct'] >= 4) {
            return;
        }

        $roster = TeamMember::where('team_id', $team->id)
            ->where('status', 'current')
            ->orderBy('priority')
            ->pluck('user_id')
            ->map(fn ($id) => User::find($id))
            ->filter()
            ->values();

        if ($roster->count() < 6) {
            $this->note('WARNING: roster too small to build distinct line-ups');

            return;
        }

        // A member who plays and is THEN marked as having left, so the "past
        // member with real match history" requirement is backed by scored
        // stage entries rather than a bare status flag.
        $leaver = $this->resolveLeaver($team);

        // Four distinct sets. combinations() groups on the sorted set of user
        // ids with a scored stage entry, so these must differ as SETS.
        $setA = [$roster[0], $roster[1], $roster[2]];                 // the historical line-up
        $setB = [$roster[3], $roster[4], $roster[5]];
        $setC = [$roster[0], $roster[3], $leaver];                    // includes the leaver
        $setD = [$roster[1], $roster[4], $roster[count($roster) - 1]];

        // B repeated 3x guarantees "one exact set repeated in >= 3 debates"
        // even if the historical data is ever cleared.
        $plan = [$setB, $setB, $setB, $setC, $setD];
        while (count($plan) < $needed) {
            $plan[] = $setC;
        }
        $plan = array_slice($plan, 0, max($needed, 5));

        $opponents = Team::where('id', '!=', $team->id)
            ->where('is_random', false)
            ->orderBy('id')
            ->get();

        if ($opponents->isEmpty()) {
            $this->note('WARNING: no opponent teams available');

            return;
        }

        $formats = DebateFormat::where('name', 'like', '[SEED]%')->orderBy('id')->get();
        if ($formats->isEmpty()) {
            $formats = DebateFormat::orderBy('id')->get();
        }

        $motions = Motion::orderBy('id')->get();
        $judges  = User::where('role', 'judge')->where('email', 'like', '%' . self::DOMAIN)->orderBy('id')->get();

        // Spread across months the team does not already cover, so the >= 4
        // distinct calendar months requirement holds on scheduled_at.
        $usedMonths = $existing->map(fn ($d) => $d->scheduled_at->format('Y-m'))->unique()->values()->all();
        $monthPool  = [1, 2, 3, 4, 5, 6, 7, 8];

        foreach ($plan as $i => $speakers) {
            $opponent = $opponents[$i % $opponents->count()];
            $format   = $formats[$i % $formats->count()];
            $motion   = $motions[($i * 3) % $motions->count()];

            $monthsBack = $monthPool[$i % count($monthPool)];
            $at = Carbon::now()->subMonthsNoOverflow($monthsBack)
                ->setDay(min(10 + $i, 27))
                ->setTime(17 + ($i % 3), [0, 15, 30][$i % 3]);

            // Alternate sides so wins and losses cannot all land one way.
            $teamIsProp = $i % 2 === 0;

            $oppRoster = TeamMember::where('team_id', $opponent->id)
                ->where('status', 'current')
                ->orderBy('priority')
                ->limit(3)
                ->pluck('user_id')
                ->map(fn ($id) => User::find($id))
                ->filter()
                ->values()
                ->all();

            if (count($oppRoster) < 3) {
                continue;
            }

            $this->createCompletedDebate(
                propTeam:     $teamIsProp ? $team : $opponent,
                oppTeam:      $teamIsProp ? $opponent : $team,
                propSpeakers: $teamIsProp ? $speakers : $oppRoster,
                oppSpeakers:  $teamIsProp ? $oppRoster : $speakers,
                judges:       $judges->all(),
                motion:       $motion,
                format:       $format,
                admin:        $admin,
                at:           $at,
                slug:         "t1-{$team->id}-{$i}",
            );
            $this->bump('debates (completed, for coach analysis)');

            if (! empty($usedMonths)) {
                $usedMonths[] = $at->format('Y-m');
            }
        }

        // Only once the leaver actually has match history does the past-member
        // row get written.
        $this->markLeaverAsPast($team, $leaver);
    }

    /**
     * Prefer a member who already has scored history but no 'past' row; failing
     * that, take a debater who is on no roster for this team and let the new
     * debates give them history.
     */
    private function resolveLeaver(Team $team): User
    {
        $alreadyQualified = $this->pastMemberWithHistory($team);
        if ($alreadyQualified) {
            $this->note("team [{$team->id}] already has a past member with match history (user {$alreadyQualified->id})");

            return $alreadyQualified;
        }

        $candidate = $this->availableDebaters($team, 1)->first();

        if (! $candidate) {
            // Fall back to any seeded debater not already 'past' here.
            $pastIds = TeamMember::where('team_id', $team->id)->where('status', 'past')->pluck('user_id')->all();
            $candidate = User::where('role', 'debater')
                ->where('email', 'like', '%' . self::DOMAIN)
                ->whereNotIn('id', $pastIds)
                ->orderBy('id')
                ->firstOrFail();
        }

        return $candidate;
    }

    /** A past member of $team who has at least one scored stage entry for it. */
    private function pastMemberWithHistory(Team $team): ?User
    {
        $pastIds = TeamMember::where('team_id', $team->id)->where('status', 'past')->pluck('user_id')->all();
        if (empty($pastIds)) {
            return null;
        }

        foreach ($this->completedDebatesFor($team) as $debate) {
            $memberIds = DebateParticipant::where('debate_id', $debate->id)
                ->where('team_id', $team->id)
                ->where('role', 'debater')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach (($debate->result->scores['stages'] ?? []) as $stage) {
                $uid = (int) ($stage['user_id'] ?? 0);
                if (isset($stage['score']) && in_array($uid, $pastIds, true) && in_array($uid, $memberIds, true)) {
                    return User::find($uid);
                }
            }
        }

        return null;
    }

    private function markLeaverAsPast(Team $team, User $leaver): void
    {
        $exists = TeamMember::where('team_id', $team->id)->where('user_id', $leaver->id)->exists();
        if ($exists) {
            return;
        }

        TeamMember::create([
            'team_id'  => $team->id,
            'user_id'  => $leaver->id,
            'priority' => 95,
            'status'   => 'past',
        ]);
        $this->bump('team_members (past, with match history)');
        $this->note("user {$leaver->id} marked 'past' on team [{$team->id}] after playing");
    }

    /** Ensure trainer1 owns >= 3 non-random teams, at least one inactive. */
    private function topUpTeamCount(User $trainer): void
    {
        $teams = Team::where('created_by', $trainer->id)->where('is_random', false)->get();
        $need  = max(0, self::TEAMS_TARGET - $teams->count());

        $names = ['فريق الاستدلال', 'فريق المرافعة', 'فريق التحليل الثاني'];

        for ($i = 0; $i < $need; $i++) {
            // First new team is inactive, satisfying the inactive requirement
            // without ever touching an existing row.
            $status = $i === 0 ? 'inactive' : 'active';
            $team   = $this->createTeam($trainer, $names[$i % count($names)], $status, 4);
            $this->bump('teams (added for trainer1)');
            $this->note("created team [{$team->id}] status={$status}");
        }

        $teams = Team::where('created_by', $trainer->id)->where('is_random', false)->get();
        if (! $teams->contains(fn (Team $t) => $t->status === 'inactive')) {
            // Existing rows are never modified, so an inactive one is added.
            $team = $this->createTeam($trainer, 'فريق مؤرشف', 'inactive', 4);
            $this->bump('teams (added for trainer1)');
            $this->note("no inactive team existed; created [{$team->id}] rather than modifying an existing row");
        }
    }

    private function createTeam(User $trainer, string $name, string $status, int $memberCount): Team
    {
        $pool = User::where('role', 'debater')
            ->where('email', 'like', '%' . self::DOMAIN)
            ->orderBy('id')
            ->limit(200)
            ->get();

        // Prefer debaters carrying the fewest current memberships.
        $sorted = $pool->sortBy(fn (User $u) => TeamMember::where('user_id', $u->id)->where('status', 'current')->count())
            ->values()
            ->take($memberCount);

        $leader = $sorted->first();

        $team = Team::create([
            'name'       => $name,
            'leader_id'  => $leader->id,
            'created_by' => $trainer->id,
            'is_random'  => false,
            'status'     => $status,
            'points'     => mt_rand(0, 200),
        ]);

        foreach ($sorted as $i => $member) {
            TeamMember::create([
                'team_id'  => $team->id,
                'user_id'  => $member->id,
                'priority' => $i + 1,
                'status'   => 'current',
            ]);
            $this->bump('team_members (current, new teams)');
        }

        return $team;
    }

    // ════════════════════════════════════════════════════════════════════════
    // TASK 2 — judge ratings for judge1
    // ════════════════════════════════════════════════════════════════════════

    private function taskTwoJudgeRatings(): void
    {
        $judge = $this->user(self::JUDGE_RICH);

        $judgedDebates = $this->debatesJudgedBy($judge);
        $this->note("judge1 judges {$judgedDebates->count()} completed+revealed debates");

        if ($judgedDebates->count() < self::JUDGE_DEBATES_TARGET) {
            $this->note('WARNING: judge1 judges fewer than ' . self::JUDGE_DEBATES_TARGET . ' debates; ratings limited to what exists');
        }

        $existing = Feedbacks::where('type', 'rating_judgement')->where('to_user_id', $judge->id)->count();
        $needed   = max(0, self::JUDGE_RATINGS_TARGET - $existing);

        $this->note("judge1 had {$existing} rating_judgement rows");

        // Ratings already present, so the 1-5 span can be completed rather than
        // restarted. Existing seeded data skews to 4/5.
        $have = Feedbacks::where('type', 'rating_judgement')->where('to_user_id', $judge->id)
            ->pluck('scores')
            ->map(fn ($s) => is_array($s) ? ($s['rating'] ?? null) : (json_decode((string) $s, true)['rating'] ?? null))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $missingRatings = array_values(array_diff([1, 2, 3, 4, 5], $have));

        // Deliberately fewer rated debates than judged debates, so coverage
        // stays a genuine fraction: at most two-thirds of the judged set is
        // used, and some debates carry two raters.
        $usable = $judgedDebates->sortBy(fn (Debate $d) => $d->scheduled_at->timestamp)->values();
        $cap    = max(1, (int) floor($usable->count() * 2 / 3));
        $target = $usable->take($cap);

        if ($target->isEmpty() || $needed === 0) {
            $this->note('judge1 ratings already at target; nothing inserted');
        } else {
            $ratingQueue = array_merge($missingRatings, [5, 4, 3, 5, 4, 2, 5, 4, 3, 5, 4, 1]);
            $inserted    = 0;
            $qi          = 0;

            foreach ($target as $debate) {
                if ($inserted >= $needed) {
                    break;
                }

                // Two raters on some debates keeps ratings_count above
                // debates_rated without widening the rated-debate set.
                $ratersWanted = $inserted + 2 <= $needed ? 2 : 1;

                foreach ($this->ratersFor($debate, $judge, $ratersWanted) as $rater) {
                    if ($inserted >= $needed) {
                        break;
                    }
                    if ($this->ratingExists($debate, $rater, $judge)) {
                        continue;
                    }

                    $this->createRating($debate, $rater, $judge, $ratingQueue[$qi % count($ratingQueue)]);
                    $qi++;
                    $inserted++;
                }
            }

            $this->bump('feedbacks (rating_judgement -> judge1)', $inserted);
        }

        $this->ensureMultiJudgeRatedDebate($judge);
        $this->ensurePeerGroup($judge);
    }

    /** @return \Illuminate\Support\Collection<int,Debate> */
    private function debatesJudgedBy(User $judge): \Illuminate\Support\Collection
    {
        $ids = DebateParticipant::where('user_id', $judge->id)
            ->where('role', 'judge')
            ->where('status', 'approved')
            ->pluck('debate_id');

        return Debate::whereIn('id', $ids)
            ->where('status', 'completed')
            ->whereNotNull('result_revealed_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Participants of $debate who may rate it: StoreFeedbackRequest requires
     * the rater to be a participant, and the rated user to be a judge of that
     * same debate. The judge never rates themselves.
     *
     * @return \Illuminate\Support\Collection<int,User>
     */
    private function ratersFor(Debate $debate, User $judge, int $n): \Illuminate\Support\Collection
    {
        return DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', '!=', $judge->id)
            ->where('role', 'debater')
            ->orderBy('id')
            ->pluck('user_id')
            ->unique()
            ->map(fn ($id) => User::find($id))
            ->filter()
            ->take($n);
    }

    private function ratingExists(Debate $debate, User $from, User $to): bool
    {
        return Feedbacks::where('debate_id', $debate->id)
            ->where('from_user_id', $from->id)
            ->where('to_user_id', $to->id)
            ->where('type', 'rating_judgement')
            ->exists();
    }

    private function createRating(Debate $debate, User $from, User $to, int $rating): void
    {
        $notes = [
            1 => 'قرار غير مقنع ولم يوضح أسبابه.',
            2 => 'التعليل كان مختصراً أكثر من اللازم.',
            3 => 'تحكيم مقبول مع ملاحظات عامة.',
            4 => 'الملاحظات كانت مفيدة ومحددة.',
            5 => 'تحكيم عادل وتعليل واضح للقرار.',
        ];

        $at = (clone $debate->scheduled_at)->addMinutes(110);

        Feedbacks::forceCreate([
            'debate_id'    => $debate->id,
            'from_user_id' => $from->id,
            'to_user_id'   => $to->id,
            'type'         => 'rating_judgement',
            'content'      => $notes[$rating],
            'scores'       => ['rating' => $rating],
            'created_at'   => $at,
            'updated_at'   => $at,
        ]);
    }

    /**
     * At least one debate with two judges, each carrying their own correctly
     * attributed rating row.
     */
    private function ensureMultiJudgeRatedDebate(User $judge): void
    {
        foreach ($this->debatesJudgedBy($judge) as $debate) {
            $others = DebateParticipant::where('debate_id', $debate->id)
                ->where('role', 'judge')
                ->where('status', 'approved')
                ->where('user_id', '!=', $judge->id)
                ->pluck('user_id');

            if ($others->isEmpty()) {
                continue;
            }

            $other  = User::find($others->first());
            $raters = $this->ratersFor($debate, $judge, 2);

            if ($raters->count() < 2 || ! $other) {
                continue;
            }

            $added = 0;
            if (! $this->ratingExists($debate, $raters[0], $judge)) {
                $this->createRating($debate, $raters[0], $judge, 5);
                $added++;
            }
            if (! $this->ratingExists($debate, $raters[1], $other)) {
                $this->createRating($debate, $raters[1], $other, 3);
                $added++;
            }

            if ($added > 0) {
                $this->bump('feedbacks (multi-judge debate)', $added);
            }
            $this->note("multi-judge rated debate: [{$debate->id}] judges {$judge->id} + {$other->id}");

            return;
        }

        $this->note('WARNING: no multi-judge debate found for judge1');
    }

    /** >= 2 judges other than judge1 must carry ratings, or peer_average is null. */
    private function ensurePeerGroup(User $judge): void
    {
        $peers = Feedbacks::where('type', 'rating_judgement')
            ->whereNotNull('to_user_id')
            ->where('to_user_id', '!=', $judge->id)
            ->distinct()
            ->count('to_user_id');

        if ($peers >= 2) {
            $this->note("peer group already has {$peers} other rated judge(s)");

            return;
        }

        $added = 0;
        $candidates = User::where('role', 'judge')
            ->where('email', 'like', '%' . self::DOMAIN)
            ->where('id', '!=', $judge->id)
            ->orderBy('id')
            ->get();

        foreach ($candidates as $peer) {
            if ($added >= 2) {
                break;
            }
            foreach ($this->debatesJudgedBy($peer)->take(1) as $debate) {
                $rater = $this->ratersFor($debate, $peer, 1)->first();
                if ($rater && ! $this->ratingExists($debate, $rater, $peer)) {
                    $this->createRating($debate, $rater, $peer, 4);
                    $added++;
                }
            }
        }

        if ($added > 0) {
            $this->bump('feedbacks (peer judges)', $added);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // TASK 3 — complaints
    // ════════════════════════════════════════════════════════════════════════

    private function taskThreeComplaints(): void
    {
        // A complaint only surfaces in the accountability report if its target
        // has >= min_debates_involved (default 3) completed debates in the
        // matching capacity, so each target is chosen by real exposure.
        $targets = [
            'debater' => $this->exposedUser('debater'),
            'judge'   => $this->exposedUser('judge'),
            'chair'   => $this->exposedUser('chair'),
            'trainer' => $this->ensureTrainerExposure(),
        ];

        foreach ($targets as $role => $user) {
            if (! $user) {
                $this->note("WARNING: no user with >= " . self::MIN_DEBATES_INVOLVED . " completed debates as '{$role}'; complaint would be hidden by the default filter");
                continue;
            }

            $have = Complaint::where('target_role', $role)->count();
            if ($have > 0) {
                $this->note("target_role '{$role}' already has {$have} complaint(s)");
                continue;
            }

            $this->createComplaintSet($role, $user);
        }

        $this->topUpComplaintMonths();
        $this->topUpComplaintResolutionTimes();
    }

    /**
     * A user with >= MIN_DEBATES_INVOLVED completed + revealed debates in the
     * given capacity. 'chair' means a judge participant with is_chair.
     */
    private function exposedUser(string $capacity): ?User
    {
        $q = DB::table('debate_participants as dp')
            ->join('debates as d', 'd.id', '=', 'dp.debate_id')
            ->where('d.status', 'completed')
            ->whereNotNull('d.result_revealed_at')
            ->where('dp.role', $capacity === 'chair' ? 'judge' : $capacity)
            ->when($capacity === 'chair', fn ($q) => $q->where('dp.is_chair', true))
            ->groupBy('dp.user_id')
            ->selectRaw('dp.user_id, COUNT(DISTINCT dp.debate_id) as n')
            ->havingRaw('n >= ?', [self::MIN_DEBATES_INVOLVED])
            ->orderBy('dp.user_id')
            ->first();

        return $q ? User::find($q->user_id) : null;
    }

    /**
     * No trainer holds debate_participants rows in the seeded data, so a
     * trainer-targeted complaint would be invisible at the default
     * min_debates_involved. Attach a coach to completed debates his own team
     * played, which is both realistic and enough exposure.
     */
    private function ensureTrainerExposure(): ?User
    {
        $existing = $this->exposedUser('trainer');
        if ($existing) {
            $this->note("trainer '{$existing->email}' already has enough exposure");

            return $existing;
        }

        $trainer = User::where('email', self::TRAINER_BLAMED)->first()
            ?? User::where('role', 'trainer')->where('email', 'like', '%' . self::DOMAIN)
                ->where('email', '!=', self::TRAINER_RICH)->orderBy('id')->first();

        if (! $trainer) {
            return null;
        }

        $teamIds = Team::where('created_by', $trainer->id)->pluck('id');

        $debates = Debate::where('status', 'completed')
            ->whereNotNull('result_revealed_at')
            ->where(function ($q) use ($teamIds) {
                $q->whereIn('proposition_team_id', $teamIds)->orWhereIn('opposition_team_id', $teamIds);
            })
            ->orderBy('id')
            ->limit(self::MIN_DEBATES_INVOLVED + 1)
            ->get();

        if ($debates->count() < self::MIN_DEBATES_INVOLVED) {
            // Fall back to any completed debates so the capacity is reachable.
            $debates = Debate::where('status', 'completed')
                ->whereNotNull('result_revealed_at')
                ->orderBy('id')
                ->limit(self::MIN_DEBATES_INVOLVED + 1)
                ->get();
        }

        $added = 0;
        foreach ($debates as $debate) {
            $exists = DebateParticipant::where('debate_id', $debate->id)
                ->where('user_id', $trainer->id)
                ->exists();
            if ($exists) {
                continue;
            }

            DebateParticipant::create([
                'debate_id'         => $debate->id,
                'user_id'           => $trainer->id,
                'role'              => 'trainer',
                'side'              => 'trainer',
                'status'            => 'approved',
                'is_attended'       => true,
                'first_attended_at' => $debate->scheduled_at,
            ]);
            $added++;
        }

        if ($added > 0) {
            $this->bump('debate_participants (trainer exposure)', $added);
            $this->note("attached trainer {$trainer->id} to {$added} completed debates so trainer-role complaints are visible");
        }

        return $trainer;
    }

    /** One complaint per status for a role, spread across three months. */
    private function createComplaintSet(string $role, User $target): void
    {
        $filer = User::where('role', 'debater')
            ->where('email', 'like', '%' . self::DOMAIN)
            ->where('id', '!=', $target->id)
            ->orderBy('id')
            ->first();

        if (! $filer) {
            return;
        }

        $debate = DebateParticipant::where('user_id', $target->id)
            ->orderBy('debate_id')
            ->value('debate_id');

        $descriptions = [
            'debater' => 'سلوك غير لائق أثناء المناظرة وتجاوز على الفريق الآخر.',
            'trainer' => 'المدرب لم يحضر جلسة التحضير المتفق عليها.',
            'judge'   => 'تحيز واضح في التحكيم دون تبرير كافٍ.',
            'chair'   => 'رئيس الهيئة لم يلتزم بإدارة الوقت بشكل عادل.',
        ];

        $plan = [
            ['status' => 'open',      'monthsBack' => 1, 'gapHours' => 0],
            ['status' => 'resolved',  'monthsBack' => 2, 'gapHours' => 52],
            ['status' => 'dismissed', 'monthsBack' => 3, 'gapHours' => 27],
        ];

        foreach ($plan as $i => $row) {
            $createdAt = Carbon::now()->subMonthsNoOverflow($row['monthsBack'])
                ->setDay(min(6 + $i * 4, 26))
                ->setTime(10 + $i, 20);

            $this->insertComplaint(
                filer:       $filer,
                target:      $target,
                role:        $role,
                status:      $row['status'],
                description: $descriptions[$role] ?? 'شكوى ضمن بيانات الاختبار.',
                debateId:    $debate,
                createdAt:   $createdAt,
                gapHours:    $row['gapHours'],
            );
            $this->bump("complaints (target_role={$role})");
        }
    }

    /** Every status must appear across >= 3 distinct months overall. */
    private function topUpComplaintMonths(): void
    {
        $months = DB::table('complaints')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as m")
            ->distinct()
            ->pluck('m');

        $this->note('complaint months before top-up: ' . $months->count());

        foreach (['open', 'resolved', 'dismissed'] as $status) {
            $statusMonths = DB::table('complaints')
                ->where('status', $status)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as m")
                ->distinct()
                ->pluck('m');

            $need = max(0, 3 - $statusMonths->count());
            if ($need === 0) {
                continue;
            }

            $target = $this->exposedUser('debater');
            $filer  = User::where('role', 'debater')->where('email', 'like', '%' . self::DOMAIN)
                ->where('id', '!=', $target?->id)->orderBy('id')->first();

            if (! $target || ! $filer) {
                continue;
            }

            $added = 0;
            for ($back = 1; $back <= 8 && $added < $need; $back++) {
                $candidate = Carbon::now()->subMonthsNoOverflow($back)->setDay(14)->setTime(11, 5);
                if ($statusMonths->contains($candidate->format('Y-m'))) {
                    continue;
                }

                $this->insertComplaint(
                    filer:       $filer,
                    target:      $target,
                    role:        'debater',
                    status:      $status,
                    description: 'شكوى إضافية ضمن بيانات الاختبار لتغطية الأشهر.',
                    debateId:    null,
                    createdAt:   $candidate,
                    gapHours:    $status === 'open' ? 0 : 36,
                );

                $statusMonths->push($candidate->format('Y-m'));
                $added++;
                $this->bump("complaints (month coverage, {$status})");
            }
        }
    }

    /**
     * avg_time_to_last_update_hours_approx is updated_at − created_at on closed
     * rows, so rows written in the same second make the metric read as zero.
     */
    private function topUpComplaintResolutionTimes(): void
    {
        $meaningful = DB::table('complaints')
            ->whereIn('status', ['resolved', 'dismissed'])
            ->whereRaw('TIMESTAMPDIFF(SECOND, created_at, updated_at) >= 3600')
            ->count();

        if ($meaningful >= 3) {
            $this->note("{$meaningful} closed complaints already have a meaningful resolution gap");

            return;
        }

        $target = $this->exposedUser('debater');
        $filer  = User::where('role', 'debater')->where('email', 'like', '%' . self::DOMAIN)
            ->where('id', '!=', $target?->id)->orderBy('id')->first();

        if (! $target || ! $filer) {
            return;
        }

        $need  = 3 - $meaningful;
        $gaps  = [18, 61, 133];

        for ($i = 0; $i < $need; $i++) {
            $createdAt = Carbon::now()->subMonthsNoOverflow(1 + $i)->setDay(22)->setTime(9, 30);

            $this->insertComplaint(
                filer:       $filer,
                target:      $target,
                role:        'debater',
                status:      $i % 2 === 0 ? 'resolved' : 'dismissed',
                description: 'شكوى مغلقة بعد مراجعة إدارية استغرقت وقتاً.',
                debateId:    null,
                createdAt:   $createdAt,
                gapHours:    $gaps[$i % count($gaps)],
            );
            $this->bump('complaints (with resolution gap)');
        }
    }

    private function insertComplaint(
        User $filer,
        User $target,
        string $role,
        string $status,
        string $description,
        ?int $debateId,
        Carbon $createdAt,
        int $gapHours,
    ): void {
        $updatedAt = $gapHours > 0 ? (clone $createdAt)->addHours($gapHours) : (clone $createdAt);

        // forceCreate: created_at / updated_at are not in Complaint::$fillable,
        // and the gap between them is the whole point for the closed rows.
        Complaint::forceCreate([
            'filed_by'       => $filer->id,
            'debate_id'      => $debateId,
            'target_user_id' => $target->id,
            'target_role'    => $role,
            'description'    => $description,
            'status'         => $status,
            'admin_response' => in_array($status, ['resolved', 'dismissed'], true)
                ? 'تمت المراجعة واتخاذ الإجراء المناسب.'
                : null,
            'created_at'     => $createdAt,
            'updated_at'     => $updatedAt,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Sparse-case confirmation (read-only)
    // ════════════════════════════════════════════════════════════════════════

    private function confirmSparseCases(): void
    {
        $t8 = $this->user(self::TRAINER_SPARSE);
        $teams = Team::where('created_by', $t8->id)->where('is_random', false)->get();
        $debates = 0;
        foreach ($teams as $team) {
            $debates += $this->completedDebatesFor($team)->count();
        }
        $this->notes[] = sprintf(
            'SPARSE trainer8 (id=%d): %d team(s), %d completed debate(s) — untouched',
            $t8->id, $teams->count(), $debates
        );

        $j2 = $this->user(self::JUDGE_SPARSE);
        $n  = Feedbacks::where('type', 'rating_judgement')->where('to_user_id', $j2->id)->count();
        $this->notes[] = sprintf(
            'SPARSE judge2 (id=%d): %d rating_judgement row(s) — %s',
            $j2->id, $n,
            ($n >= 1 && $n <= 3) ? 'within 1-3, untouched' : 'OUTSIDE 1-3, reported not forced'
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // Debate construction — reproduced from ComprehensiveTestDataSeeder
    // ════════════════════════════════════════════════════════════════════════

    /**
     * One completed + result-revealed debate.
     *
     * Written as a SINGLE insert: debates.scheduled_at is
     * `ON UPDATE current_timestamp()`, so any follow-up UPDATE that does not
     * name the column silently resets the schedule to DB-now.
     *
     * @param  array<int,User>  $propSpeakers
     * @param  array<int,User>  $oppSpeakers
     * @param  array<int,User>  $judges
     */
    private function createCompletedDebate(
        Team $propTeam,
        Team $oppTeam,
        array $propSpeakers,
        array $oppSpeakers,
        array $judges,
        Motion $motion,
        DebateFormat $format,
        User $admin,
        Carbon $at,
        string $slug,
    ): Debate {
        $stages = $format->deriveStages();

        $debate = Debate::forceCreate([
            'format_id'             => $format->id,
            'motion_id'             => $motion->id,
            'proposition_team_id'   => $propTeam->id,
            'opposition_team_id'    => $oppTeam->id,
            'created_by'            => $admin->id,
            'title'                 => 'مناظرة ' . $propTeam->name . ' ضد ' . $oppTeam->name,
            'description'           => 'مناظرة مكتملة ضمن بيانات تحليلات الاختبار.',
            'tag'                   => 'تحليلات',
            'status'                => 'completed',
            'livekit_room_name'     => "topup-{$slug}-main",
            'prop_room_name'        => "topup-{$slug}-prop",
            'opp_room_name'         => "topup-{$slug}-opp",
            'result_room_name'      => "topup-{$slug}-result",
            'scheduled_at'          => $at,
            'motion_revealed_at'    => (clone $at)->subHours(24),
            'prep_rooms_opened_at'  => (clone $at)->subHour(),
            'live_started_at'       => $at,
            'started_at'            => $at,
            'speeches_completed_at' => (clone $at)->addMinutes(75),
            'ended_at'              => (clone $at)->addMinutes(75),
            'result_revealed_at'    => (clone $at)->addMinutes(95),
            'current_stage'         => count($stages) + 1,
            'timer_is_paused'       => false,
            'prop_speaker_order'    => array_map(fn (User $u) => $u->id, $propSpeakers),
            'opp_speaker_order'     => array_map(fn (User $u) => $u->id, $oppSpeakers),
            'created_at'            => $at,
            'updated_at'            => $at,
        ]);

        $bySide = [
            'proposition' => $this->makeSpeakers($debate, $propTeam, $propSpeakers, 'proposition'),
            'opposition'  => $this->makeSpeakers($debate, $oppTeam, $oppSpeakers, 'opposition'),
        ];
        $this->bump('debate_participants (debaters)', 6);

        $panel = $this->makeJudges($debate, $judges, mt_rand(2, 3), $at);
        $this->bump('debate_participants (judges)', count($panel));

        $stageData = $this->makePhasesAndScores($debate, $stages, $bySide, $at, false);
        $this->bump('debate_phases', count($stages));

        $this->makeResult($debate, $panel, $stageData, $at);
        $this->bump('debate_results');

        return $debate;
    }

    /** @return array<int,DebateParticipant> */
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
                'debate_id'          => $debate->id,
                'participant_id'     => $participant->id,
                'name'               => $stage['name'],
                'order_index'        => $order,
                'duration_seconds'   => $stage['duration_seconds'],
                'status'             => 'completed',
                'started_at'         => $startedAt,
                'ended_at'           => $endedAt,
                'poi_raised_count'   => mt_rand(0, 4),
                'poi_answered_count' => mt_rand(0, 2),
                'is_reply'           => $isReply,
            ]);

            $stageData[] = [
                'stage_order'    => $order,
                'participant_id' => $participant->id,
                'user_id'        => $participant->user_id,
                'score'          => $this->realisticScore(),
            ];
        }

        if ($forceBest) {
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
            $roll <= 6  => mt_rand(38, 54),
            $roll <= 88 => mt_rand(55, 85),
            default     => mt_rand(86, 96),
        };
    }

    /**
     * @param  array<int,DebateParticipant>    $panel
     * @param  array<int,array<string,mixed>>  $stageData
     */
    private function makeResult(Debate $debate, array $panel, array $stageData, Carbon $at): void
    {
        $propTotal = 0;
        $oppTotal  = 0;

        foreach ($stageData as $row) {
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
            // Same shape LiveDebateController::submitResult writes.
            'scores'              => ['stages' => $stageData, 'notes' => $notes],
            'summary_notes'       => $notes,
            'submitted_at'        => (clone $at)->addMinutes(90),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Helpers + summary
    // ════════════════════════════════════════════════════════════════════════

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** @return \Illuminate\Support\Collection<int,Debate> */
    private function completedDebatesFor(Team $team): \Illuminate\Support\Collection
    {
        return Debate::where(function ($q) use ($team) {
            $q->where('proposition_team_id', $team->id)->orWhere('opposition_team_id', $team->id);
        })
            ->where('status', 'completed')
            ->whereNotNull('result_revealed_at')
            ->with('result')
            ->orderBy('scheduled_at')
            ->get();
    }

    /** @return array{distinct:int, max_repeat:int, sets:array<string,int>} */
    private function lineupSetsFor(Team $team): array
    {
        $sets = [];

        foreach ($this->completedDebatesFor($team) as $debate) {
            if (! $debate->result) {
                continue;
            }

            $memberIds = DebateParticipant::where('debate_id', $debate->id)
                ->where('team_id', $team->id)
                ->where('role', 'debater')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $ids = [];
            foreach (($debate->result->scores['stages'] ?? []) as $stage) {
                $uid = (int) ($stage['user_id'] ?? 0);
                if (isset($stage['score']) && in_array($uid, $memberIds, true)) {
                    $ids[] = $uid;
                }
            }

            $ids = array_values(array_unique($ids));
            sort($ids);

            if (! empty($ids)) {
                $key        = implode('-', $ids);
                $sets[$key] = ($sets[$key] ?? 0) + 1;
            }
        }

        return [
            'distinct'   => count($sets),
            'max_repeat' => empty($sets) ? 0 : max($sets),
            'sets'       => $sets,
        ];
    }

    private function bump(string $key, int $n = 1): void
    {
        if ($n <= 0) {
            return;
        }
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $n;
    }

    private function note(string $line): void
    {
        $this->notes[] = $line;
    }

    private function report(): void
    {
        $this->command->newLine();
        $this->command->info('Analytics top-up complete.');
        $this->command->newLine();

        if (empty($this->counts)) {
            $this->command->line('  Nothing inserted — every threshold was already met.');
        } else {
            $rows = [];
            foreach ($this->counts as $label => $n) {
                $rows[] = [$label, $n];
            }
            $this->command->table(['Entity', 'Created'], $rows);
        }

        // ── verification of the coverage thresholds ────────────
        $trainer = $this->user(self::TRAINER_RICH);
        $judge   = $this->user(self::JUDGE_RICH);
        $team    = $this->largestTeam;

        $teams   = Team::where('created_by', $trainer->id)->where('is_random', false)->get();
        $checks  = [];

        $checks[] = ['trainer1 teams (>=3)', $teams->count(), $teams->count() >= 3 ? 'OK' : 'SHORT'];
        $checks[] = [
            'trainer1 inactive team (>=1)',
            $teams->where('status', 'inactive')->count(),
            $teams->where('status', 'inactive')->count() >= 1 ? 'OK' : 'SHORT',
        ];

        if ($team) {
            $team->refresh();
            $cur = TeamMember::where('team_id', $team->id)->where('status', 'current')->count();
            $debs = $this->completedDebatesFor($team);
            $months = $debs->map(fn (Debate $d) => $d->scheduled_at->format('Y-m'))->unique();
            $fw = collect();
            foreach ($debs as $d) {
                $fw = $fw->merge($d->motion?->frameworks?->pluck('id') ?? collect());
            }
            $lineups = $this->lineupSetsFor($team);
            $past    = $this->pastMemberWithHistory($team);

            $outcomes = [];
            foreach ($debs as $d) {
                if (! $d->result) {
                    continue;
                }
                $side = (int) $d->proposition_team_id === (int) $team->id ? 'proposition' : 'opposition';
                $key  = $d->result->winning_side === 'draw'
                    ? 'draw'
                    : ($d->result->winning_side === $side ? 'win' : 'loss');
                $outcomes[$key] = ($outcomes[$key] ?? 0) + 1;
            }

            $checks[] = ["largest team [{$team->id}] current members (>=8)", $cur, $cur >= 8 ? 'OK' : 'SHORT'];
            $checks[] = ['  completed+revealed debates (>=12)', $debs->count(), $debs->count() >= 12 ? 'OK' : 'SHORT'];
            $checks[] = ['  distinct months (>=4)', $months->count(), $months->count() >= 4 ? 'OK' : 'SHORT'];
            $checks[] = ['  distinct frameworks (>=2)', $fw->unique()->count(), $fw->unique()->count() >= 2 ? 'OK' : 'SHORT'];
            $checks[] = ['  distinct line-up sets (>=4)', $lineups['distinct'], $lineups['distinct'] >= 4 ? 'OK' : 'SHORT'];
            $checks[] = ['  max repeat of one set (>=3)', $lineups['max_repeat'], $lineups['max_repeat'] >= 3 ? 'OK' : 'SHORT'];
            $checks[] = ['  past member with match history', $past?->id ?? 0, $past ? 'OK' : 'SHORT'];
            $checks[] = ['  outcomes win/loss/draw', json_encode($outcomes), count($outcomes) >= 2 ? 'OK' : 'SHORT'];
        }

        $judged = $this->debatesJudgedBy($judge)->count();
        $rated  = Feedbacks::where('type', 'rating_judgement')->where('to_user_id', $judge->id)->count();
        $ratedDebates = Feedbacks::where('type', 'rating_judgement')->where('to_user_id', $judge->id)
            ->distinct()->count('debate_id');
        $ratingMonths = DB::table('feedbacks as f')->join('debates as d', 'd.id', '=', 'f.debate_id')
            ->where('f.type', 'rating_judgement')->where('f.to_user_id', $judge->id)
            ->selectRaw("DATE_FORMAT(d.scheduled_at, '%Y-%m') as m")->distinct()->pluck('m');
        $spread = Feedbacks::where('type', 'rating_judgement')->where('to_user_id', $judge->id)
            ->pluck('scores')
            ->map(fn ($s) => is_array($s) ? ($s['rating'] ?? null) : (json_decode((string) $s, true)['rating'] ?? null))
            ->filter()->unique()->sort()->values();
        $peers = Feedbacks::where('type', 'rating_judgement')->whereNotNull('to_user_id')
            ->where('to_user_id', '!=', $judge->id)->distinct()->count('to_user_id');

        $checks[] = ['judge1 debates judged (>=6)', $judged, $judged >= 6 ? 'OK' : 'SHORT'];
        $checks[] = ['judge1 rating rows (>=15)', $rated, $rated >= 15 ? 'OK' : 'SHORT'];
        $checks[] = ['judge1 rated debates < judged', "{$ratedDebates} < {$judged}", $ratedDebates < $judged ? 'OK' : 'SHORT'];
        $checks[] = ['judge1 rating months (>=3)', $ratingMonths->count(), $ratingMonths->count() >= 3 ? 'OK' : 'SHORT'];
        $checks[] = ['judge1 rating values seen', $spread->implode(','), $spread->contains(1) || $spread->contains(2) ? 'OK' : 'SHORT'];
        $checks[] = ['peer judges rated (>=2)', $peers, $peers >= 2 ? 'OK' : 'SHORT'];

        $cStatuses = DB::table('complaints')->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $cRoles    = DB::table('complaints')->whereNotNull('target_role')->where('target_role', '!=', '')
            ->selectRaw('target_role, count(*) c')->groupBy('target_role')->pluck('c', 'target_role');
        $cMonths   = DB::table('complaints')->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as m")->distinct()->pluck('m');
        $cGaps     = DB::table('complaints')->whereIn('status', ['resolved', 'dismissed'])
            ->whereRaw('TIMESTAMPDIFF(SECOND, created_at, updated_at) >= 3600')->count();

        $rolesOk = collect(['debater', 'trainer', 'judge', 'chair'])->every(fn ($r) => ($cRoles[$r] ?? 0) > 0);

        $checks[] = ['complaint months (>=3)', $cMonths->count(), $cMonths->count() >= 3 ? 'OK' : 'SHORT'];
        $checks[] = ['complaint target_roles all 4', json_encode($cRoles), $rolesOk ? 'OK' : 'SHORT'];
        $checks[] = ['complaint statuses', json_encode($cStatuses), 'INFO'];
        $checks[] = ['closed complaints with >=1h gap (>=3)', $cGaps, $cGaps >= 3 ? 'OK' : 'SHORT'];

        $this->command->newLine();
        $this->command->info('Threshold verification');
        $this->command->table(['Check', 'Value', 'Status'], $checks);

        $this->command->newLine();
        $this->command->info('Key ids / logins (password: Test1234!)');
        $rows = [
            ['trainer1 (rich coach)', self::TRAINER_RICH, $trainer->id],
            ['trainer8 (sparse coach)', self::TRAINER_SPARSE, $this->user(self::TRAINER_SPARSE)->id],
            ['judge1 (rich judge)', self::JUDGE_RICH, $judge->id],
            ['judge2 (sparse judge)', self::JUDGE_SPARSE, $this->user(self::JUDGE_SPARSE)->id],
        ];
        if ($team) {
            $rows[] = ['largest team (coach analysis)', $team->name, $team->id];
        }
        $this->command->table(['Role', 'Email / Name', 'Id'], $rows);

        if (! empty($this->notes)) {
            $this->command->newLine();
            $this->command->info('Notes');
            foreach ($this->notes as $n) {
                $this->command->line('  - ' . $n);
            }
        }

        $this->command->newLine();
        $this->command->warn('Cleanup is unchanged — this seeder adds nothing outside the seeded dataset:');
        $this->command->line("  User::where('email','like','%" . self::DOMAIN . "')->delete();");
        $this->command->line("  DebateFormat::where('name','like','[SEED]%')->delete();");
        $this->command->newLine();
    }
}
