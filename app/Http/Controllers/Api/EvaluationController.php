<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluation\StoreEvaluationRequest;
use App\Http\Resources\EvaluationResource;
use App\Models\Evaluation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $evaluations = Evaluation::with(['debater', 'debate'])
            ->where('trainer_id', $user->id)
            ->when($request->debater_id, fn ($q) => $q->where('debater_id', $request->debater_id))
            ->latest()
            ->paginate(20);

        return $this->paginated(EvaluationResource::collection($evaluations), $evaluations,  'تم جلب التقييمات. | Evaluations retrieved.');
    }

    public function store(StoreEvaluationRequest $request): JsonResponse
    {
        $evaluation = Evaluation::create([
            'trainer_id' => $request->user()->id,
            'debater_id' => $request->debater_id,
            'debate_id'  => $request->debate_id,
            'notes'      => $request->notes,
            'scores'     => $request->scores,
        ]);

        $evaluation->load(['trainer', 'debater', 'debate']);

        return $this->success(new EvaluationResource($evaluation), 'تم إنشاء التقييم. | Evaluation created.', 201);
    }

    public function show(Request $request, Evaluation $evaluation): JsonResponse
    {
        $user = $request->user();

        if ($evaluation->trainer_id !== $user->id && $user->role !== 'admin') {
            return $this->error('غير مصرح. | Forbidden.', [], 403);
        }

        $evaluation->load(['trainer', 'debater', 'debate']);

        return $this->success(new EvaluationResource($evaluation), 'تم جلب التقييم. | Evaluation retrieved.');
    }
}
