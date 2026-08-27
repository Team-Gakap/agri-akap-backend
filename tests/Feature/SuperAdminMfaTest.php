<?php

namespace Tests\Feature;

use App\Models\MfaChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SuperAdminMfaTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_password_login_returns_mfa_challenge_without_token(): void
    {
        $user = User::factory()->superAdmin()->create([
            'email' => 'root@mao.com',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertOk();

        $this->assertTrue($response->json('data.mfa_required'));
        $this->assertTrue($response->json('data.mfa_setup_required'));
        $this->assertSame(['totp'], $response->json('data.mfa_methods'));
        $this->assertNotEmpty($response->json('data.mfa_challenge_id'));
        $this->assertNull($response->json('data.access_token'));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_auth_login_alias_matches_login(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertOk()
            ->assertJsonPath('data.mfa_required', true);
    }

    public function test_admin_login_still_returns_a_token(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin-mfa@mao.com',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.access_token'));
        $this->assertSame('admin', $response->json('data.user.role'));
        $this->assertNull($response->json('data.mfa_required'));
    }

    public function test_invalid_totp_increments_attempts_and_fifth_failure_invalidates_challenge(): void
    {
        [$user, $secret] = $this->enrolledSuperAdmin();
        $challengeId = $this->startMfa($user);

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/auth/mfa/verify', [
                'mfa_challenge_id' => $challengeId,
                'code' => '000000',
            ])->assertUnprocessable();

            $this->assertDatabaseHas('tbl_mfa_challenges', [
                'id' => $challengeId,
                'attempts' => $i,
            ]);
        }

        $this->postJson('/api/auth/mfa/verify', [
            'mfa_challenge_id' => $challengeId,
            'code' => '000000',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Too many attempts. Please sign in again.');

        $this->assertDatabaseMissing('tbl_mfa_challenges', ['id' => $challengeId]);

        $this->postJson('/api/auth/mfa/verify', [
            'mfa_challenge_id' => $challengeId,
            'code' => $this->totp($secret),
        ])->assertUnprocessable();
    }

    public function test_valid_totp_issues_a_token(): void
    {
        [$user, $secret] = $this->enrolledSuperAdmin();
        $challengeId = $this->startMfa($user);

        $response = $this->postJson('/api/auth/mfa/verify', [
            'mfa_challenge_id' => $challengeId,
            'code' => $this->totp($secret),
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.access_token'));
        $this->assertSame($user->id, $response->json('data.user.id'));
        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseMissing('tbl_mfa_challenges', ['id' => $challengeId]);
    }

    public function test_recovery_code_works_once_then_is_rejected(): void
    {
        $plain = 'ABCD-EFGH';
        [$user] = $this->enrolledSuperAdmin([
            'mfa_recovery_codes' => [Hash::make($plain)],
        ]);

        $first = $this->startMfa($user);
        $this->postJson('/api/auth/mfa/verify', [
            'mfa_challenge_id' => $first,
            'code' => $plain,
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $second = $this->startMfa($user);
        $this->postJson('/api/auth/mfa/verify', [
            'mfa_challenge_id' => $second,
            'code' => $plain,
        ])->assertUnprocessable();
    }

    public function test_sms_send_refused_when_totp_not_enrolled(): void
    {
        $user = User::factory()->superAdmin()->create([
            'mobile_number' => '09171234567',
        ]);
        $challengeId = $this->startMfa($user);

        $this->postJson('/api/auth/mfa/sms/send', [
            'mfa_challenge_id' => $challengeId,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Authenticator enrollment is required before SMS can be used.');
    }

    public function test_sms_send_refused_when_no_mobile(): void
    {
        [$user] = $this->enrolledSuperAdmin(['mobile_number' => null]);
        $challengeId = $this->startMfa($user);

        $this->postJson('/api/auth/mfa/sms/send', [
            'mfa_challenge_id' => $challengeId,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'No mobile number is on file for SMS backup.');
    }

    public function test_first_totp_setup_returns_recovery_codes_and_token(): void
    {
        $user = User::factory()->superAdmin()->create();
        $challengeId = $this->startMfa($user);

        $qr = $this->getJson('/api/auth/mfa/setup-qr?mfa_challenge_id='.$challengeId)->assertOk();
        $this->assertNotEmpty($qr->json('data.qr_data_uri'));
        $this->assertStringStartsWith('otpauth://totp/', $qr->json('data.otpauth_uri'));

        $secret = MfaChallenge::query()->findOrFail($challengeId)->pending_secret;
        $this->assertNotEmpty($secret);

        $response = $this->postJson('/api/auth/mfa/setup', [
            'mfa_challenge_id' => $challengeId,
            'code' => $this->totp($secret),
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.access_token'));
        $this->assertCount(8, $response->json('data.recovery_codes'));
        $this->assertNotNull($user->fresh()->mfa_confirmed_at);
    }

    public function test_reset_mfa_command_clears_enrollment(): void
    {
        [$user] = $this->enrolledSuperAdmin(['email' => 'reset-mfa@mao.com']);

        $this->artisan('agri:reset-mfa', ['email' => 'reset-mfa@mao.com'])
            ->assertSuccessful();

        $user->refresh();
        $this->assertNull($user->mfa_secret);
        $this->assertNull($user->mfa_confirmed_at);
        $this->assertNull($user->mfa_recovery_codes);
    }

    /** @return array{0: User, 1: string} */
    private function enrolledSuperAdmin(array $overrides = []): array
    {
        $secret = (new Google2FA())->generateSecretKey();

        $user = User::factory()->superAdmin()->create(array_merge([
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => now(),
        ], $overrides));

        return [$user, $secret];
    }

    private function startMfa(User $user): string
    {
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertOk();

        $this->assertTrue($response->json('data.mfa_required'));
        $this->assertNull($response->json('data.access_token'));

        return (string) $response->json('data.mfa_challenge_id');
    }

    private function totp(string $secret): string
    {
        return (new Google2FA())->getCurrentOtp($secret);
    }
}
