<?php

namespace Tests\Feature;

use App\Http\Controllers\PasswordResetController;
use App\Models\SystemAuditLog;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_forgot_password_does_not_enumerate_unknown_or_inactive_emails(): void
    {
        Notification::fake();
        User::factory()->technician()->create([
            'email' => 'off@mao.com',
            'is_active' => false,
        ]);

        foreach (['missing@mao.com', 'off@mao.com'] as $email) {
            $this->postJson('/api/auth/forgot-password', ['email' => $email])
                ->assertOk()
                ->assertJsonPath('message', PasswordResetController::DISPATCH_MESSAGE);
        }

        Notification::assertNothingSent();
        $this->assertSame(0, SystemAuditLog::query()->where('action', 'auth.password.reset.requested')->count());
    }

    public function test_forgot_password_notifies_active_admin_barangay_and_technician(): void
    {
        Notification::fake();

        $users = [
            User::factory()->admin()->create(['email' => 'admin@mao.com']),
            User::factory()->barangayOfficial()->create(['email' => 'brgy@mao.com']),
            User::factory()->technician()->create(['email' => 'tech@mao.com']),
        ];

        foreach ($users as $user) {
            $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
                ->assertOk()
                ->assertJsonPath('message', PasswordResetController::DISPATCH_MESSAGE);

            Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user) {
                $mail = $notification->toMail($user);

                return str_contains((string) $mail->actionUrl, 'http://localhost:5173/reset-password')
                    && str_contains((string) $mail->actionUrl, 'token=')
                    && str_contains((string) $mail->actionUrl, 'email=');
            });
        }
    }

    public function test_reset_rejects_weak_and_municipal_temporary_passwords(): void
    {
        $user = User::factory()->technician()->create(['email' => 'tech@mao.com']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertStatus(422);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => User::TEMPORARY_PASSWORD,
            'password_confirmation' => User::TEMPORARY_PASSWORD,
        ])->assertStatus(422);
    }

    public function test_reset_updates_password_revokes_tokens_and_writes_audit(): void
    {
        $user = User::factory()->technician()->create([
            'email' => 'tech@mao.com',
            'must_change_password' => true,
            'failed_login_attempts' => 4,
        ]);
        $user->createToken('phpunit');
        $this->assertSame(1, $user->tokens()->count());

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewStrong1!',
            'password_confirmation' => 'NewStrong1!',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('NewStrong1!', $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertSame(0, $fresh->failed_login_attempts);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertSame(1, SystemAuditLog::query()->where('action', 'auth.password.reset.completed')->count());
    }

    public function test_reset_rejects_invalid_token(): void
    {
        $user = User::factory()->technician()->create(['email' => 'tech@mao.com']);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => 'not-a-real-token',
            'password' => 'NewStrong1!',
            'password_confirmation' => 'NewStrong1!',
        ])->assertStatus(422)
            ->assertJsonPath('message', PasswordResetController::INVALID_LINK_MESSAGE);
    }

    public function test_change_password_rejects_weak_and_municipal_temporary_passwords(): void
    {
        $user = User::factory()->technician()->create([
            'password' => 'CurrentPass1!',
            'must_change_password' => true,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/change-password', [
            'current_password' => 'CurrentPass1!',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);

        $this->postJson('/api/auth/change-password', [
            'current_password' => 'CurrentPass1!',
            'password' => User::TEMPORARY_PASSWORD,
            'password_confirmation' => User::TEMPORARY_PASSWORD,
        ])->assertStatus(422);

        $this->postJson('/api/auth/change-password', [
            'current_password' => 'CurrentPass1!',
            'password' => 'NewStrong1!',
            'password_confirmation' => 'NewStrong1!',
        ])->assertOk();

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('NewStrong1!', $user->fresh()->password));
    }
}
