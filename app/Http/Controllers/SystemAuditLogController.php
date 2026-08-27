<?php

namespace App\Http\Controllers;

use App\Models\SystemAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SystemAuditLog::query()->orderByDesc('created_at');

        $action = trim((string) $request->query('action', ''));
        if ($action !== '') {
            $query->where('action', $action);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('actor_email', 'like', $term)
                    ->orWhere('action', 'like', $term);
            });
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to').' 23:59:59');
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        return response()->json([
            'status' => 'success',
            'message' => 'Audit logs retrieved.',
            'data' => $query->paginate($perPage),
        ]);
    }
}
