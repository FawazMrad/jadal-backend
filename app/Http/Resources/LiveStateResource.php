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
                'current_stage'        => $debate->current_stage,
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

            'motion' => $debate->motion_revealed_at
                ? ($debate->relationLoaded('motion') && $debate->motion
                    ? ['id' => $debate->motion->id, 'text' => $debate->motion->text]
                    : null)
                : null,

            'rooms' => $this->buildRooms($request),

            'judges' => $this->buildJudges($debate),

            'proposition' => $this->buildSide($debate, 'proposition'),

            'opposition' => $this->buildSide($debate, 'opposition'),

            'stages' => $this->buildStages($debate),

            'result' => $this->buildResult($debate, $request),
        ];
    }

    private function buildRooms(Request $request): array
    {
        $debate = $this->debate;
        $user   = $request->user();
        $p      = $this->myParticipant;

        $now    = now();
        $isApproved = $p && $p->status === 'approved';

        $mainOpen = $debate->status === 'live' && $debate->current_stage > 0;
        $prepOpen = in_array($debate->status, ['teams-selected', 'live'])
            && $debate->prep_rooms_opened_at
            && $debate->prep_rooms_opened_at->lte($now);
        $resultOpen = $debate->status === 'completed'
            && $debate->result_revealed_at === null;

        $mainJoinable   = $isApproved;
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
        if (! $p || $p->status !== 'approved') {
            return null;
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
        return $debate->phases->map(fn ($phase) => [
            'order_index'        => $phase->order_index,
            'name'               => $phase->name,
            'role'               => null,  // stored in name; can be derived from order_index parity
            'is_reply'           => (bool) $phase->is_reply,
            'duration_seconds'   => $phase->duration_seconds,
            'status'             => $phase->status,
            'participant_id'     => $phase->participant_id,
            'started_at'         => $phase->started_at?->toIso8601String(),
            'ended_at'           => $phase->ended_at?->toIso8601String(),
            'poi_raised_count'   => $phase->poi_raised_count,
            'poi_answered_count' => $phase->poi_answered_count,
            'audio_url'          => $phase->audio_url,
            'speech_text'        => $phase->speech_text,
        ])->toArray();
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
