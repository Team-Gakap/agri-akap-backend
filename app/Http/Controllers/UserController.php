<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * List active users, optionally filtered by role (admin staff pickers).
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->where('is_active', true);

        $role = trim((string) $request->query('role', ''));
        if ($role !== '') {
            $query->where('role', $role);
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
