<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * List active users for staff pickers. Admins cannot list privileged accounts.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $allowed = StaffAccess::listableRoles($actor);

        $query = User::query()->where('is_active', true)->whereIn('role', $allowed);

        $role = trim((string) $request->query('role', ''));
        if ($role !== '') {
            if (! in_array($role, $allowed, true)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('role', $role);
            }
        }

        $users = $query
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'assigned_barangay']);

        return response()->json([
            'status' => 'success',
            'message' => 'Users retrieved.',
            'data' => $users,
        ]);
    }
}
