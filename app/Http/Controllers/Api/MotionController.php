<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Motion\StoreMotionRequest;
use App\Http\Requests\Motion\UpdateMotionRequest;
use App\Http\Requests\SearchListRequest;
use App\Http\Resources\MotionResource;
use App\Models\Motion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MotionController extends Controller
{
    /** Matches the debates listing: default 20, hard ceiling 50. */
    private const PER_PAGE_DEFAULT = 20;
    private const PER_PAGE_MAX     = 50;

    public function index(SearchListRequest $request): JsonResponse
    {
        // `per_page` was previously ignored entirely (paginate() was called with
        // a hardcoded 20), so clients sending per_page=200/1000 silently got 20
        // rows back. It is honoured now, but CLAMPED rather than validated: a
        // 422 would break those existing callers the moment this shipped,
        // whereas clamping just starts returning the sane maximum.
        $perPage = (int) $request->input('per_page', self::PER_PAGE_DEFAULT);
        $perPage = max(1, min($perPage, self::PER_PAGE_MAX));

        $motions = Motion::with(['addedBy', 'frameworks'])
            ->when(
                $request->framework_id,
                fn($q) => $q->whereHas('frameworks', fn($q2) => $q2->where('motion_frameworks.id', $request->framework_id))
            )
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->where('text', 'LIKE', "%{$term}%");
            })
            // Newest first. Without an explicit ORDER BY, InnoDB returns rows in
            // effectively primary-key order, so page 1 was the 20 OLDEST motions
            // and a newly created one landed on the LAST page — invisible to any
            // picker that reads only the first page.
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->paginated(MotionResource::collection($motions), $motions, 'تم جلب الحركات. | Motions retrieved.');
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
