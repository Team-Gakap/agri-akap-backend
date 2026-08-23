<?php

namespace App\Traits;

use App\Models\Farmer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait ResolvesEncodingBarangay
{
    /**
     * Resolve the effective barangay for data-encoding requests.
     * Barangay officials are locked to assigned_barangay; admins may override via barangay_name.
     *
     * @return array{barangay: string|null}|JsonResponse
     */
    protected function resolveEncodingBarangay(Request $request, ?Farmer $farmer = null): array|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->role === 'barangay_official') {
            $barangay = $user->assigned_barangay;
            if (! $barangay) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No assigned barangay on this account. Contact MAO admin.',
                ], 403);
            }

            if ($farmer && $farmer->permanent_brgy !== $barangay) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You can only encode farmers from your assigned barangay.',
                ], 403);
            }

            return ['barangay' => $barangay];
        }

        if ($user->role === 'admin') {
            $barangay = $request->input('barangay_name');
            if (! $barangay) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Target barangay (barangay_name) is required for admin override encoding.',
                ], 422);
            }

            if ($farmer && $farmer->permanent_brgy !== $barangay) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Selected farmer does not belong to the target barangay.',
                ], 422);
            }

            return ['barangay' => $barangay];
        }

        return ['barangay' => null];
    }

    /**
     * Apply barangay scoping to ledger index queries.
     */
    protected function applyEncodingBarangayScope($query, Request $request, string $farmerRelation = 'farmer'): void
    {
        $user = $request->user();
        $barangayParam = $request->input('barangay');

        if ($user->role === 'barangay_official' && $user->assigned_barangay) {
            $query->whereHas($farmerRelation, fn ($f) => $f->where('permanent_brgy', $user->assigned_barangay));
        } elseif ($user->role === 'admin' && ! empty($barangayParam)) {
            $query->whereHas($farmerRelation, fn ($f) => $f->where('permanent_brgy', $barangayParam));
        } elseif (! empty($barangayParam)) {
            $query->whereHas($farmerRelation, fn ($f) => $f->where('permanent_brgy', $barangayParam));
        }
    }

    protected function assertCanDeleteEncodedRecord(Request $request, ?Farmer $farmer): ?JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->role === 'barangay_official') {
            if (! $user->assigned_barangay) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No assigned barangay on this account.',
                ], 403);
            }

            if ($farmer && $farmer->permanent_brgy !== $user->assigned_barangay) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You can only remove records from your assigned barangay.',
                ], 403);
            }
        }

        return null;
    }
}
