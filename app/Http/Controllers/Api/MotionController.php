<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Motion\StoreMotionRequest;
use App\Http\Requests\Motion\UpdateMotionRequest;
use App\Http\Resources\MotionResource;
use App\Models\Motion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $motions = Motion::with(['addedBy', 'frameworks'])
            ->when(
                $request->framework_id,
                fn ($q) => $q->whereHas('frameworks', fn ($q2) => $q2->where('motion_frameworks.id', $request->framework_id))
            )
            ->paginate(20);

        return $this->paginated($motions, MotionResource::class, 'تم جلب الحركات. | Motions retrieved.');
    }

    public function show(Motion $motion): JsonResponse
    {
        $motion->load(['addedBy', 'frameworks']);

        return $this->success(new MotionResource($motion), 'تم جلب الحركة. | Motion retrieved.');
    }

    public function store(StoreMotionRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->role, ['judge', 'admin'])) {
            return $this->error('غير مصرح. | Forbidden.', [], 403);
        }

        $motion = Motion::create([
            'added_by' => $user->id,
            'text'     => $request->text,
        ]);

        if ($request->filled('framework_ids')) {
            $rows = collect($request->framework_ids)
                ->map(fn ($id) => ['motion_id' => $motion->id, 'framework_id' => $id])
                ->all();
            DB::table('motion_framework_pivot')->insert($rows);
        }

        $motion->load(['addedBy', 'frameworks']);

        return $this->success(new MotionResource($motion), 'تم إنشاء الحركة. | Motion created.', 201);
    }

    public function update(UpdateMotionRequest $request, Motion $motion): JsonResponse
    {
        $user = $request->user();

        if ($motion->added_by !== $user->id && $user->role !== 'admin') {
            return $this->error('غير مصرح. | Forbidden.', [], 403);
        }

        if ($request->has('text')) {
            $motion->update(['text' => $request->text]);
        }

        if ($request->has('framework_ids')) {
            DB::table('motion_framework_pivot')->where('motion_id', $motion->id)->delete();
            if (count($request->framework_ids)) {
                $rows = collect($request->framework_ids)
                    ->map(fn ($id) => ['motion_id' => $motion->id, 'framework_id' => $id])
                    ->all();
                DB::table('motion_framework_pivot')->insert($rows);
            }
        }

        $motion->load(['addedBy', 'frameworks']);

        return $this->success(new MotionResource($motion), 'تم تحديث الحركة. | Motion updated.');
    }

    public function destroy(Request $request, Motion $motion): JsonResponse
    {
        $user = $request->user();

        if ($motion->added_by !== $user->id && $user->role !== 'admin') {
            return $this->error('غير مصرح. | Forbidden.', [], 403);
        }

        if (DB::table('debates')->where('motion_id', $motion->id)->exists()) {
            return $this->error(
                'لا يمكن حذف الحركة لأنها مستخدمة في نقاشات. | Cannot delete motion used by existing debates.',
                [],
                422
            );
        }

        $motion->delete();

        return $this->success(null, 'تم حذف الحركة. | Motion deleted.');
    }
}
