<?php

namespace App\Console\Commands;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Services\LiveKitService;
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

        $config = $format->phase_config;

        $motionRevealTime  = $debate->scheduled_at->subHours($config['motion_reveal_offset_hours'] ?? 24);
        $prepRoomsOpenTime = $debate->scheduled_at->subHours($config['prep_rooms_open_offset_hours'] ?? 1);

        // ── Step 1: Reveal motion ─────────────────────────────────────────────
        if ($now->gte($motionRevealTime) && $debate->motion_revealed_at === null) {
            $updates = ['motion_revealed_at' => $now];
            if ($debate->status === 'scheduled') {
                $updates['status'] = 'announced';
            }
            $debate->update($updates);
            $debate->refresh();
            $this->info("Debate {$debate->id}: motion revealed.");
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

            $hasBothSides = $this->hasBothSides($debate);
            $newStatus    = $hasBothSides ? 'teams-selected' : $debate->status;

            $debate->update([
                'prep_rooms_opened_at' => $now,
                'status'               => $newStatus,
            ]);
            $debate->refresh();
            $this->info("Debate {$debate->id}: prep rooms opened, status → {$newStatus}.");
        }

        // ── Step 3: Start debate at scheduled_at ─────────────────────────────
        if ($now->gte($debate->scheduled_at) && in_array($debate->status, ['announced', 'teams-selected'])) {
            // Auto-fill any missing speaker slots.
            $this->autoAssignSpeakers($debate, 'proposition');
            $this->autoAssignSpeakers($debate, 'opposition');
            $debate->refresh();

            // Require at least one approved judge.
            $judgeCount = DebateParticipant::where('debate_id', $debate->id)
                ->where('role', 'judge')
                ->where('status', 'approved')
                ->count();

            if ($judgeCount === 0) {
                $debate->update(['status' => 'cancelled']);
                $this->warn("Debate {$debate->id}: cancelled — no approved judges.");
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

            $debate->update([
                'status'        => 'live',
                'started_at'    => $now,
                'current_stage' => 0,
            ]);
            $this->info("Debate {$debate->id}: advanced to live.");
        }
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
     * Auto-assign speaking_phase_order 1,2,3 for the given side if any slots are empty.
     */
    private function autoAssignSpeakers(Debate $debate, string $side): void
    {
        $approved = DebateParticipant::where('debate_id', $debate->id)
            ->where('side', $side)
            ->where('role', 'debater')
            ->where('status', 'approved')
            ->get();

        if ($approved->isEmpty()) {
            return;
        }

        $filled    = $approved->whereNotNull('speaking_phase_order')->pluck('speaking_phase_order')->toArray();
        $needed    = array_diff([1, 2, 3], $filled);
        $unassigned = $approved->whereNull('speaking_phase_order')->values();

        foreach ($needed as $slot) {
            $participant = $unassigned->shift();
            if (! $participant) {
                // Not enough participants — pick random from approved list.
                $participant = $approved->random();
            }
            $participant->update(['speaking_phase_order' => $slot]);
        }
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
