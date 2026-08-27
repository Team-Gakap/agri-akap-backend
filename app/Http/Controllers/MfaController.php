<?php

namespace App\Http\Controllers;

use App\Exceptions\MfaException;
use App\Models\User;
use App\Services\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MfaController extends Controller
{
    public function __construct(private MfaService $mfa)
    {
    }

    public function setupQr(Request $request): JsonResponse
    {
        $request->validate([
            'mfa_challenge_id' => 'required|uuid',
        ]);

        return $this->ok('QR code ready.', $this->mfa->setupQr($request->input('mfa_challenge_id')));
    }

    public function setup(Request $request): JsonResponse
    {
        $request->validate([
            'mfa_challenge_id' => 'required|uuid',
            'code' => 'required|string',
        ]);

        $data = $this->mfa->confirmSetup(
            $request->input('mfa_challenge_id'),
            trim((string) $request->input('code')),
            $request,
        );

        return $this->ok('Authenticator enrolled.', $data);
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'mfa_challenge_id' => 'required|uuid',
            'code' => 'required_without_all:totp,recovery_code|nullable|string',
            'totp' => 'nullable|string',
            'recovery_code' => 'nullable|string',
        ]);

        $code = $this->resolveCode($request);

        $data = $this->mfa->verify($request->input('mfa_challenge_id'), $code, $request);

        return $this->ok('Login successful.', $data);
    }

    public function sendSms(Request $request): JsonResponse
    {
        $request->validate([
            'mfa_challenge_id' => 'required|uuid',
        ]);

        $this->mfa->sendSms($request->input('mfa_challenge_id'), $request);
        $challengeId = (string) $request->input('mfa_challenge_id');
        [, $user] = $this->mfa->requireChallenge($challengeId);

        return $this->ok('SMS code sent.', [
            'masked_mobile' => $this->mfa->maskMobile($user->mobile_number),
            'resend_after_seconds' => MfaService::SMS_RESEND_SECONDS,
        ]);
    }

    public function verifySms(Request $request): JsonResponse
    {
        $request->validate([
            'mfa_challenge_id' => 'required|uuid',
            'code' => 'required|string',
        ]);

        $data = $this->mfa->verifySms(
            $request->input('mfa_challenge_id'),
            trim((string) $request->input('code')),
            $request,
        );

        return $this->ok('Login successful.', $data);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $this->superAdmin($request);

        return $this->ok('MFA status retrieved.', $this->mfa->status($user));
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'code' => 'required_without:totp|nullable|string',
            'totp' => 'nullable|string',
        ]);

        $user = $this->superAdmin($request);
        $codes = $this->mfa->regenerateRecoveryCodes(
            $user,
            (string) $request->input('current_password'),
            trim((string) ($request->input('code') ?? $request->input('totp'))),
            $request,
        );

        return $this->ok('Recovery codes regenerated. Store them now; they will not be shown again.', [
            'recovery_codes' => $codes,
        ]);
    }

    public function updateMobile(Request $request): JsonResponse
    {
        $request->validate([
            'mobile_number' => 'required|string|max:20',
        ]);

        $user = $this->mfa->updateMobile(
            $this->superAdmin($request),
            (string) $request->input('mobile_number'),
        );

        return $this->ok('Mobile number updated.', $this->mfa->status($user));
    }

    private function superAdmin(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to perform this action.',
            ], 403));
        }

        return $user;
    }

    private function resolveCode(Request $request): string
    {
        $code = trim((string) (
            $request->input('code')
            ?? $request->input('totp')
            ?? $request->input('recovery_code')
            ?? ''
        ));

        if ($code === '') {
            throw new MfaException('Enter an authenticator or recovery code.');
        }

        return $code;
    }

    /** @param  array<string, mixed>  $data */
    private function ok(string $message, array $data): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ]);
    }
}
