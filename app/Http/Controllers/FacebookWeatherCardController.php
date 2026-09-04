<?php

namespace App\Http\Controllers;

use App\Services\FacebookGraphService;
use App\Services\FacebookRainfallCardService;
use App\Traits\LogsReportAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FacebookWeatherCardController extends Controller
{
    use LogsReportAudit;

    public function __construct(
        private FacebookRainfallCardService $cards,
        private FacebookGraphService $facebook,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $window = (string) $request->query('window', 'today');

        try {
            $data = $this->cards->preview($window);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unable to build rainfall graphic: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    public function png(Request $request): Response|JsonResponse
    {
        $window = (string) $request->query('window', 'today');

        try {
            $png = $this->cards->renderPng($window);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unable to render rainfall graphic: '.$e->getMessage(),
            ], 500);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function post(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'window' => ['required', 'in:today,tomorrow'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $this->cards->post(
                $validated['window'],
                $validated['caption'] ?? null,
                $request->user(),
                (bool) ($validated['force'] ?? false),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            $status = str_contains(mb_strtolower($e->getMessage()), 'not configured') ? 422 : 502;

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $status);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Facebook post failed: '.$e->getMessage(),
            ], 502);
        }

        $this->logReportAudit('weather.facebook.posted', null, [
            'forecast_date' => $result['post']['forecast_date'] ?? null,
            'window' => $result['post']['window'] ?? null,
            'facebook_post_id' => $result['post']['facebook_post_id'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Rainfall forecast posted to the Facebook Page.',
            'data' => $result,
        ]);
    }

    public function history(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->cards->recentPosts(),
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'configured' => $this->facebook->isConfigured(),
                'page_id_set' => filled(config('services.facebook.page_id')),
                'token_set' => filled(config('services.facebook.page_access_token')),
                'graph_version' => config('services.facebook.graph_version'),
            ],
        ]);
    }
}
