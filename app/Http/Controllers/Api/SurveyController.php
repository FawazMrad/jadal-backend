<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Survey\SubmitResponseRequest;
use App\Http\Resources\SurveyDetailResource;
use App\Http\Resources\SurveyResource;
use App\Models\Survey;
use App\Models\SurveyResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SurveyController extends Controller
{
    // ── List surveys visible to this user ─────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Survey IDs from trainer surveys where user is a current member of a linked team
        $trainerSurveyIds = DB::table('survey_teams')
            ->join('team_members', 'survey_teams.team_id', '=', 'team_members.team_id')
            ->where('team_members.user_id', $user->id)
            ->where('team_members.status', 'current')
            ->pluck('survey_teams.survey_id');

        $surveys = Survey::with('createdBy')
            ->where(function ($outer) use ($user, $trainerSurveyIds) {
                $outer->where(function ($q) use ($user) {
                    // Admin surveys where user's role is in target_roles
                    $q->whereHas('createdBy', fn($u) => $u->where('role', 'admin'))
                      ->whereJsonContains('target_roles', $user->role);
                })->orWhereIn('id', $trainerSurveyIds);
            })
            ->where(function ($q) {
                // Only open surveys
                $q->whereNull('closes_at')->orWhere('closes_at', '>', now());
            })
            ->withExists(['responses as already_responded' => fn($q) => $q->where('user_id', $user->id)])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return $this->paginated(
            SurveyResource::collection($surveys),
            $surveys,
            'تم جلب الاستطلاعات. | Surveys retrieved.'
        );
    }

    // ── View single survey with questions ─────────────────────────────────────

    public function show(Request $request, Survey $survey): JsonResponse
    {
        $user = $request->user();

        if ($survey->closes_at && $survey->closes_at->isPast()) {
            return $this->error('هذا الاستطلاع مغلق. | This survey is closed.', [], 403);
        }

        $survey->loadMissing('createdBy');

        if (! $this->canAccess($survey, $user)) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $survey->load('questions');
        $survey->already_responded = SurveyResponse::where('survey_id', $survey->id)
            ->where('user_id', $user->id)
            ->exists();

        return $this->success(
            new SurveyDetailResource($survey),
            'تم جلب الاستطلاع. | Survey retrieved.'
        );
    }

    // ── Submit response ───────────────────────────────────────────────────────

    public function respond(SubmitResponseRequest $request, Survey $survey): JsonResponse
    {
        $user = $request->user();

        if ($survey->closes_at && $survey->closes_at->isPast()) {
            return $this->error('انتهت صلاحية هذا الاستطلاع. | This survey has closed.', [], 422);
        }

        $survey->loadMissing('createdBy');

        if (! $this->canAccess($survey, $user)) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $alreadyResponded = SurveyResponse::where('survey_id', $survey->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyResponded) {
            return $this->error(
                'لقد أجبت على هذا الاستطلاع مسبقاً. | You have already responded to this survey.',
                ['already_responded' => true],
                409
            );
        }

        SurveyResponse::create([
            'survey_id' => $survey->id,
            'user_id'   => $user->id,
            'answers'   => $request->answers,
        ]);

        return $this->success(null, 'تم تقديم إجاباتك بنجاح. | Response submitted successfully.');
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function canAccess(Survey $survey, $user): bool
    {
        $creator = $survey->createdBy;

        if (! $creator) {
            return false;
        }

        if ($creator->role === 'admin') {
            return in_array($user->role, $survey->target_roles ?? [], true);
        }

        if ($creator->role === 'trainer') {
            return DB::table('survey_teams')
                ->join('team_members', 'survey_teams.team_id', '=', 'team_members.team_id')
                ->where('survey_teams.survey_id', $survey->id)
                ->where('team_members.user_id', $user->id)
                ->where('team_members.status', 'current')
                ->exists();
        }

        return false;
    }
}
