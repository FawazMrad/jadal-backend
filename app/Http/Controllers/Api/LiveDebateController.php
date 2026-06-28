<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Debate\ReportPoiRequest;
use App\Http\Requests\Debate\SetTeamSpeakersRequest;
use App\Http\Requests\Debate\SubmitResultRequest;
use App\Http\Resources\DebateResultResource;
use App\Http\Resources\LiveStateResource;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Models\DebateResult;
use App\Services\LiveKitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class LiveDebateController extends Controller
{
    public function __construct(private LiveKitService $liveKit) {}

    // ── F1: GET /debates/{debate}/live-state ──────────────────────────────────

    public function state(Request $request, Debate $debate): JsonResponse
    {
        $user = $request->user();

        $debate->load([
            'format',
            'motion.frameworks',
            'participants.user',
            'phases',
            'result.judge',
        ]);

        $myParticipant = $debate->participants
            ->firstWhere('user_id', $user->id);

        // Any authenticated user can read the live-state. Visibility of sensitive
        // bits (motion, result, joinable rooms) is enforced inside the resource.
        return $this->success(
            new LiveStateResource($debate, $myParticipant),
            'Live state retrieved.'
        );
    }

    // ── F3: POST /debates/{debate}/team-speakers ──────────────────────────────

    public function setTeamSpeakers(SetTeamSpeakersRequest $request, Debate $debate): JsonResponse
    {
        $user = $request->user();
        $side = $request->side;

        if (! in_array($debate->status, ['teams-selected', 'live'])) {
            return $this->error(
                'يمكن تحديد المتحدثين فقط في مرحلة تحديد الفرق أو أثناء النقاش. | Speakers can only be set when teams-selected or live.',
                [], 422
            );
        }

        if ($debate->current_stage !== 0) {
            return $this->error('يبدأ النقاش بالفعل. لا يمكن تغيير المتحدثين. | Debate already in progress.', [], 422);
        }

        // Auth: user must be the leader of the team on that side.
        $sideParticipants = DebateParticipant::where('debate_id', $debate->id)
            ->where('side', $side)
            ->where('role', 'debater')
            ->where('status', 'approved')
            ->get();

        $teamId = $sideParticipants->first()?->team_id;

        if ($teamId) {
            $team = \App\Models\Team::find($teamId);
            if (! $team || (int) $team->leader_id !== (int) $user->id) {
                return $this->error('فقط قائد الفريق يمكنه تحديد المتحدثين. | Only the team leader can set speakers.', [], 403);
            }
        } else {
            // Random team — any approved debater on that side can act as leader.
            $isMember = $sideParticipants->contains('user_id', $user->id);
            if (! $isMember) {
                return $this->error('غير مصرح. | Not authorized to set speakers for this side.', [], 403);
            }
        }

        $speakerIds = $request->speaker_user_ids;

        // Verify all supplied IDs are approved debaters on this side.
        $approvedIds = $sideParticipants->pluck('user_id')->map(fn ($id) => (int) $id)->toArray();
        foreach ($speakerIds as $uid) {
            if (! in_array((int) $uid, $approvedIds)) {
                return $this->error(
                    "المستخدم {$uid} ليس متحدثاً معتمداً على جانب {$side}. | User {$uid} is not an approved debater on {$side}.",
                    [], 422
                );
            }
        }

        $replySpeakerId = $request->reply_speaker_user_id; // null when format has no reply

        DB::transaction(function () use ($debate, $sideParticipants, $speakerIds, $side, $replySpeakerId) {
            $debateId = $debate->id;

            // Reset speaking orders AND reply-speaker flag for this side.
            DebateParticipant::whereIn('id', $sideParticipants->pluck('id'))
                ->update(['speaking_phase_order' => null, 'is_reply_speaker' => false]);

            // Persist the full ordered assignment (array of user_ids, duplicates
            // allowed) — this is the authoritative source for per-stage speaker
            // resolution and the lobby team layout. A 2-person team filling 3
            // slots sends e.g. [A, B, A]; A then covers slots 1 and 3.
            $orderColumn = $side === 'proposition' ? 'prop_speaker_order' : 'opp_speaker_order';
            $debate->update([$orderColumn => array_map('intval', $speakerIds)]);

            // speaking_phase_order holds only ONE slot per participant, so set it to
            // the FIRST slot each distinct user appears in (kept for display /
            // backward-compat; the order array above is authoritative for multi-role).
            $seen = [];
            foreach ($speakerIds as $idx => $userId) {
                if (in_array((int) $userId, $seen, true)) {
                    continue;
                }
                $seen[] = (int) $userId;
                DebateParticipant::where('debate_id', $debateId)
                    ->where('user_id', $userId)
                    ->where('side', $side)
                    ->update(['speaking_phase_order' => $idx + 1]);
            }

            // Flag the chosen reply speaker (validated to be slot 1 or 2).
            if ($replySpeakerId) {
                DebateParticipant::where('debate_id', $debateId)
                    ->where('user_id', $replySpeakerId)
                    ->where('side', $side)
                    ->update(['is_reply_speaker' => true]);
            }
        });

        $debate->load(['format', 'motion.frameworks', 'participants.user', 'phases', 'result.judge']);
        $myParticipant = $debate->participants->firstWhere('user_id', request()->user()->id);

        return $this->success(
            new LiveStateResource($debate, $myParticipant),
            'تم تحديد المتحدثين. | Speakers set.'
        );
    }

    // ── F4: POST /debates/{debate}/next-stage ─────────────────────────────────

    public function nextStage(Request $request, Debate $debate): JsonResponse
    {
        $user = $request->user();

        // Must be the chair judge.
        $chair = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $user->id)
            ->where('role', 'judge')
            ->where('is_chair', true)
            ->where('status', 'approved')
            ->first();

        if (! $chair) {
            return $this->error('فقط قاضي الرئاسة يمكنه تقديم المراحل. | Only the chair judge can advance stages.', [], 403);
        }

        if ($debate->status !== 'live') {
            return $this->error('النقاش ليس في حالة live. | Debate is not live.', [], 422);
        }

        // Once the speaking portion is finished (result phase) there is nothing
        // left to advance: the only forward path is submit/reveal → close-room.
        // Without this guard the chair can keep pressing next-stage, which
        // re-stamps speeches_completed_at/ended_at and pushes current_stage past
        // the marker (the trace showed it walk 7 → 8 → 9). Reject it.
        if ($debate->speeches_completed_at !== null) {
            return $this->error('انتهت مرحلة المتحدثين بالفعل. | The speaking phase is already complete; advancing further is not allowed.', [], 422);
        }

        $result = DB::transaction(function () use ($debate, $user) {
            $totalStages = $debate->phases()->count();

            // Close the current active stage.
            if ($debate->current_stage > 0) {
                $currentPhase = DebatePhase::where('debate_id', $debate->id)
                    ->where('order_index', $debate->current_stage)
                    ->first();

                if ($currentPhase) {
                    $currentPhase->update(['status' => 'completed', 'ended_at' => now()]);

                    if ($currentPhase->egress_id) {
                        try {
                            app(LiveKitService::class)->stopEgress($currentPhase->egress_id);
                        } catch (\Throwable) {
                            // Non-fatal — egress may have already ended.
                        }
                    }
                }
            }

            $nextStage = $debate->current_stage + 1;

            // Past the last speech → the SPEAKING phase is done, but the debate is
            // NOT over. Status stays `live` (the "result phase"): the main room
            // stays open for rejoining, the result room opens for judges to
            // deliberate, and the chair submits + reveals a result. Only close-room
            // finalises the debate (→ completed/cancelled). We do NOT set completed here.
            if ($nextStage > $totalStages) {
                $debate->update([
                    'speeches_completed_at' => now(),
                    'ended_at'              => now(),   // end of the speaking portion
                    'current_stage'         => $nextStage, // > total = "past last speech" marker
                ]);

                // Open the result room (judges-only — see LiveKitController).
                app(LiveKitService::class)->createRoomIfMissing($debate->result_room_name);

                // Tell the main room the speeches are done / result phase is open.
                // Status is STILL `live` — clients must not treat this as "finished".
                try {
                    app(LiveKitService::class)->sendDataToRoom(
                        $debate->livekit_room_name,
                        ['event' => 'speeches_completed', 'result_room' => $debate->result_room_name]
                    );
                } catch (\Throwable) {}

                return $debate->fresh();
            }

            // Advance to the next stage.
            $nextPhase = DebatePhase::where('debate_id', $debate->id)
                ->where('order_index', $nextStage)
                ->first();

            if (! $nextPhase) {
                throw new \RuntimeException("Phase {$nextStage} not found for debate {$debate->id}.");
            }

            // Resolve the expected speaker for this stage.
            $speakerParticipant = $this->resolveStageSpeaker($debate, $nextPhase);

            $egressId = null;
            if ($speakerParticipant) {
                try {
                    $egressId = app(LiveKitService::class)->startTrackEgressForParticipant(
                        $debate->livekit_room_name,
                        (string) $speakerParticipant->user_id,
                        $debate->id,
                        $nextStage
                    );
                } catch (\Throwable) {
                    // Non-fatal — egress is best-effort.
                }
            }

            $nextPhase->update([
                'status'         => 'active',
                'started_at'     => now(),
                'participant_id' => $speakerParticipant?->id,
                'egress_id'      => $egressId,
            ]);

            $debate->update(['current_stage' => $nextStage]);

            // Broadcast stage change.
            try {
                app(LiveKitService::class)->sendDataToRoom(
                    $debate->livekit_room_name,
                    [
                        'event'            => 'stage_changed',
                        'current_stage'    => $nextStage,
                        'speaker_user_id'  => $speakerParticipant?->user_id,
                        'duration_seconds' => $nextPhase->duration_seconds,
                        'server_started_at' => now()->toIso8601String(),
                    ]
                );
            } catch (\Throwable) {}

            // First transition out of the lobby (stage 0 → 1): close prep rooms on
            // LiveKit and signal the frontend to switch into debate mode.
            if ($nextStage === 1) {
                try {
                    $svc = app(LiveKitService::class);
                    if ($debate->prop_room_name) {
                        $svc->deleteRoomIfExists($debate->prop_room_name);
                    }
                    if ($debate->opp_room_name) {
                        $svc->deleteRoomIfExists($debate->opp_room_name);
                    }
                } catch (\Throwable) {}

                try {
                    app(LiveKitService::class)->sendDataToRoom(
                        $debate->livekit_room_name,
                        ['event' => 'debate_mode_started']
                    );
                } catch (\Throwable) {}
            }

            return $debate->fresh();
        });

        $result->load(['format', 'motion.frameworks', 'participants.user', 'phases', 'result.judge']);
        $myParticipant = $result->participants->firstWhere('user_id', $user->id);

        return $this->success(
            new LiveStateResource($result, $myParticipant),
            'تم تقديم المرحلة. | Stage advanced.'
        );
    }

    // ── F5: POST /debates/{debate}/stages/{stage}/poi ─────────────────────────

    public function reportPoi(ReportPoiRequest $request, Debate $debate, DebatePhase $stage): JsonResponse
    {
        $user = $request->user();

        // Participant must be approved.
        $participant = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

        if (! $participant) {
            return $this->error('غير مصرح. | Not an approved participant.', [], 403);
        }

        // Stage must be the currently active one.
        if ($stage->debate_id !== $debate->id || $stage->order_index !== $debate->current_stage) {
            return $this->error('المرحلة غير نشطة حالياً. | This stage is not the active stage.', [], 422);
        }

        if ($stage->status !== 'active') {
            return $this->error('المرحلة غير نشطة. | Stage is not active.', [], 422);
        }

        $action         = $request->action;
        $speakerParticipantId = $stage->participant_id;
        $speakerParticipant   = $speakerParticipantId
            ? DebateParticipant::find($speakerParticipantId)
            : null;

        if ($action === 'raise') {
            // Anyone on the opposing side from the speaker.
            if ($speakerParticipant) {
                if ($participant->side === $speakerParticipant->side) {
                    return $this->error('لا يمكن رفع نقطة إجراء من نفس الجانب. | Cannot raise POI from the same side as speaker.', [], 422);
                }
            }
            $stage->increment('poi_raised_count');
        } else {
            // 'answer' — only the current speaker.
            if (! $speakerParticipant || (int) $speakerParticipant->user_id !== (int) $user->id) {
                return $this->error('فقط المتحدث الحالي يمكنه الإجابة. | Only the current speaker can answer.', [], 403);
            }

            if ($stage->poi_answered_count >= $stage->poi_raised_count) {
                return $this->error('لا توجد نقاط مرفوعة للإجابة عليها. | No raised POI to answer.', [], 422);
            }
            $stage->increment('poi_answered_count');
        }

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    // ── F6: POST /debates/{debate}/result  (overrides DebateController) ───────

    public function submitResult(SubmitResultRequest $request, Debate $debate): JsonResponse
    {
        $user = $request->user();

        $chair = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $user->id)
            ->where('role', 'judge')
            ->where('is_chair', true)
            ->where('status', 'approved')
            ->first();

        if (! $chair) {
            return $this->error('فقط قاضي الرئاسة يمكنه تقديم النتيجة. | Only the chair judge can submit results.', [], 403);
        }

        // The result can be submitted during the result phase: speeches are done
        // (past the last stage) but the debate is still `live` and not yet closed.
        if (! $debate->isInResultPhase()) {
            return $this->error('يجب إنهاء جميع المراحل أولاً عبر /next-stage. | Speeches must be completed first (advance past the last stage via /next-stage).', [], 422);
        }

        if ($debate->result()->exists()) {
            return $this->error('تم تقديم النتيجة بالفعل. | Result already submitted.', [], 409);
        }

        // Build scores payload from stage_scores.
        $phases     = $debate->phases()->orderBy('order_index')->get()->keyBy('order_index');
        $stageData  = [];

        foreach ($request->stage_scores as $entry) {
            $phase = $phases->get($entry['stage_order']);
            if (! $phase) {
                continue;
            }

            $participantId = $phase->participant_id;
            $userId        = $participantId
                ? DebateParticipant::find($participantId)?->user_id
                : null;

            $stageData[] = [
                'stage_order'    => $entry['stage_order'],
                'participant_id' => $participantId,
                'user_id'        => $userId,
                'score'          => $entry['score'],
            ];
        }

        // Snapshot every attended approved judge — the whole panel contributed,
        // not just the chair who clicked submit.
        $contributingJudges = $debate->participants()
            ->where('role', 'judge')
            ->where('status', 'approved')
            ->where('is_attended', true)
            ->get(['user_id', 'judge_order', 'is_chair'])
            ->map(fn ($j) => [
                'user_id'     => (int) $j->user_id,
                'judge_order' => $j->judge_order,
                'is_chair'    => (bool) $j->is_chair,
            ])
            ->values()
            ->all();

        $result = DB::transaction(function () use ($request, $debate, $user, $stageData, $contributingJudges) {
            return DebateResult::create([
                'debate_id'           => $debate->id,
                'judge_id'            => $user->id,
                'contributing_judges' => $contributingJudges,
                'winning_side'        => $request->winning_side,
                'scores'        => [
                    'stages' => $stageData,
                    'notes'  => $request->summary_notes,
                ],
                'summary_notes' => $request->summary_notes,
                'submitted_at'  => now(),
            ]);
        });

        return $this->success(
            new DebateResultResource($result->load('judge')),
            'تم تقديم النتيجة. | Result submitted.',
            201
        );
    }

    // ── F7: POST /debates/{debate}/result/reveal ──────────────────────────────

    public function revealResult(Request $request, Debate $debate): JsonResponse
    {
        $user = $request->user();

        $chair = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $user->id)
            ->where('role', 'judge')
            ->where('is_chair', true)
            ->where('status', 'approved')
            ->first();

        if (! $chair) {
            return $this->error('فقط قاضي الرئاسة يمكنه الكشف عن النتيجة. | Only the chair judge can reveal the result.', [], 403);
        }

        // Reveal ("share result") happens from the LIVE room during the result
        // phase — status stays `live`. It does NOT finalise the debate; only
        // close-room does that.
        if (! $debate->isInResultPhase()) {
            return $this->error('النقاش لم يصل إلى مرحلة النتائج بعد. | Debate is not in the result phase yet.', [], 422);
        }

        if (! $debate->result()->exists()) {
            return $this->error('يجب تقديم النتيجة أولاً. | Result must be submitted before revealing.', [], 422);
        }

        if ($debate->result_revealed_at !== null) {
            return $this->error('النتيجة مكشوفة بالفعل. | Result is already revealed.', [], 409);
        }

        $debate->update(['result_revealed_at' => now()]);

        // The result room is left open through the result phase (it's torn down on
        // close-room). Broadcast the reveal to the main room so everyone there
        // sees the result — the chair shares it from the live room.
        try {
            $this->liveKit->sendDataToRoom(
                $debate->livekit_room_name,
                ['event' => 'result_revealed']
            );
        } catch (\Throwable) {}

        $debate->load(['format', 'motion.frameworks', 'participants.user', 'phases', 'result.judge']);
        $myParticipant = $debate->participants->firstWhere('user_id', $user->id);

        return $this->success(
            new LiveStateResource($debate, $myParticipant),
            'تم الكشف عن النتيجة. | Result revealed.'
        );
    }

    // ── V2: POST /debates/{debate}/rollback-to-lobby ──────────────────────────

    public function rollbackToLobby(Request $request, Debate $debate): JsonResponse
    {
        $user = $request->user();

        $chair = $this->chairParticipant($debate, $user->id);
        if (! $chair) {
            return $this->error('فقط قاضي الرئاسة يمكنه العودة إلى اللوبي. | Only the chair judge can return to the lobby.', [], 403);
        }

        if ($debate->status !== 'live' || $debate->current_stage < 1) {
            return $this->error('لا يمكن العودة إلى اللوبي إلا أثناء النقاش. | Can only roll back to the lobby during a live debate.', [], 422);
        }

        DB::transaction(function () use ($debate) {
            // Reset the currently active phase back to pending.
            $activePhase = DebatePhase::where('debate_id', $debate->id)
                ->where('order_index', $debate->current_stage)
                ->first();

            if ($activePhase) {
                if ($activePhase->egress_id) {
                    try {
                        $this->liveKit->stopEgress($activePhase->egress_id);
                    } catch (\Throwable) {
                        // Non-fatal — egress may have already ended.
                    }
                }

                $activePhase->update([
                    'status'         => 'pending',
                    'started_at'     => null,
                    'participant_id' => null,
                    'egress_id'      => null,
                    'audio_url'      => null,
                ]);
            }

            $debate->update(['current_stage' => 0]);

            // Re-open the prep rooms that were closed when the debate started.
            try {
                if ($debate->prop_room_name) {
                    $this->liveKit->createRoomIfMissing($debate->prop_room_name);
                }
                if ($debate->opp_room_name) {
                    $this->liveKit->createRoomIfMissing($debate->opp_room_name);
                }
            } catch (\Throwable) {}

            // Tell the frontend to drop back to lobby UI.
            try {
                $this->liveKit->sendDataToRoom(
                    $debate->livekit_room_name,
                    ['event' => 'returned_to_lobby']
                );
            } catch (\Throwable) {}
        });

        $debate->refresh()->load(['format', 'motion.frameworks', 'participants.user', 'phases', 'result.judge']);
        $myParticipant = $debate->participants->firstWhere('user_id', $user->id);

        return $this->success(
            new LiveStateResource($debate, $myParticipant),
            'تم الرجوع إلى اللوبي. | Returned to lobby.'
        );
    }

    // ── V2: POST /debates/{debate}/close-main ─────────────────────────────────

    public function closeMain(Request $request, Debate $debate): JsonResponse
    {
        $user = $request->user();

        $chair = $this->chairParticipant($debate, $user->id);
        if (! $chair) {
            return $this->error('فقط قاضي الرئاسة يمكنه إغلاق الغرفة الرئيسية. | Only the chair judge can close the main room.', [], 403);
        }

        // closeMain is the legacy "finish + close the main room" action; it is only
        // meaningful in the result phase (or once already completed).
        if (! $debate->isInResultPhase() && $debate->status !== 'completed') {
            return $this->error('لا يمكن إغلاق الغرفة الرئيسية إلا في مرحلة النتائج. | Main room can only be closed during the result phase.', [], 422);
        }

        DB::transaction(function () use ($debate) {
            // Close the main room on LiveKit.
            if ($debate->livekit_room_name) {
                try {
                    $this->liveKit->deleteRoomIfExists($debate->livekit_room_name);
                } catch (\Throwable) {}
            }

            // A result exists → the debate is finished: mark completed and reveal
            // if not already revealed.
            if ($debate->result()->exists()) {
                $updates = ['status' => 'completed'];
                if ($debate->ended_at === null) {
                    $updates['ended_at'] = now();
                }
                if ($debate->result_revealed_at === null) {
                    $updates['result_revealed_at'] = now();
                }
                $debate->update($updates);

                // Main room is gone — broadcast the reveal on the result room instead.
                if ($debate->result_room_name) {
                    try {
                        $this->liveKit->sendDataToRoom(
                            $debate->result_room_name,
                            ['event' => 'result_revealed']
                        );
                    } catch (\Throwable) {}
                }
            } else {
                // Edge case: closing main with no result submitted reveals nothing.
                \Illuminate\Support\Facades\Log::warning(
                    "Debate {$debate->id}: main room closed before any result was submitted."
                );
            }
        });

        $debate->refresh()->load(['format', 'motion.frameworks', 'participants.user', 'phases', 'result.judge']);
        $myParticipant = $debate->participants->firstWhere('user_id', $user->id);

        return $this->success(
            new LiveStateResource($debate, $myParticipant),
            'تم إغلاق الغرفة الرئيسية. | Main room closed.'
        );
    }

    // ── V2: POST /debates/{debate}/close-room ─────────────────────────────────

    /**
     * Terminal "close the room" action for the chair. Allowed from any
     * non-terminal status (teams-selected, live, completed, …). It either:
     *   - reveals an already-submitted-but-unrevealed result (a finished debate
     *     must not be cancelled), OR
     *   - cancels the debate (status=cancelled, reason=manual) when there is no
     *     finished result yet (the chair aborted early).
     * Either way the main LiveKit room is deleted so no one can rejoin. The
     * `room_closed` event is broadcast BEFORE deletion so it reaches everyone.
     */
    public function closeRoom(Request $request, Debate $debate): JsonResponse
    {
        $user = $request->user();

        $chair = $this->chairParticipant($debate, $user->id);
        if (! $chair) {
            return $this->error('فقط قاضي الرئاسة يمكنه إغلاق الغرفة. | Only the chair judge can close the room.', [], 403);
        }

        // close-room is the single terminal action. A debate that is already
        // finalised (completed or cancelled) cannot be closed again.
        if (in_array($debate->status, ['cancelled', 'completed'], true)) {
            return $this->error('النقاش منتهٍ بالفعل. | Debate is already finalised.', [], 422);
        }

        DB::transaction(function () use ($debate) {
            // The terminal rule: room closed AND a result stored → completed;
            // room closed with NO result → cancelled.
            $hasResult   = $debate->result()->exists();
            $finalStatus = $hasResult ? 'completed' : 'cancelled';

            // Broadcast room_closed (with the resolved final status) to the main
            // room FIRST — before it's deleted — so every client receives it.
            if ($debate->livekit_room_name) {
                try {
                    $this->liveKit->sendDataToRoom(
                        $debate->livekit_room_name,
                        ['event' => 'room_closed', 'status' => $finalStatus]
                    );
                } catch (\Throwable) {}
            }

            if ($hasResult) {
                // Finished debate: mark completed and reveal the result if needed.
                $updates = ['status' => 'completed'];
                if ($debate->ended_at === null) {
                    $updates['ended_at'] = now();
                }
                if ($debate->result_revealed_at === null) {
                    $updates['result_revealed_at'] = now();
                }
                $debate->update($updates);

                if ($debate->result_room_name) {
                    try {
                        $this->liveKit->sendDataToRoom($debate->result_room_name, ['event' => 'result_revealed']);
                    } catch (\Throwable) {}
                }
            } else {
                // No result → the chair aborted; cancel the debate.
                $debate->update(['status' => 'cancelled', 'cancellation_reason' => 'manual']);
            }

            // Delete BOTH the main and result rooms AFTER the broadcasts so no one
            // can rejoin once the debate is closed.
            foreach ([$debate->livekit_room_name, $debate->result_room_name] as $room) {
                if ($room) {
                    try {
                        $this->liveKit->deleteRoomIfExists($room);
                    } catch (\Throwable) {}
                }
            }
        });

        $debate->refresh()->load(['format', 'motion.frameworks', 'participants.user', 'phases', 'result.judge']);
        $myParticipant = $debate->participants->firstWhere('user_id', $user->id);

        return $this->success(
            new LiveStateResource($debate, $myParticipant),
            'تم إغلاق الغرفة. | Room closed.'
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Return the chair participant for this debate if the given user is the
     * approved, chair judge — otherwise null.
     */
    private function chairParticipant(Debate $debate, int $userId): ?DebateParticipant
    {
        return DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $userId)
            ->where('role', 'judge')
            ->where('is_chair', true)
            ->where('status', 'approved')
            ->first();
    }

    /**
     * Derive which approved participant should be the speaker for a given phase.
     *
     * Order alternates: stages 1,3,5 = proposition; stages 2,4,6 = opposition.
     * Reply stages (if any): prop reply = prop speaker 1, opp reply = opp speaker 1.
     *
     * speaking_phase_order 1 = first speaker, 2 = second, 3 = third.
     */
    private function resolveStageSpeaker(Debate $debate, DebatePhase $phase): ?DebateParticipant
    {
        $orderIndex = $phase->order_index;
        $isReply    = (bool) $phase->is_reply;

        // Determine side and speaker slot from order_index.
        if ($isReply) {
            $side = str_contains(strtolower($phase->name), 'opposition') ? 'opposition' : 'proposition';

            // Reply speaker is the participant explicitly flagged is_reply_speaker.
            $replySpeaker = DebateParticipant::where('debate_id', $debate->id)
                ->where('side', $side)
                ->where('role', 'debater')
                ->where('status', 'approved')
                ->where('is_reply_speaker', true)
                ->first();

            if ($replySpeaker) {
                return $replySpeaker;
            }

            // Defensive fallback: speaker #1 if no explicit reply speaker is set.
            $speakerSlot = 1;
        } else {
            // Odd order_index → proposition; even → opposition.
            $side        = ($orderIndex % 2 === 1) ? 'proposition' : 'opposition';
            $speakerSlot = (int) ceil($orderIndex / 2);
        }

        // Authoritative path: the stored speaker order (array of user_ids, with
        // duplicates allowed) resolves slot → user, so one debater can cover
        // multiple slots (multi-role teams). Slot is 1-based.
        $order = $debate->speakerOrderFor($side);
        $userId = $order[$speakerSlot - 1] ?? null;

        if ($userId) {
            $p = DebateParticipant::where('debate_id', $debate->id)
                ->where('side', $side)
                ->where('role', 'debater')
                ->where('status', 'approved')
                ->where('user_id', $userId)
                ->first();

            if ($p) {
                return $p;
            }
        }

        // Legacy fallback: resolve by the per-participant speaking_phase_order
        // (used when no speaker order array was stored, e.g. older debates).
        return DebateParticipant::where('debate_id', $debate->id)
            ->where('side', $side)
            ->where('role', 'debater')
            ->where('status', 'approved')
            ->where('speaking_phase_order', $speakerSlot)
            ->first();
    }
}
