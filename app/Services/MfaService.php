<?php

namespace App\Services;

use App\Exceptions\MfaException;
use App\Models\MfaChallenge;
use App\Models\User;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class MfaService
{
    public const CHALLENGE_MINUTES = 5;
    public const MAX_ATTEMPTS = 5;
    public const SMS_RESEND_SECONDS = 60;
    public const SMS_TTL_MINUTES = 5;
    public const RECOVERY_CODE_COUNT = 8;
    public const ISSUER = 'AGRI-AKAP';

    public function __construct(
        private SystemAuditLogger $audit,
        private SmsService $sms,
        private Google2FA $google2fa,
    ) {
        $this->google2fa->setWindow(1);
    }

    public function isEnrolled(User $user): bool
    {
        return $user->mfa_confirmed_at !== null && filled($user->mfa_secret);
    }

    /** @return list<string> */
    public function availableMethods(User $user): array
    {
        $methods = ['totp'];

        if ($this->isEnrolled($user) && filled($user->mobile_number) && $this->sms->isConfigured()) {
            $methods[] = 'sms';
        }

        return $methods;
    }

    public function maskMobile(?string $mobile): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $mobile) ?: '';

        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            $digits = '0'.substr($digits, 2);
        }

        if (strlen($digits) < 7) {
            return null;
        }

        return substr($digits, 0, 2).'•••'.substr($digits, -4);
    }

    public function createChallenge(User $user, string $deviceName): MfaChallenge
    {
        MfaChallenge::query()->where('user_id', $user->id)->delete();

        return MfaChallenge::query()->create([
            'user_id' => $user->id,
            'device_name' => $deviceName,
            'pending_secret' => $this->isEnrolled($user) ? null : $this->google2fa->generateSecretKey(),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::CHALLENGE_MINUTES),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    public function challengePayload(User $user, MfaChallenge $challenge): array
    {
        $setupRequired = ! $this->isEnrolled($user);

        return [
            'mfa_required' => true,
            'mfa_challenge_id' => $challenge->id,
            'mfa_setup_required' => $setupRequired,
            'mfa_methods' => $setupRequired ? ['totp'] : $this->availableMethods($user),
            'masked_mobile' => $setupRequired ? null : $this->maskMobile($user->mobile_number),
        ];
    }

    /** @return array{0: MfaChallenge, 1: User} */
    public function requireChallenge(string $id): array
    {
        $challenge = MfaChallenge::query()->find($id);

        if (! $challenge || $challenge->expires_at === null || $challenge->expires_at->isPast()) {
            $challenge?->delete();
            throw new MfaException('MFA challenge expired. Please sign in again.');
        }

        $user = User::query()->find($challenge->user_id);

        if (! $user || ! $user->isSuperAdmin() || ! $user->is_active || $user->isLocked()) {
            $challenge->delete();
            throw new MfaException('MFA challenge expired. Please sign in again.');
        }

        return [$challenge, $user];
    }

    /** @return array<string, mixed> */
    public function setupQr(string $challengeId): array
    {
        [$challenge, $user] = $this->requireChallenge($challengeId);

        if ($this->isEnrolled($user)) {
            throw new MfaException('Authenticator is already enrolled. Use the verification step instead.');
        }

        if (! filled($challenge->pending_secret)) {
            $challenge->forceFill([
                'pending_secret' => $this->google2fa->generateSecretKey(),
            ])->save();
            $challenge->refresh();
        }

        $otpauth = $this->google2fa->getQRCodeUrl(self::ISSUER, $user->email, $challenge->pending_secret);
        $png = (new Writer(new GDLibRenderer(240)))->writeString($otpauth);

        return [
            'otpauth_uri' => $otpauth,
            'qr_data_uri' => 'data:image/png;base64,'.base64_encode($png),
        ];
    }

    /** @return array<string, mixed> */
    public function confirmSetup(string $challengeId, string $code, Request $request): array
    {
        [$challenge, $user] = $this->requireChallenge($challengeId);

        if ($this->isEnrolled($user)) {
            throw new MfaException('Authenticator is already enrolled. Use the verification step instead.');
        }

        if (! $this->totpValid((string) $challenge->pending_secret, $code)) {
            $this->failAttempt($challenge, $user, $request);
        }

        $recovery = $this->generateRecoveryCodes();

        $user->forceFill([
            'mfa_secret' => $challenge->pending_secret,
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => $this->hashRecoveryCodes($recovery),
        ])->save();

        $this->audit->record('mfa.setup', $user, $user, [], $request);

        return $this->issueToken($user, $challenge, $request, [
            'recovery_codes' => $recovery,
        ]);
    }

    /** @return array<string, mixed> */
    public function verify(string $challengeId, string $code, Request $request): array
    {
        [$challenge, $user] = $this->requireChallenge($challengeId);

        if (! $this->isEnrolled($user)) {
            throw new MfaException('Authenticator enrollment is required. Scan the QR code first.');
        }

        $normalized = strtoupper(trim($code));

        if ($this->looksLikeTotp($normalized)) {
            if ($this->totpValid((string) $user->mfa_secret, $normalized)) {
                $this->audit->record('mfa.verify.success', $user, $user, ['method' => 'totp'], $request);

                return $this->issueToken($user, $challenge, $request);
            }
        }

        if ($this->consumeRecoveryCode($user, $normalized)) {
            $this->audit->record('mfa.verify.success', $user, $user, ['method' => 'recovery'], $request);

            return $this->issueToken($user, $challenge, $request);
        }

        $this->failAttempt($challenge, $user, $request);
    }

    public function sendSms(string $challengeId, Request $request): void
    {
        [$challenge, $user] = $this->requireChallenge($challengeId);

        if (! $this->isEnrolled($user)) {
            throw new MfaException('Authenticator enrollment is required before SMS can be used.');
        }

        if (! filled($user->mobile_number)) {
            throw new MfaException('No mobile number is on file for SMS backup.');
        }

        if (! $this->sms->isConfigured()) {
            throw new MfaException('SMS is not available.');
        }

        if ($challenge->sms_sent_at && $challenge->sms_sent_at->gt(now()->subSeconds(self::SMS_RESEND_SECONDS))) {
            throw new MfaException('Please wait before requesting another SMS code.');
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $result = $this->sms->send(
            $user->mobile_number,
            "AGRI-AKAP code: {$otp}. Valid for ".self::SMS_TTL_MINUTES.' minutes. Do not share this code.',
        );

        if (! ($result['success'] ?? false)) {
            throw new MfaException('Could not send the SMS code. Try authenticator or a recovery code.');
        }

        $challenge->forceFill([
            'sms_code_hash' => Hash::make($otp),
            'sms_sent_at' => now(),
        ])->save();

        $this->audit->record('mfa.sms.sent', $user, $user, [
            'masked_mobile' => $this->maskMobile($user->mobile_number),
        ], $request);
    }

    /** @return array<string, mixed> */
    public function verifySms(string $challengeId, string $code, Request $request): array
    {
        [$challenge, $user] = $this->requireChallenge($challengeId);

        if (! $this->isEnrolled($user)) {
            throw new MfaException('Authenticator enrollment is required before SMS can be used.');
        }

        if (
            ! filled($challenge->sms_code_hash)
            || ! $challenge->sms_sent_at
            || $challenge->sms_sent_at->lt(now()->subMinutes(self::SMS_TTL_MINUTES))
        ) {
            $this->failAttempt($challenge, $user, $request);
        }

        if (! Hash::check($code, $challenge->sms_code_hash)) {
            $this->failAttempt($challenge, $user, $request);
        }

        $this->audit->record('mfa.verify.success', $user, $user, ['method' => 'sms'], $request);

        return $this->issueToken($user, $challenge, $request);
    }

    /** @return array<string, mixed> */
    public function status(User $user): array
    {
        $enrolled = $this->isEnrolled($user);

        return [
            'enrolled' => $enrolled,
            'confirmed_at' => $user->mfa_confirmed_at?->toIso8601String(),
            'masked_mobile' => $this->maskMobile($user->mobile_number),
            'has_mobile' => filled($user->mobile_number),
            'sms_available' => $enrolled && filled($user->mobile_number) && $this->sms->isConfigured(),
            'recovery_codes_remaining' => is_array($user->mfa_recovery_codes)
                ? count($user->mfa_recovery_codes)
                : 0,
        ];
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user, string $password, string $totp, Request $request): array
    {
        if (! Hash::check($password, $user->password)) {
            throw new MfaException('Current password is incorrect.');
        }

        if (! $this->isEnrolled($user) || ! $this->totpValid((string) $user->mfa_secret, $totp)) {
            throw new MfaException('Invalid authenticator code.');
        }

        $recovery = $this->generateRecoveryCodes();
        $user->forceFill([
            'mfa_recovery_codes' => $this->hashRecoveryCodes($recovery),
        ])->save();

        $this->audit->record('mfa.recovery.regenerated', $user, $user, [], $request);

        return $recovery;
    }

    public function updateMobile(User $user, string $mobile): User
    {
        $normalized = $this->normalizePhMobile($mobile);

        $user->forceFill([
            'mobile_number' => $normalized,
        ])->save();

        return $user->fresh();
    }

    public function resetEnrollment(User $user): void
    {
        $user->forceFill([
            'mfa_secret' => null,
            'mfa_confirmed_at' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        MfaChallenge::query()->where('user_id', $user->id)->delete();
    }

    public function normalizePhMobile(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?: '';

        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            $digits = '0'.substr($digits, 2);
        }

        if (! preg_match('/^09\d{9}$/', $digits)) {
            throw new MfaException('Enter a valid Philippine mobile number (09XXXXXXXXX).');
        }

        return $digits;
    }

    /** @return array<string, mixed> */
    private function issueToken(User $user, MfaChallenge $challenge, Request $request, array $extra = []): array
    {
        $deviceName = $challenge->device_name;
        $token = $user->createToken($deviceName)->plainTextToken;
        $challenge->delete();

        $this->audit->record('auth.login.success', $user, $user, [
            'device_name' => $deviceName,
            'mfa' => true,
        ], $request);

        return array_merge([
            'access_token' => $token,
            'user' => $user->fresh()->toAuthPayload(),
        ], $extra);
    }

    private function totpValid(?string $secret, string $code): bool
    {
        if (! filled($secret) || ! $this->looksLikeTotp($code)) {
            return false;
        }

        try {
            return $this->google2fa->verifyKey($secret, $code, 1) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function looksLikeTotp(string $code): bool
    {
        return (bool) preg_match('/^\d{6}$/', $code);
    }

    private function failAttempt(MfaChallenge $challenge, User $user, Request $request): never
    {
        $attempts = (int) $challenge->attempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $challenge->delete();
            $this->audit->record('mfa.verify.failed', $user, $user, [
                'reason' => 'max_attempts',
            ], $request);
            throw new MfaException('Too many attempts. Please sign in again.');
        }

        $challenge->forceFill(['attempts' => $attempts])->save();
        $this->audit->record('mfa.verify.failed', $user, $user, [
            'attempts' => $attempts,
        ], $request);

        throw new MfaException('Invalid MFA code.');
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = strtoupper(Str::random(4).'-'.Str::random(4));
        }

        return $codes;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn (string $code) => Hash::make(strtoupper($code)), $codes);
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->mfa_recovery_codes;
        if (! is_array($hashes) || $hashes === []) {
            return false;
        }

        $match = null;
        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && Hash::check($code, $hash)) {
                $match = $index;
            }
        }

        if ($match === null) {
            return false;
        }

        unset($hashes[$match]);
        $user->forceFill([
            'mfa_recovery_codes' => array_values($hashes),
        ])->save();

        return true;
    }
}
