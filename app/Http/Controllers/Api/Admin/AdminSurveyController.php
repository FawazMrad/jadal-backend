<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Survey\StoreQuestionRequest;
use App\Http\Requests\Survey\StoreSurveyRequest;
use App\Http\Requests\Survey\UpdateQuestionRequest;
use App\Http\Resources\SurveyDetailResource;
use App\Http\Resources\SurveyResource;
use App\Http\Resources\SurveyResultResource;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSurveyController extends Controller
{
    // ── List all surveys ──────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $surveys = Survey::with(['createdBy', 'questions'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return $this->paginated(
            SurveyDetailResource::collection($surveys),
            $surveys,
            'تم جلب الاستطلاعات. | Surveys retrieved.'
        );
    }

    // ── Create survey ─────────────────────────────────────────────────────────

    public function store(StoreSurveyRequest $request): JsonResponse
    {
        $data = $request->validated();

        $survey = Survey::create([
            'created_by'   => $request->user()->id,
            'title'        => $data['title'],
            'description'  => $data['description'] ?? null,
            'target_roles' => $data['target_roles'],
            'closes_at'    => $data['closes_at'] ?? null,
        ]);

        $survey->load(['createdBy', 'questions']);

        return $this->success(
            new SurveyDetailResource($survey),
            'تم إنشاء الاستطلاع. | Survey created.',
            201
        );
    }

    // ── View single survey with questions ─────────────────────────────────────

    public function show(Survey $survey): JsonResponse
    {
        $survey->load(['createdBy', 'questions']);

        return $this->success(
            new SurveyDetailResource($survey),
            'تم جلب الاستطلاع. | Survey retrieved.'
        );
    }

    // ── Delete survey ─────────────────────────────────────────────────────────

    public function destroy(Survey $survey): JsonResponse
    {
        $survey->delete();

        return $this->success(null, 'تم حذف الاستطلاع. | Survey deleted.');
    }

    // ── View all responses + aggregate results ────────────────────────────────

    public function results(Survey $survey): JsonResponse
    {
        $survey->load(['questions', 'responses.user']);

        return $this->success(
            new SurveyResultResource($survey),
            'تم جلب النتائج. | Results retrieved.'
        );
    }

    // ── Add question ──────────────────────────────────────────────────────────

    public function storeQuestion(StoreQuestionRequest $request, Survey $survey): JsonResponse
    {
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

    public function destroyQuestion(Survey $survey, SurveyQuestion $question): JsonResponse
    {
        if ($question->survey_id !== $survey->id) {
            return $this->error('السؤال لا ينتمي لهذا الاستطلاع. | Question does not belong to this survey.', [], 404);
        }

        $question->delete();

        return $this->success(null, 'تم حذف السؤال. | Question deleted.');
    }
}