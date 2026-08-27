<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Models\User;
use App\Services\SystemAuditLogger;
use App\Support\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StaffController extends Controller
{
    public function __construct(private SystemAuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $roles = StaffAccess::listableRoles($actor);

        $query = User::query()->withCount('tokens')->whereIn('role', $roles);

        $role = trim((string) $request->query('role', ''));
        if ($role !== '') {
            if (! in_array($role, $roles, true)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('role', $role);
            }
        }

        $status = trim((string) $request->query('status', 'all'));
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status === 'locked') {
            $query->whereNotNull('locked_until')->where('locked_until', '>', now());
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('email', 'like', $term);
            });
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $page = $query->orderBy('name')->paginate($perPage);
        $page->getCollection()->transform(fn (User $user) => $user->toStaffPayload());

        return response()->json([
            'status' => 'success',
            'message' => 'Staff retrieved.',
            'data' => $page,
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $roles = StaffAccess::listableRoles($actor);
        $base = User::query()->whereIn('role', $roles);

        $byRole = [];
        foreach ($roles as $role) {
            $byRole[$role] = (clone $base)->where('role', $role)->count();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Staff summary retrieved.',
            'data' => [
                'by_role' => $byRole,
                'total' => (clone $base)->count(),
                'deactivated' => (clone $base)->where('is_active', false)->count(),
                'locked' => (clone $base)->whereNotNull('locked_until')->where('locked_until', '>', now())->count(),
            ],
        ]);
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $temporary = $this->temporarySecret();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $temporary,
            'role' => $validated['role'],
            'assigned_barangay' => $validated['role'] === User::ROLE_BARANGAY_OFFICIAL
                ? ($validated['assigned_barangay'] ?? null)
                : null,
            'is_active' => $validated['is_active'] ?? true,
            'must_change_password' => true,
            'password_changed_at' => now(),
        ]);

        $this->audit->record('user.created', $request->user(), $user, [
            'role' => $user->role,
            'email' => $user->email,
        ], $request);

        return response()->json([
            'status' => 'success',
            'message' => 'Account created. Share the temporary password once.',
            'data' => [
                'user' => $user->toStaffPayload(),
                'temporary_password' => $temporary,
            ],
        ], 201);
    }

    public function update(UpdateStaffRequest $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! StaffAccess::canManage($actor, $user)) {
            return $this->forbidden('You cannot manage this account.');
        }

        $validated = $request->validated();
        $before = $user->only(['name', 'email', 'role', 'assigned_barangay', 'is_active']);

        if (array_key_exists('is_active', $validated) && $validated['is_active'] === false) {
            if ($user->id === $actor->id) {
                return $this->unprocessable('You cannot deactivate your own account.');
            }
            if (StaffAccess::isLastActiveSuperAdmin($user)) {
                return $this->unprocessable('The last SuperAdmin account cannot be deactivated.');
            }
        }

        if ($user->isSuperAdmin()) {
            unset($validated['role'], $validated['is_active'], $validated['assigned_barangay']);
        }

        if (array_key_exists('role', $validated) && $validated['role'] !== User::ROLE_BARANGAY_OFFICIAL) {
            $validated['assigned_barangay'] = null;
        }

        $user->fill($validated);
        $user->save();

        if (array_key_exists('is_active', $validated) && $validated['is_active'] === false) {
            $user->tokens()->delete();
        }

        $action = (array_key_exists('is_active', $validated) && $validated['is_active'] === false)
            ? 'user.deactivated'
            : 'user.updated';

        $this->audit->record($action, $actor, $user, [
            'before' => $before,
            'after' => $user->only(['name', 'email', 'role', 'assigned_barangay', 'is_active']),
        ], $request);

        return response()->json([
            'status' => 'success',
            'message' => 'Account updated.',
            'data' => $user->fresh()->toStaffPayload(),
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! StaffAccess::canManage($actor, $user) || $user->isSuperAdmin()) {
            return $this->forbidden('You cannot reset this account password.');
        }

        $temporary = $this->temporarySecret();
        $user->forceFill([
            'password' => $temporary,
            'must_change_password' => true,
            'password_changed_at' => now(),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
        $user->tokens()->delete();

        $this->audit->record('password.reset', $actor, $user, [
            'email' => $user->email,
        ], $request);

        return response()->json([
            'status' => 'success',
            'message' => 'Temporary password generated. All sessions were revoked.',
            'data' => [
                'user' => $user->toStaffPayload(),
                'temporary_password' => $temporary,
            ],
        ]);
    }

    public function unlock(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! StaffAccess::canManage($actor, $user) || $user->isSuperAdmin()) {
            return $this->forbidden('You cannot unlock this account.');
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $this->audit->record('user.unlocked', $actor, $user, [
            'email' => $user->email,
        ], $request);

        return response()->json([
            'status' => 'success',
            'message' => 'Account unlocked.',
            'data' => $user->toStaffPayload(),
        ]);
    }

    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! StaffAccess::canManage($actor, $user) || ($user->isSuperAdmin() && $user->id !== $actor->id)) {
            return $this->forbidden('You cannot revoke sessions for this account.');
        }

        $user->tokens()->delete();

        $this->audit->record('session.revoked', $actor, $user, [
            'email' => $user->email,
        ], $request);

        return response()->json([
            'status' => 'success',
            'message' => 'All sessions revoked.',
            'data' => $user->fresh()->toStaffPayload(),
        ]);
    }

    private function temporarySecret(): string
    {
        return Str::password(12, symbols: false);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 403);
    }

    private function unprocessable(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 422);
    }
}
