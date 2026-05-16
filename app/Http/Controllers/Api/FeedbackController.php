<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Feedback\StoreFeedbackRequest;
use App\Http\Resources\FeedbackResource;
use App\Models\Feedbacks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $feedbacks = Feedbacks::with(['fromUser', 'toUser'])
            ->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))
            ->when($request->debate_id, fn ($q) => $q->where('debate_id', $request->debate_id))
            ->latest()
            ->paginate(20);

        return $this->paginated(FeedbackResource::collection($feedbacks), $feedbacks, 'تم جلب التغذية الراجعة. | Feedbacks retrieved.');
    }

    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        $user = $request->user();

        $feedback = Feedbacks::create([
            'debate_id'    => $request->debate_id,
            'from_user_id' => $user->id,
            'to_user_id'   => $request->to_user_id,
            'type'         => $request->type,
            'content'      => $request->content,
            'scores'       => $request->scores,
        ]);

        $feedback->load(['fromUser', 'toUser']);

        return $this->success(new FeedbackResource($feedback), 'تم إنشاء التغذية الراجعة. | Feedback created.', 201);
    }
}
