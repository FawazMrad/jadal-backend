<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Motion\StoreFrameworkRequest;
use App\Http\Requests\SearchListRequest;
use App\Http\Resources\MotionFrameworkResource;
use App\Models\MotionFramework;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MotionFrameworkController extends Controller
{
    public function index(SearchListRequest $request): JsonResponse
    {
        $frameworks = MotionFramework::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->where('name', 'LIKE', "%{$term}%");
            })
            ->get();

        return $this->success(
            MotionFrameworkResource::collection($frameworks),
            'تم جلب أطر الحركة. | Motion frameworks retrieved.'
        );
    }

    public function store(StoreFrameworkRequest $request): JsonResponse
    {
        $framework = MotionFramework::create($request->validated());

        return $this->success(
            new MotionFrameworkResource($framework),
            'تم إنشاء الإطار. | Framework created.',
            201
        );
    }

    public function update(Request $request, MotionFramework $framework): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'sometimes|required|max:100',
            'color_hex' => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $framework->update($validated);

        return $this->success(
            new MotionFrameworkResource($framework),
            'تم تحديث الإطار. | Framework updated.'
        );
    }

    public function destroy(MotionFramework $framework): JsonResponse
    {
        if (DB::table('motion_framework_pivot')->where('framework_id', $framework->id)->exists()) {
            return $this->error(
                'لا يمكن حذف الإطار لأنه مرتبط بحركات. | Cannot delete framework linked to motions.',
                [],
                422
            );
        }

        $framework->delete();

        return $this->success(null, 'تم حذف الإطار. | Framework deleted.');
    }
}
