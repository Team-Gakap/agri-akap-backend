<?php

namespace App\Http\Controllers;

use App\Models\Fca;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FcaController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Fca::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return response()->json([
            'status' => 'success',
            'message' => 'FCAs loaded.',
            'data' => $rows,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
        ]);

        $name = trim($validated['name']);
        $existing = Fca::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            if (! $existing->is_active) {
                $existing->update(['is_active' => true, 'name' => $name]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'FCA is already on the list.',
                'data' => $existing->fresh(),
            ]);
        }

        $fca = Fca::query()->create([
            'name' => $name,
            'is_active' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'FCA added.',
            'data' => $fca,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $fca = Fca::query()->findOrFail($id);
        $fca->update(['is_active' => $validated['is_active']]);

        return response()->json([
            'status' => 'success',
            'message' => $fca->is_active ? 'FCA restored.' : 'FCA deactivated.',
            'data' => $fca->fresh(),
        ]);
    }
}
