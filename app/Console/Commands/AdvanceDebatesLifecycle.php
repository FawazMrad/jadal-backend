<?php

namespace App\Console\Commands;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Services\LiveKitService;
use App\Services\Push\DebateNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AdvanceDebatesLifecycle extends Command
{
    protected $signature   = 'debates:tick';
    protected $description = 'Advance debate lifecycle states based on scheduled times. Run every minute via cron.';

    public function handle(LiveKitService $liveKit): int
    {
        $now = now();

        $debates = Debate::with(['format', 'participants'])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->get();

        foreach ($debates as $debate) {
            try {
                $this->processdebate($debate, $now, $liveKit);
            } catch (\Throwable $e) {
                $this->error("Debate {$debate->id}: " . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }

    private function processdebate(Debate $debate, \Carbon\Carbon $now, LiveKitService $liveKit): void
    {
        $format = $debate->format;
        if (! $format || empty($format->phase_config)) {
            return;
        }

        $config   = $format->phase_config;
        $hasReply = (bool) ($config['has_reply_speech'] ?? false);

        $motionRevealTime  = $debate->scheduled_at->copy()->subHours($config['motion_reveal_offset_hours'] ?? 24);
        $prepRoomsOpenTime = $debate->scheduled_at->copy()->subHours($config['prep_rooms_open_offset_hours'] ?? 1);

        // ── Step 1: Motion-reveal time ────────────────────────────────────────
        // The motion text becomes visible regardless of status. Status is NO LONGER
        // flipped to 'announced' here — that is now driven by the admin assigning
        // participants. If the admin never did so (still 'scheduled'), auto-cancel.
        if ($now->gte($motionRevealTime)) {
            if ($debate->motion_revealed_at === null) {
                $debate->update(['motion_revealed_at' => $now]);
                $debate->refresh();
                $this->info("Debate {$debate->id}: motion revealed.");

                // All participants, judges included.
                app(DebateNotifier::class)->motionRevealed($debate);
            }

            // TEMPORARILY DISABLED — auto-cancel when a debate reaches
            // motion-reveal time with nobody registered.
            //
            // Disabled on request so a debate created with no registrations
            // stays `scheduled` instead of being cancelled out from under the
            // admin. The debate now simply sits open; nothing else in this
            // step changes (the motion is still revealed above).
            //
            // Re-enable by uncommenting. If it stays off long-term, decide what
            // SHOULD happen to a debate nobody joined — leaving them
            // accumulating in `scheduled` forever is not a long-term answer.
            //
            // if ($debate->status === 'scheduled') {
            //     $debate->update([
            //         'status'              => 'cancelled',
            //         'cancellation_reason' => 'no_participants_at_motion_reveal',
            //         // Terminal transition — anchors the guest read window.
            //         'finalized_at'        => $now,
            //     ]);
            //     $this->warn("Debate {$debate->id}: cancelled — no participants at motion reveal.");
            //     // Reaches nobody here by definition (the debate was still
            //     // `scheduled`, so it has no approved participants), but kept for
            //     // uniformity so every cancellation path notifies identically.
            //     app(DebateNotifier::class)->debateStateChangedFrom($debate, 'scheduled');
            //     return;
            // }
        }

        // ── Step 2: Open prep rooms ───────────────────────────────────────────
        if ($now->gte($prepRoomsOpenTime) && $debate->prep_rooms_opened_at === null) {
            $liveKit->provisionDebateRooms($debate);
            $debate->refresh();

            try {
                $liveKit->createRoomIfMissing($debate->prop_room_name);
                $liveKit->createRoomIfMissing($debate->opp_room_name);
            } catch (\Throwable $e) {
                $this->warn("Debate {$debate->id}: could not create prep rooms — " . $e->getMessage());
            }

            // announce() pools each team's members with side=null, status=pending —
            // sides are deliberately not chosen until now. Randomly decide which
            // announced team is proposition vs opposition and approve each team's
            // pool onto that side, so hasBothSides() below can actually go true.
            // Gated by prep_rooms_opened_at (checked above), so this only ever
            // runs once per debate.
            if ($debate->status === 'announced') {
                $this->assignRandomSides($debate);
                $debate->refresh();
            }

            // announced → teams-selected once both sides have approved debaters.
            $hasBothSides = $this->hasBothSides($debate);
            $newStatus    = ($debate->status === 'announced' && $hasBothSides)
                ? 'teams-selected'
                : $debate->status;

            $statusChanged = $newStatus !== $debate->status;

            $debate->update([
                'prep_rooms_opened_at' => $now,
                'status'               => $newStatus,
            ]);
            $debate->refresh();
            $this->info("Debate {$debate->id}: prep rooms opened, status → {$newStatus}.");

            // Only on an actual transition (announced →
            // teams-selected), not on every prep-room open.
            if ($statusChanged) {
                app(DebateNotifier::class)->debateStateChanged($debate);
            }
        }

        // ── Step 3: Start debate at scheduled_at ─────────────────────────────
        if ($now->gte($debate->scheduled_at)) {
            // TEMPORARILY DISABLED — auto-cancel when start time arrives and the
            // debate is still `scheduled` (i.e. no participants were ever
            // assigned). Disabled alongside the motion-reveal cancellation
            // above; see the note there.
            //
            // With this off, a debate nobody joined stays `scheduled` past its
            // own start time. The `announced`/`teams-selected` branch below does
            // not match `scheduled`, so the command simply does nothing further
            // for it on every tick.
            //
            // if ($debate->status === 'scheduled') {
            //     $debate->update([
            //         'status'              => 'cancelled',
            //         'cancellation_reason' => 'no_participants_at_motion_reveal',
            //         // Terminal transition — anchors the guest read window.
            //         'finalized_at'        => $now,
            //     ]);
            //     $this->warn("Debate {$debate->id}: cancelled — no participants by start time.");
            //     app(DebateNotifier::class)->debateStateChangedFrom($debate, 'scheduled');
            //     return;
            // }

            if (in_array($debate->status, ['announced', 'teams-selected'])) {
                // Auto-fill any missing speaker slots (+ default reply speaker).
                $this->autoAssignSpeakers($debate, 'proposition', $hasReply);
                $this->autoAssignSpeakers($debate, 'opposition', $hasReply);
                $debate->refresh();

                // Require at least one approved judge.
                $judgeCount = DebateParticipant::where('debate_id', $debate->id)
                    ->where('role', 'judge')
                    ->where('status', 'approved')
                    ->count();

                if ($judgeCount === 0) {
                    $previousStatus = $debate->status;
                    $debate->update([
                        'status'              => 'cancelled',
                        'cancellation_reason' => 'no_judge_at_scheduled',
                        // Terminal transition — anchors the guest read window.
                        'finalized_at'        => $now,
                    ]);
                    $this->warn("Debate {$debate->id}: cancelled — no approved judges.");
                    // The cancellation that matters most: this debate HAS
                    // approved debaters who would otherwise turn up to nothing.
                    app(DebateNotifier::class)->debateStateChangedFrom($debate, $previousStatus);
                    return;
                }

                // Initialize phases from the format.
                if (! $debate->phases()->exists()) {
                    $this->createPhases($debate, $format);
                }

                // Provision and create the main room.
                $liveKit->provisionDebateRooms($debate);
                $debate->refresh();

                try {
                    $liveKit->createRoomIfMissing($debate->livekit_room_name);
                } catch (\Throwable $e) {
                    $this->warn("Debate {$debate->id}: could not create main room — " . $e->getMessage());
                }

                $previousStatus = $debate->status;
                $debate->update([
                    'status'        => 'live',
                    'started_at'    => $now,
                    'current_stage' => 0,
                ]);
                $this->info("Debate {$debate->id}: advanced to live.");
                app(DebateNotifier::class)->debateStateChangedFrom($debate, $previousStatus);
            }
        }
    }

    /**
     * Coin-flip which of the two announced teams plays proposition vs
     * opposition, then approve every debater in each team's pool onto that
     * side. Mirrors the shape assignParticipants()/teamRoster() already use
     * (side + status='approved' on debate_participants) — this just fills in
     * the missing automatic trigger for debates that went through announce().
     * Which 3 of each approved pool actually speak (and in what order) is
     * decided later by the team leader via team-speakers, once prep rooms
     * are open.
     */
    private function assignRandomSides(Debate $debate): void
    {
        $teamIds = [(int) $debate->proposition_team_id, (int) $debate->opposition_team_id];
        if (in_array(0, $teamIds, true)) {
            return; // announce() always sets both — defensive only.
        }

        if (random_int(0, 1) === 1) {
            $teamIds = array_reverse($teamIds);
        }
        [$propTeamId, $oppTeamId] = $teamIds;

        DB::transaction(function () use ($debate, $propTeamId, $oppTeamId) {
            $debate->update([
                'proposition_team_id' => $propTeamId,
                'opposition_team_id'  => $oppTeamId,
            ]);

            DebateParticipant::where('debate_id', $debate->id)
                ->where('team_id', $propTeamId)
                ->where('role', 'debater')
                ->update(['side' => 'proposition', 'status' => 'approved']);

            DebateParticipant::where('debate_id', $debate->id)
                ->where('team_id', $oppTeamId)
                ->where('role', 'debater')
                ->update(['side' => 'opposition', 'status' => 'approved']);
        });
    }

    private function hasBothSides(Debate $debate): bool
    {
        $sides = DebateParticipant::where('debate_id', $debate->id)
            ->where('role', 'debater')
            ->where('status', 'approved')
            ->whereIn('side', ['proposition', 'opposition'])
            ->select('side')
            ->distinct()
            ->pluck('side')
            ->toArray();

        return in_array('proposition', $sides) && in_array('opposition', $sides);
    }

    /**
     * Guarantee the side has a complete speaking order before the debate goes live.
     *
     * The team leader normally sets this through POST /debates/{debate}/team-speakers,
     * which writes the authoritative order array (debates.prop_speaker_order /
     * opp_speaker_order). When a team never did so, the debate went live with an
     * empty array: no speaker resolved for any stage and an empty team card in the
     * room. Fill it randomly from the side's approved debaters instead, so a
     * debate is always runnable regardless of whether the team showed up to pick.
     *
     * An order the team already set is left alone.
     */
    private function autoAssignSpeakers(Debate $debate, string $side, bool $hasReply = false): void
    {
        $approved = DebateParticipant::where('debate_id', $debate->id)
            ->where('side', $side)
            ->where('role', 'debater')
            ->where('status', 'approved')
            ->get();

        if ($approved->isEmpty()) {
            return;
        }

        $slots       = \App\Models\DebateFormat::SPEAKERS_PER_SIDE;
        $orderColumn = $side === 'proposition' ? 'prop_speaker_order' : 'opp_speaker_order';
        $approvedIds = $approved->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $existing    = array_map('intval', $debate->speakerOrderFor($side));

        // Keep a leader-set order only while it is still valid: every slot filled
        // and every slot pointing at a debater who is still approved on this side
        // (participants can be removed after the order was saved). Otherwise the
        // order is rebuilt from scratch rather than left partly broken.
        if (count($existing) === $slots && array_diff($existing, $approvedIds) === []) {
            return;
        }

        // Random fill: distinct debaters first, then wrap around so a short team
        // still covers every slot — the same multi-role shape team-speakers
        // accepts, e.g. a 2-person team filling 3 slots as [A, B, A].
        $pool  = $approved->shuffle()->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all();
        $order = [];
        for ($i = 0; $i < $slots; $i++) {
            $order[] = $pool[$i % count($pool)];
        }

        $participantIds = $approved->pluck('id');

        DB::transaction(function () use ($debate, $participantIds, $order, $orderColumn, $hasReply, $approved) {
            $debate->update([$orderColumn => $order]);

            // Mirror onto speaking_phase_order — one slot per participant, the
            // first slot each distinct user fills. Same projection setTeamSpeakers
            // writes, and what the legacy speaker-resolution path reads.
            DebateParticipant::whereIn('id', $participantIds)
                ->update(['speaking_phase_order' => null]);

            $seen = [];
            foreach ($order as $idx => $userId) {
                if (in_array($userId, $seen, true)) {
                    continue;
                }
                $seen[] = $userId;
                DebateParticipant::whereIn('id', $participantIds)
                    ->where('user_id', $userId)
                    ->update(['speaking_phase_order' => $idx + 1]);
            }

            if (! $hasReply) {
                return;
            }

            // The reply speaker must be one of the chosen speakers and must hold
            // slot 1 or 2, never slot 3 — the constraint team-speakers enforces.
            // A still-valid choice by the leader survives; anything else resets
            // to the slot-1 speaker.
            $current = $approved->firstWhere('is_reply_speaker', true);
            $stillValid = $current
                && in_array((int) $current->user_id, array_slice($order, 0, 2), true);

            if ($stillValid) {
                return;
            }

            DebateParticipant::whereIn('id', $participantIds)
                ->update(['is_reply_speaker' => false]);

            DebateParticipant::whereIn('id', $participantIds)
                ->where('user_id', $order[0])
                ->update(['is_reply_speaker' => true]);
        });

        $this->info("Debate {$debate->id}: {$side} speaking order auto-filled — " . implode(', ', $order) . '.');
    }

    private function createPhases(Debate $debate, \App\Models\DebateFormat $format): void
    {
        $stages = $format->deriveStages();

        foreach ($stages as $stage) {
            DebatePhase::create([
                'debate_id'        => $debate->id,
                'name'             => $stage['name'],
                'order_index'      => $stage['order_index'],
                'duration_seconds' => $stage['duration_seconds'],
                'status'           => 'pending',
                'is_reply'         => $stage['is_reply'],
            ]);
        }
    }
}
