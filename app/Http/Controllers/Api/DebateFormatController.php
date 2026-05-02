<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DebateFormat\StoreDebateFormatRequest;
use App\Http\Requests\DebateFormat\UpdateDebateFormatRequest;
use App\Http\Resources\DebateFormatResource;
use App\Models\DebateFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DebateFormatController extends Controller
{
    public function index(): JsonResponse
    {
        $formats = DebateFormat::orderBy('name')->get();

        return $this->success(
            DebateFormatResource::collection($formats),
            'تم جلب صيغ النقاش. | Debate formats retrieved.'
        );
    }

    public function show(DebateFormat $format): JsonResponse
    {
        return $this->success(
            new DebateFormatResource($format),
            'تم جلب صيغة النقاش. | Debate format retrieved.'
        );
    }

    public function store(StoreDebateFormatRequest $request): JsonResponse
    {
        $format = DebateFormat::create($request->validated());

        return $this->success(
            new DebateFormatResource($format),
            'تم إنشاء صيغة النقاش. | Debate format created.',
            201
        );
    }

    public function update(UpdateDebateFormatRequest $request, DebateFormat $format): JsonResponse
    {
        $format->update($request->validated());

        return $this->success(
            new DebateFormatResource($format),
            'تم تحديث صيغة النقاش. | Debate format updated.'
        );
    }

    public function destroy(DebateFormat $format): JsonResponse
    {
        $inUse = DB::table('debates')->where('format_id', $format->id)->exists();

        if ($inUse) {
            return $this->error(
                'لا يمكن حذف الصيغة لأنها مستخدمة في نقاشات. | Cannot delete format used by existing debates.',
                [],
                422
            );
        }

        $format->delete();

        return $this->success(null, 'تم حذف صيغة النقاش. | Debate format deleted.');
    }
}
