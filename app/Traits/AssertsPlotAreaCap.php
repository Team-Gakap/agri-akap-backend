<?php

namespace App\Traits;

use App\Models\FarmPlot;
use Illuminate\Http\JsonResponse;

trait AssertsPlotAreaCap
{
    protected function assertAreaWithinPlot(
        ?string $farmPlotId,
        ?float $area,
        string $label = 'Area',
    ): ?JsonResponse {
        if ($farmPlotId === null || $farmPlotId === '' || $area === null) {
            return null;
        }

        $plot = FarmPlot::find($farmPlotId);
        if (! $plot) {
            return response()->json([
                'status' => 'error',
                'message' => 'Selected farm plot was not found.',
            ], 422);
        }

        $cap = (float) $plot->size_ha;
        if ($cap <= 0) {
            return null;
        }
        if ($area > $cap + 0.0001) {
            return response()->json([
                'status' => 'error',
                'message' => "{$label} cannot exceed the farm plot size ({$cap} ha).",
            ], 422);
        }

        return null;
    }

    protected function plotAreaExceedsCap(?string $farmPlotId, ?float $area): ?string
    {
        if ($farmPlotId === null || $farmPlotId === '' || $area === null) {
            return null;
        }

        $plot = FarmPlot::find($farmPlotId);
        if (! $plot) {
            return null;
        }

        $cap = (float) $plot->size_ha;
        if ($cap <= 0) {
            return null;
        }
        if ($area > $cap + 0.0001) {
            return "Area cannot exceed the farm plot size ({$cap} ha).";
        }

        return null;
    }
}
