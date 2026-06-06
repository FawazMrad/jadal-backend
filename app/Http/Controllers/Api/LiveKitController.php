<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Services\LiveKitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveKitController extends Controller
{
    public function __construct(private LiveKitService $liveKit) {}

    public function getToken(Request $request, int $debateId): JsonResponse
    {
        $user   = $request->user();
        $debate = Debate::findOrFail($debateId);

        $participant = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

        if (! $participant) {
            return $this->error(
                'أنت لست مشاركاً معتمداً في هذا النقاش. | You are not an approved participant of this debate.',
                [],
                403
            );
        }

        $identity = (string) $user->id;
        $token    = $this->liveKit->generateToken(
            $debate->livekit_room_name,
            $identity,
            $participant->role,
            (bool) $participant->is_chair
        );

        return $this->success([
            'token'     => $token,
            'url'       => config('services.livekit.url'),
            'room_name' => $debate->livekit_room_name,
        ], 'تم إنشاء رمز الوصول. | Access token generated.');
    }
}
