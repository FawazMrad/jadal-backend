<?php

namespace App\Services\AdminStats;

use App\Services\Stats\DebaterStatsService;
use App\Services\Stats\ParticipationRow;
use App\Services\Stats\PositionCodes;
use App\Services\Stats\StatsFilter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stat 2 — Platform Leaderboards (rising stars / most improved / most active).
 *
 * The Improvement Index math is NOT re-implemented here: rows are built in
 * bulk (two queries for the whole platform — one for debates+results, one for
 * debater participations) into the mobile module's own ParticipationRow value
 * objects, then DebaterStatsService::improvement() — the exact code path the
 * mobile endpoint runs — is called per debater as pure in-memory math. Same
 * buckets, same regression, same consistency term, same config-driven
 * normalization/weights/bands; zero per-debater queries.
 *
 * Draw handling mirrors each board's own definition:
 *   - most_improved: draws excluded entirely (the mobile improvement pipeline
 *     filters winning_side to proposition/opposition at the source; matched
 *     here so both endpoints always agree for the same debater).
 *   - win_rate / avg_score / most_active: draws count in the denominator per
 *     the admin-stats glossary (a draw is a win for neither side).
 */
class PlatformLeaderboardService
{
    public const BOARDS = ['most_improved', 'win_rate', 'avg_score', 'most_active'];

    public function __construct(
        private DebaterStatsService $mobileStats,
        private StatsCache $cache,
    ) {}

    public function compute(AdminStatFilters $f, string $board, int $limit, int $minNDebates): array
    {
        $key = $f->cacheKeyPart() . ":{$board}:l{$limit}:min{$minNDebates}";

        return $this->cache->remember('leaderboard', $key, fn () => $this->build($f, $board, $limit, $minNDebates));
    }

    private function build(AdminStatFilters $f, string $board, int $limit, int $minNDebates): array
    {
        [$rowsByUser, $names] = $this->participationRowsByUser($f);

        $entries = [];

        foreach ($rowsByUser as $userId => $rows) {
            $entry = $this->boardEntry($board, $rows, $minNDebates);
            if ($entry === null) {
                continue;
            }

            $entries[] = $entry + [
                'user_id' => $userId,
                'name'    => $names[$userId] ?? (string) $userId,
            ];
        }

        // Rank: value desc, then volume desc, then name asc for stable ties.
        usort($entries, function ($a, $b) {
            return [$b['value'], $b['n_debates'], $a['name']] <=> [$a['value'], $a['n_debates'], $b['name']];
        });

        $entries = array_slice($entries, 0, $limit);

        $ranked = [];
        foreach ($entries as $i => $e) {
            $ranked[] = ['rank' => $i + 1]
                + ['user_id' => $e['user_id'], 'name' => $e['name'], 'value' => $e['value']]
                + (isset($e['band']) ? ['band' => $e['band']] : [])
                + ['n_debates' => $e['n_debates']];
        }

        return [
            'stat'          => 'leaderboard',
            'board'         => $board,
            'min_n_debates' => $minNDebates,
            'entries'       => $ranked,
        ];
    }

    /** @return array{value: float|int, band?: string, n_debates: int}|null */
    private function boardEntry(string $board, Collection $rows, int $minNDebates): ?array
    {
        if ($board === 'most_improved') {
            // Mirror the mobile pipeline's source filter: draws never enter.
            $eligible = $rows->filter(fn (ParticipationRow $r) => $r->winningSide !== 'draw')->values();
            if ($eligible->count() < $minNDebates) {
                return null;
            }

            $res = $this->mobileStats->improvement($eligible, new StatsFilter());
            if ($res['index'] === null) {
                // insufficient_history (fewer non-empty buckets than the
                // configured minimum) — no honest number to rank by.
                return null;
            }

            return [
                'value'     => $res['index'],
                'band'      => $res['band'],
                'n_debates' => $res['total_n_debates'],
            ];
        }

        $n = $rows->count();
        if ($n < $minNDebates) {
            return null;
        }

        return match ($board) {
            'win_rate' => [
                'value'     => round($rows->filter(fn (ParticipationRow $r) => $r->won)->count() / $n, 4),
                'n_debates' => $n,
            ],
            'avg_score' => [
                'value'     => round($rows->map(fn (ParticipationRow $r) => $r->normalizedScore(null))->avg(), 4),
                'n_debates' => $n,
            ],
            'most_active' => [
                'value'     => $n,
                'n_debates' => $n,
            ],
            default => null,
        };
    }

