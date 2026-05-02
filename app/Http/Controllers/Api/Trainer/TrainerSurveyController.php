<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Survey\StoreQuestionRequest;
use App\Http\Requests\Survey\StoreSurveyRequest;
use App\Http\Requests\Survey\UpdateQuestionRequest;
use App\Http\Resources\SurveyDetailResource;
use App\Http\Resources\SurveyResource;
use App\Http\Resources\SurveyResultResource;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainerSurveyController extends Controller
{
    // ── List own surveys ──────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $surveys = Survey::with('createdBy')
            ->where('created_by', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return $this->paginated(
            SurveyResource::collection($surveys),
            $surveys,
            'تم جلب الاستطلاعات. | Surveys retrieved.'
        );
    }

    // ── Create survey (linked to trainer's teams) ─────────────────────────────

    public function store(StoreSurveyRequest $request): JsonResponse
    {
        $data = $request->validated();

        $survey = Survey::create([
            'created_by'   => $request->user()->id,
            'title'        => $data['title'],
            'description'  => $data['description'] ?? null,
            'target_roles' => ['debater'],
            'closes_at'    => $data['closes_at'] ?? null,
        ]);

        DB::table('survey_teams')->insert(
            collect($data['team_ids'])->map(fn($teamId) => [
                'survey_id' => $survey->id,
                'team_id'   => $teamId,
            ])->toArray()
        );

        $survey->load(['createdBy', 'questions']);

        return $this->success(
            new SurveyDetailResource($survey),
            'تم إنشاء الاستطلاع. | Survey created.',
            201
        );
    }

    // ── View own survey with questions ────────────────────────────────────────

    public function show(Request $request, Survey $survey): JsonResponse
    {
        if ($survey->created_by !== $request->user()->id) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $survey->load(['createdBy', 'questions']);

        return $this->success(
            new SurveyDetailResource($survey),
            'تم جلب الاستطلاع. | Survey retrieved.'
        );
    }

    // ── Delete own survey ─────────────────────────────────────────────────────

    public function destroy(Request $request, Survey $survey): JsonResponse
    {
        if ($survey->created_by !== $request->user()->id) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $survey->delete();

        return $this->success(null, 'تم حذف الاستطلاع. | Survey deleted.');
    }

    // ── View responses of own survey ──────────────────────────────────────────

    public function results(Request $request, Survey $survey): JsonResponse
    {
        if ($survey->created_by !== $request->user()->id) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $survey->load(['questions', 'responses.user']);

        return $this->success(
            new SurveyResultResource($survey),
            'تم جلب النتائج. | Results retrieved.'
        );
    }

    // ── Add question ──────────────────────────────────────────────────────────

    public function storeQuestion(StoreQuestionRequest $request, Survey $survey): JsonResponse
    {
        if ($survey->created_by !== $request->user()->id) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $data = $request->validated();

        $question = $survey->questions()->create([
            'question_text' => $data['question_text'],
            'type'          => $data['type'],
            'options'       => $data['options'] ?? null,
            'order_index'   => $data['order_index'] ?? (($survey->questions()->max('order_index') ?? -1) + 1),
        ]);

        return $this->success(
            [
                'id'            => $question->id,
                'question_text' => $question->question_text,
                'type'          => $question->type,
                'options'       => $question->options,
                'order_index'   => $question->order_index,
            ],
            'تم إضافة السؤال. | Question added.',
            201
        );
    }

    // ── Edit question ─────────────────────────────────────────────────────────

    public function updateQuestion(UpdateQuestionRequest $request, Survey $survey, SurveyQuestion $question): JsonResponse
    {
        if ($survey->created_by !== $request->user()->id) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        if ($question->survey_id !== $survey->id) {
            return $this->error('السؤال لا ينتمي لهذا الاستطلاع. | Question does not belong to this survey.', [], 404);
        }

        $question->update($request->validated());

        return $this->success(
            [
                'id'            => $question->id,
                'question_text' => $question->question_text,
                'type'          => $question->type,
                'options'       => $question->options,
                'order_index'   => $question->order_index,
            ],
            'تم تحديث السؤال. | Question updated.'
        );
    }

    // ── Delete question ───────────────────────────────────────────────────────

    public function destroyQuestion(Request $request, Survey $survey, SurveyQuestion $question): JsonResponse
    {
        if ($survey->created_by !== $request->user()->id) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        if ($question->survey_id !== $survey->id) {
            return $this->error('السؤال لا ينتمي لهذا الاستطلاع. | Question does not belong to this survey.', [], 404);
        }

        $question->delete();

        return $this->success(null, 'تم حذف السؤال. | Question deleted.');
    }
}
