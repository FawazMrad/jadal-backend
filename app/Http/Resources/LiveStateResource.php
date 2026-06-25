<?php

namespace App\Http\Resources;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LiveStateResource extends JsonResource
{
    public function __construct(
        private Debate $debate,
        private DebateParticipant|null $myParticipant
    ) {
        parent::__construct($debate);
    }

    public function toArray(Request $request): array
    {
        $debate = $this->debate;
        $format = $debate->relationLoaded('format') ? $debate->format : null;
        $config = $format?->phase_config ?? [];

        $totalStages = $debate->phases->count();

        return [
            'debate' => [
                'id'                   => $debate->id,
                'title'                => $debate->title,
                'tag'                  => $debate->tag,
                'status'               => $debate->status,
                'scheduled_at'         => $debate->scheduled_at?->toIso8601String(),
                'started_at'           => $debate->started_at?->toIso8601String(),
                'ended_at'             => $debate->ended_at?->toIso8601String(),
                'motion_revealed_at'   => $debate->motion_revealed_at?->toIso8601String(),
                'prep_rooms_opened_at' => $debate->prep_rooms_opened_at?->toIso8601String(),
                'result_revealed_at'   => $debate->result_revealed_at?->toIso8601String(),
                'cancellation_reason'  => $debate->cancellation_reason,
                'current_stage'        => $debate->current_stage,
                // Server start time of the stage currently in progress, so a client
                // that joins mid-speech can sync its timer instead of restarting at 0.
                'current_stage_started_at' => $this->currentStageStartedAt($debate)?->toIso8601String(),
            ],

            'format' => $format ? [
                'speech_time_seconds'         => $config['speech_time_seconds'] ?? null,
                'has_reply_speech'             => $config['has_reply_speech'] ?? false,
                'reply_time_seconds'           => $config['reply_time_seconds'] ?? null,
                'motion_reveal_offset_hours'   => $config['motion_reveal_offset_hours'] ?? null,
                'prep_rooms_open_offset_hours' => $config['prep_rooms_open_offset_hours'] ?? null,
                'speakers_per_side'            => DebateFormat::SPEAKERS_PER_SIDE,
                'total_stages'                 => $totalStages,
            ] : null,

            'motion' => $this->buildMotion($request),

            'rooms' => $this->buildRooms($request),

            'judges' => $this->buildJudges($debate),

            'proposition' => $this->buildSide($debate, 'proposition'),

            'opposition' => $this->buildSide($debate, 'opposition'),

            'stages' => $this->buildStages($debate),

            'result' => $this->buildResult($debate, $request),
        ];
    }

    /**
     * Motion is visible once it has been revealed (motion_revealed_at set), or
     * always to admins. Includes frameworks; `tags` mirrors framework names for
     * a backward-friendly flat list.
     */
    private function buildMotion(Request $request): ?array
    {
        $debate = $this->debate;
        $user   = $request->user();

        $visible = $debate->motion_revealed_at !== null
            || ($user && $user->role === 'admin');

        if (! $visible || ! $debate->relationLoaded('motion') || ! $debate->motion) {
            return null;
        }

        $motion     = $debate->motion;
        $frameworks = $motion->relationLoaded('frameworks') ? $motion->frameworks : collect();

        return [
            'id'         => $motion->id,
            'text'       => $motion->text,
            'tags'       => $frameworks->pluck('name')->values()->all(),
            'frameworks' => $frameworks->map(fn ($f) => [
                'id'        => $f->id,
                'name'      => $f->name,
                'color_hex' => $f->color_hex,
            ])->values()->all(),
        ];
    }

    private function buildRooms(Request $request): array
    {
        $debate = $this->debate;
        $user   = $request->user();
        $p      = $this->myParticipant;

        $now    = now();
        $isApproved = $p && $p->status === 'approved';

        // Main room is open for the whole `live` status (lobby at stage 0, debate after).
        $mainOpen = $debate->status === 'live';

        // Prep rooms are ONLY open during the lobby (current_stage === 0). They close
        // when the chair starts stage 1 and re-open on rollback-to-lobby.
        $prepOpen = $debate->prep_rooms_opened_at
            && $debate->prep_rooms_opened_at->lte($now)
            && in_array($debate->status, ['teams-selected', 'live'])
            && $debate->current_stage === 0;

        $resultOpen = $debate->status === 'completed'
            && $debate->result_revealed_at === null;

        // Any authenticated user can join the main room (participant or viewer),
        // both in lobby mode and during the debate.
        $mainJoinable   = $user !== null;
        $propJoinable   = $isApproved && $p && in_array($p->side, ['proposition'])
            && in_array($p->role, ['debater']);
        $oppJoinable    = $isApproved && $p && in_array($p->side, ['opposition'])
            && in_array($p->role, ['debater']);
        $resultJoinable = $isApproved && $p && $p->role === 'judge' && $p->judge_order !== null;

        return [
            'main'   => [
                'name'           => $debate->livekit_room_name,
                'open'           => $mainOpen,
                'joinable_for_me' => $mainJoinable,
                'role_if_joined' => $this->resolveRoleInMain($p),
            ],
            'prop'   => [
                'name'           => $debate->prop_room_name,
                'open'           => $prepOpen,
                'joinable_for_me' => $prepOpen && $propJoinable,
                'role_if_joined' => $propJoinable ? 'debater_member' : null,
            ],
            'opp'    => [
                'name'           => $debate->opp_room_name,
                'open'           => $prepOpen,
                'joinable_for_me' => $prepOpen && $oppJoinable,
                'role_if_joined' => $oppJoinable ? 'debater_member' : null,
            ],
            'result' => [
                'name'           => $debate->result_room_name,
                'open'           => $resultOpen,
                'joinable_for_me' => $resultOpen && $resultJoinable,
                'role_if_joined' => $resultJoinable
                    ? ($p->is_chair ? 'judge_chair' : 'judge_panel')
                    : null,
            ],
        ];
    }

