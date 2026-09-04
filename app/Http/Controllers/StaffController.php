<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Models\User;
use App\Services\SystemAuditLogger;
use App\Support\AuditRemarks;
use App\Support\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function __construct(private SystemAuditLogger $audit) {}

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

        $mfaError = $this->authorizeEnforceMfa(
            $request->user(),
            $validated['role'],
            array_key_exists('enforce_mfa', $validated),
        );
        if ($mfaError) {
            return $mfaError;
        }

        $enforce = $validated['role'] === User::ROLE_ADMIN
            && ($request->user()?->isSuperAdmin() ?? false)
            && (bool) ($validated['enforce_mfa'] ?? false);

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
            'enforce_mfa' => $enforce,
        ]);

        $this->audit->record('user.created', $request->user(), $user, [
            'role' => $user->role,
            'email' => $user->email,
            'enforce_mfa' => $user->enforce_mfa,
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
        $before = $user->only(['name', 'email', 'role', 'assigned_barangay', 'is_active', 'enforce_mfa']);
        $wasEnforced = (bool) $user->enforce_mfa;

        if (array_key_exists('is_active', $validated) && $validated['is_active'] === false) {
            if ($user->id === $actor->id) {
                return $this->unprocessable('You cannot deactivate your own account.');
            }
            if (StaffAccess::isLastActiveSuperAdmin($user)) {
                return $this->unprocessable('The last SuperAdmin account cannot be deactivated.');
            }
            AuditRemarks::require($request, 'A justification is required before deactivating a staff account.');
        }

        if ($user->isSuperAdmin()) {
            unset($validated['role'], $validated['is_active'], $validated['assigned_barangay'], $validated['enforce_mfa']);
        }

        $effectiveRole = $validated['role'] ?? $user->role;

        $mfaError = $this->authorizeEnforceMfa(
            $actor,
            $effectiveRole,
            array_key_exists('enforce_mfa', $validated),
        );
        if ($mfaError) {
            return $mfaError;
        }

        if (array_key_exists('role', $validated) && $validated['role'] !== User::ROLE_BARANGAY_OFFICIAL) {
            $validated['assigned_barangay'] = null;
        }

        if (! in_array($effectiveRole, [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], true)) {
            $validated['enforce_mfa'] = false;
        }

        $user->fill($validated);
        $user->save();

        $nowEnforced = (bool) $user->enforce_mfa;
        if (array_key_exists('is_active', $validated) && $validated['is_active'] === false) {
            $user->tokens()->delete();
        } elseif (! $wasEnforced && $nowEnforced) {
            $user->tokens()->delete();
        }

        $action = (array_key_exists('is_active', $validated) && $validated['is_active'] === false)
            ? 'user.deactivated'
            : 'user.updated';

        $this->audit->record($action, $actor, $user, [
            'before' => $before,
            'after' => $user->only(['name', 'email', 'role', 'assigned_barangay', 'is_active', 'enforce_mfa']),
            'remarks' => AuditRemarks::optional($request),
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

        $remarks = AuditRemarks::require($request, 'A justification is required before resetting a staff password.');

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
            'remarks' => $remarks,
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

    private function authorizeEnforceMfa(User $actor, string $effectiveRole, bool $requested): ?JsonResponse
    {
        if (! $requested) {
            return null;
        }

        if (! $actor->isSuperAdmin()) {
            return $this->unprocessable('Only a SuperAdmin can change MFA enforcement.');
        }

        if ($effectiveRole !== User::ROLE_ADMIN) {
            return $this->unprocessable('Authenticator enforcement can only be set on MAO Administrator accounts.');
        }

        return null;
    }

    private function temporarySecret(): string
    {
        return User::TEMPORARY_PASSWORD;
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
