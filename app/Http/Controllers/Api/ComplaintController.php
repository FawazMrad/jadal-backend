<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Complaint\StoreComplaintRequest;
use App\Http\Requests\Complaint\UpdateComplaintRequest;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplaintController extends Controller
{
    public function myComplaints(Request $request): JsonResponse
    {
        $complaints = Complaint::where('filed_by', $request->user()->id)
            ->latest()
            ->paginate(20);

        return $this->paginated(ComplaintResource::collection($complaints), $complaints, 'تم جلب شكاواك. | Your complaints retrieved.');
    }

    public function store(StoreComplaintRequest $request): JsonResponse
    {
        $complaint = Complaint::create([
            'filed_by'       => $request->user()->id,
            'debate_id'      => $request->debate_id,
            'target_user_id' => $request->target_user_id,
            'target_role'    => $request->target_role,
            'description'    => $request->description,
            'status'         => 'open',
        ]);

        return $this->success(new ComplaintResource($complaint), 'تم تقديم الشكوى. | Complaint filed.', 201);
    }

    public function index(Request $request): JsonResponse
    {
        $complaints = Complaint::with(['filedBy', 'targetUser'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return $this->paginated(ComplaintResource::collection($complaints), $complaints, 'تم جلب الشكاوى. | Complaints retrieved.');
    }

    public function show(Complaint $complaint): JsonResponse
    {
        $complaint->load(['filedBy', 'targetUser']);

        return $this->success(new ComplaintResource($complaint), 'تم جلب الشكوى. | Complaint retrieved.');
    }

    public function update(UpdateComplaintRequest $request, Complaint $complaint): JsonResponse
    {
        $complaint->update($request->validated());

        return $this->success(
            new ComplaintResource($complaint->load(['filedBy', 'targetUser'])),
            'تم تحديث الشكوى. | Complaint updated.'
        );
    }
}