    private function resolveRoleInMain(?DebateParticipant $p): ?string
    {
        // Non-participants (and not-yet-approved users) join the main room as viewers.
        if (! $p || $p->status !== 'approved') {
            return 'viewer';
        }
        if ($p->role === 'judge') {
            return $p->is_chair ? 'judge_chair' : 'judge_panel';
        }
        if ($p->role === 'trainer') {
            return 'trainer';
        }
        if ($p->role === 'debater') {
            $currentStage = $this->debate->current_stage;
            if ($currentStage > 0) {
                $phase = $this->debate->phases
                    ->firstWhere('order_index', $currentStage);
                if ($phase && (int) $phase->participant_id === (int) $p->id) {
                    return 'debater_speaker';
                }
            }
            return 'debater_member';
        }
        return 'viewer';
    }

    private function buildJudges(Debate $debate): array
    {
        return $debate->participants
            ->where('role', 'judge')
            ->values()
            ->map(fn ($j) => [
                'id'          => $j->id,
                'user'        => $j->relationLoaded('user')
                    ? new PublicUserResource($j->user)
                    : null,
                'judge_order' => $j->judge_order,
                'is_chair'    => (bool) $j->is_chair,
                'is_attended' => (bool) $j->is_attended,
            ])
            ->toArray();
    }

    private function buildSide(Debate $debate, string $side): array
    {
        $participants = $debate->participants
            ->where('side', $side)
            ->where('role', 'debater')
            ->where('status', 'approved')
            ->values();

        $firstTeamId = $participants->first()?->team_id;
        $team        = null;
        $isRandom    = false;

        if ($firstTeamId) {
            $teamModel = \App\Models\Team::find($firstTeamId);
            $team      = $teamModel ? new \App\Http\Resources\TeamResource($teamModel) : null;
            $isRandom  = (bool) $teamModel?->is_random;
        }

        $speakers = $participants
            ->sortBy('speaking_phase_order')
            ->values();

        return [
            'team'     => $team,
            'is_random' => $isRandom,
            'members'  => PublicUserResource::collection(
                $participants->filter(fn ($p) => $p->relationLoaded('user') && $p->user)
                    ->map(fn ($p) => $p->user)
                    ->values()
            ),
            'speakers' => DebateParticipantResource::collection($speakers),
        ];
    }

    private function buildStages(Debate $debate): array
    {
        // participant_id → user_id, so clients get the speaker directly per stage.
        $userByParticipantId = $debate->participants
            ->pluck('user_id', 'id');

        return $debate->phases->map(fn ($phase) => [
            'order_index'        => $phase->order_index,
            'name'               => $phase->name,
            'role'               => null,  // stored in name; can be derived from order_index parity
            'is_reply'           => (bool) $phase->is_reply,
            'duration_seconds'   => $phase->duration_seconds,
            'status'             => $phase->status,
            'participant_id'     => $phase->participant_id,
            'speaker_user_id'    => $phase->participant_id
                ? ($userByParticipantId[$phase->participant_id] ?? null)
                : null,
            'started_at'         => $phase->started_at?->toIso8601String(),
            'ended_at'           => $phase->ended_at?->toIso8601String(),
            'poi_raised_count'   => $phase->poi_raised_count,
            'poi_answered_count' => $phase->poi_answered_count,
            'audio_url'          => $phase->audio_url,
            'speech_text'        => $phase->speech_text,
        ])->toArray();
    }

    /**
     * started_at of the phase whose order_index == current_stage (the one in
     * progress), or null in the lobby / when not yet started.
     */
    private function currentStageStartedAt(Debate $debate): ?\Carbon\CarbonInterface
    {
        if ($debate->current_stage < 1) {
            return null;
        }

        $phase = $debate->phases->firstWhere('order_index', $debate->current_stage);

        return $phase?->started_at;
    }

    private function buildResult(Debate $debate, Request $request): mixed
    {
        $result = $debate->relationLoaded('result') ? $debate->result : null;
        if (! $result) {
            return null;
        }

        $user           = $request->user();
        $isJudge        = $this->myParticipant && $this->myParticipant->role === 'judge';
        $isResultPublic = $debate->result_revealed_at !== null;

        if (! $isResultPublic && ! $isJudge) {
            return null;
        }

        return new DebateResultResource($result);
    }
}