    /**
     * Bulk equivalent of the mobile module's per-debater participationRows():
     * two platform-wide queries, then the same row-construction rules (skip
     * debates without chair-submitted stage scores, skip debaters with no
     * scored stage, best-speaker = highest main-stage score in the debate).
     *
     * @return array{0: array<int, Collection<int, ParticipationRow>>, 1: array<int, string>}
     */
    private function participationRowsByUser(AdminStatFilters $f): array
    {
        $debatesQ = DB::table('debates as d')
            ->join('debate_results as dr', 'dr.debate_id', '=', 'd.id')
            ->where('d.status', 'completed')
            ->whereNotNull('d.result_revealed_at');
        $f->applyWindow($debatesQ, 'd.scheduled_at');
        $f->applyFormats($debatesQ, 'd.format_id');

        $debates = [];
        foreach ($debatesQ->select('d.id', 'd.scheduled_at', 'dr.winning_side', 'dr.scores')->get() as $d) {
            $scores = json_decode((string) $d->scores, true);
            $stages = is_array($scores) ? ($scores['stages'] ?? null) : null;
            if (! is_array($stages) || empty($stages)) {
                continue; // same completed-debate guard as the mobile module
            }

            $maxMain = null;
            foreach ($stages as $st) {
                if ((int) ($st['stage_order'] ?? 0) <= 6 && isset($st['score'])) {
                    $score = (float) $st['score'];
                    $maxMain = $maxMain === null ? $score : max($maxMain, $score);
                }
            }

            $debates[(int) $d->id] = [
                'date'         => Carbon::parse($d->scheduled_at),
                'winning_side' => (string) $d->winning_side,
                'stages'       => $stages,
                'max_main'     => $maxMain,
            ];
        }

        $participantsQ = DB::table('debate_participants as dp')
            ->join('debates as d', 'd.id', '=', 'dp.debate_id')
            ->join('debate_results as dr', 'dr.debate_id', '=', 'd.id')
            ->join('users as u', 'u.id', '=', 'dp.user_id')
            ->where('dp.role', 'debater')
            ->where('d.status', 'completed')
            ->whereNotNull('d.result_revealed_at');
        $f->applyWindow($participantsQ, 'd.scheduled_at');
        $f->applyFormats($participantsQ, 'd.format_id');

        $byUser = [];
        $names = [];

        foreach ($participantsQ->select('dp.user_id', 'dp.debate_id', 'dp.side', 'dp.team_id', 'u.name')->get() as $p) {
            $debate = $debates[(int) $p->debate_id] ?? null;
            if ($debate === null) {
                continue;
            }

            $myStages = [];
            foreach ($debate['stages'] as $st) {
                if ((int) ($st['user_id'] ?? 0) !== (int) $p->user_id) {
                    continue;
                }
                $order = (int) ($st['stage_order'] ?? 0);
                $code = PositionCodes::codeForStageOrder($order);
                if ($code === null || ! isset($st['score'])) {
                    continue;
                }
                $myStages[] = [
                    'order'    => $order,
                    'code'     => $code,
                    'is_reply' => PositionCodes::isReplyOrder($order),
                    'score'    => (float) $st['score'],
                ];
            }

            if (empty($myStages)) {
                continue;
            }

            $myMain = null;
            foreach ($myStages as $s) {
                if (! $s['is_reply']) {
                    $myMain = $myMain === null ? $s['score'] : max($myMain, $s['score']);
                }
            }
            $isBest = $myMain !== null && $debate['max_main'] !== null
                && abs($myMain - $debate['max_main']) < 1e-9;

            $userId = (int) $p->user_id;
            $names[$userId] = (string) $p->name;
            $byUser[$userId] ??= collect();
            $byUser[$userId]->push(new ParticipationRow(
                debateId:       (int) $p->debate_id,
                date:           $debate['date'],
                frameworkIds:   [],
                side:           (string) $p->side,
                winningSide:    $debate['winning_side'],
                won:            (string) $p->side === $debate['winning_side'],
                teamKey:        $p->team_id ? (string) $p->team_id : 'RANDOM',
                teamId:         $p->team_id ? (int) $p->team_id : null,
                stages:         $myStages,
                isBestSpeaker:  $isBest,
                motionText:     '',
            ));
        }

        return [$byUser, $names];
    }
}
