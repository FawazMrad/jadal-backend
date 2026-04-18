<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    // ── FR-45: List all users (paginated, filterable) ─────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->when($request->role, fn ($q) => $q->where('role', $request->role))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->latest();

        $users = $query->paginate($request->integer('per_page', 15));

        return $this->paginated(
            UserResource::collection($users),
            $users,
            'تم جلب المستخدمين بنجاح. | Users retrieved.'
        );
    }

    // ── FR-45: Create new user ────────────────────────────────────────────────

    public function store(CreateUserRequest $request): JsonResponse
    {
        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => $request->password, // hashed automatically by cast
            'role'     => $request->role,
            'phone'    => $request->phone,
            'status'   => 'active',
        ]);

        // Assign Spatie role — uses the default guard (matches guard in permission tables)
        $user->assignRole($request->role);

        // TODO: send welcome email with credentials to $user->email

        return $this->success(new UserResource($user), 'تم إنشاء المستخدم بنجاح. | User created.', 201);
    }

    // ── FR-46: View any user's profile ───────────────────────────────────────

    public function show(User $user): JsonResponse
    {
        return $this->success(new UserResource($user), 'تم جلب بيانات المستخدم. | User retrieved.');
    }

    // ── FR-46: Update any user's profile ─────────────────────────────────────

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        // If role is changing, sync Spatie role too
        if (isset($data['role']) && $data['role'] !== $user->role) {
            $user->syncRoles([$data['role']]);
        }

        $user->update($data);

        return $this->success(new UserResource($user->fresh()), 'تم تحديث بيانات المستخدم. | User updated.');
    }

    // ── FR-47 / FR-48: Change user status ────────────────────────────────────

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return $this->error(
                'لا يمكنك تغيير حالة حسابك الخاص. | You cannot change your own account status.',
                [],
                422
            );
        }

        $oldStatus = $user->status;
        $user->update(['status' => $request->status]);

        // Revoke all tokens when suspending or banning so they can't continue using the API
        if ($request->status !== 'active') {
            $user->tokens()->delete();
        }

        return $this->success(
            new UserResource($user->fresh()),
            "تم تغيير الحالة من {$oldStatus} إلى {$request->status}. | Status changed from {$oldStatus} to {$request->status}."
        );
    }

    // ── FR-47: Delete user ───────────────────────────────────────────────────

    public function destroy(User $user): JsonResponse
    {
        // Safety guard: admin cannot delete themselves
        if ($user->id === request()->user()->id) {
            return $this->error(
                'لا يمكنك حذف حسابك الخاص. | You cannot delete your own account.',
                [],
                422
            );
        }

        // NOTE: User may have related debates, teams, feedbacks, evaluations, etc.
        // Those records reference this user via FKs with cascadeOnDelete or nullOnDelete
        // as defined in migrations. Review carefully before deleting in production.
        $user->tokens()->delete();
        $user->delete();

        return $this->success(null, 'تم حذف المستخدم بنجاح. | User deleted.');
    }
}
