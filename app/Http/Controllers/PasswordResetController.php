<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SystemAuditLogger;
use App\Services\TurnstileService;
use App\Support\PasswordRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    public const DISPATCH_MESSAGE = 'If an active account is associated with this email, a reset link has been dispatched.';

    public const INVALID_LINK_MESSAGE = 'This password reset link is invalid or has expired.';

    public function __construct(private SystemAuditLogger $audit) {}

    public function forgot(Request $request, TurnstileService $turnstile): JsonResponse
    {
        $captchaRequired = $turnstile->requiredFor($request);

        $request->validate([
            'email' => 'required|email',
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

        $user = User::query()->where('email', $request->input('email'))->first();

        if ($user && $user->is_active) {
            $status = Password::sendResetLink(['email' => $user->email]);

            if ($status === Password::RESET_LINK_SENT) {
                $this->audit->record('auth.password.reset.requested', $user, $user, [
                    'email' => $user->email,
                ], $request);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => self::DISPATCH_MESSAGE,
            'data' => null,
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => PasswordRules::required(),
        ], PasswordRules::messages());

        $user = User::query()->where('email', $request->input('email'))->first();
        if (! $user || ! $user->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => self::INVALID_LINK_MESSAGE,
            ], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($request): void {
                $user->forceFill([
                    'password' => $password,
                    'must_change_password' => false,
                    'password_changed_at' => now(),
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();

                $user->tokens()->delete();

                $this->audit->record('auth.password.reset.completed', $user, $user, [
                    'email' => $user->email,
                ], $request);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'status' => 'error',
                'message' => self::INVALID_LINK_MESSAGE,
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Password updated. Sign in with your new password.',
            'data' => null,
        ]);
    }
}
