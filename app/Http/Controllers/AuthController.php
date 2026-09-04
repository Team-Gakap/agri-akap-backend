<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MfaService;
use App\Services\SystemAuditLogger;
use App\Services\TurnstileService;
use App\Support\PasswordRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private SystemAuditLogger $audit,
        private MfaService $mfa,
    ) {}

    public function login(Request $request, TurnstileService $turnstile)
    {
        $captchaRequired = $turnstile->requiredFor($request);

        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_name' => 'required|string|max:255',
            'turnstile_token' => $captchaRequired ? 'required|string' : 'nullable|string',
        ], [
            'turnstile_token.required' => 'Please complete the captcha.',
        ]);

        if ($captchaRequired && ! $turnstile->verify($request->input('turnstile_token'), $request->ip())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Captcha verification failed. Please try again.',
            ], 422);
        }

        $user = User::where('email', $request->email)->first();
        $passwordOk = $user && Hash::check($request->password, $user->password);

        if (! $passwordOk) {
            if ($user) {
                $this->registerFailedAttempt($user, $request);
            } else {
                $this->audit->record('auth.login.failed', null, null, [
                    'email' => $request->email,
                ], $request);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($user->isLocked()) {
            $this->audit->record('auth.login.failed', null, $user, [
                'reason' => 'locked',
            ], $request);

            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if (! $user->is_active) {
            $this->audit->record('auth.login.failed', null, $user, [
                'reason' => 'deactivated',
            ], $request);

            return response()->json([
                'status' => 'error',
                'message' => 'Account is deactivated. Please contact the MAO Administrator.',
            ], 403);
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        if ($this->mfa->requiredFor($user)) {
            $challenge = $this->mfa->createChallenge($user, $request->device_name);

            return response()->json([
                'status' => 'success',
                'message' => 'Additional verification required.',
                'data' => $this->mfa->challengePayload($user, $challenge),
            ]);
        }

        $token = $user->createToken($request->device_name)->plainTextToken;

        $this->audit->record('auth.login.success', $user, $user, [
            'device_name' => $request->device_name,
        ], $request);

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful.',
            'data' => [
                'access_token' => $token,
                'user' => $user->toAuthPayload(),
            ],
        ], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully.',
            'data' => null,
        ], 200);
    }

    public function me(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Profile retrieved.',
            'data' => [
                'user' => $request->user()->toAuthPayload(),
            ],
        ], 200);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => PasswordRules::required(differentFrom: 'current_password'),
        ], PasswordRules::messages());

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $user->forceFill([
            'password' => $request->input('password'),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        $this->audit->record('password.changed', $user, $user, [], $request);

        return response()->json([
            'status' => 'success',
            'message' => 'Password updated.',
            'data' => [
                'user' => $user->fresh()->toAuthPayload(),
            ],
        ]);
    }

    private function registerFailedAttempt(User $user, Request $request): void
    {
        $attempts = (int) $user->failed_login_attempts + 1;
        $lockUntil = $attempts >= User::MAX_LOGIN_ATTEMPTS
            ? now()->addMinutes(User::LOCKOUT_MINUTES)
            : $user->locked_until;

        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $lockUntil,
        ])->save();

        $this->audit->record('auth.login.failed', null, $user, [
            'attempts' => $attempts,
            'locked' => $user->isLocked(),
        ], $request);
    }
}
