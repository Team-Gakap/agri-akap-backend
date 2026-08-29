<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MfaService;
use Illuminate\Console\Command;

class ResetMfaCommand extends Command
{
    protected $signature = 'agri:reset-mfa {email : Account email}';

    protected $description = 'Clear TOTP enrollment so a SuperAdmin or MFA-enforced admin can enroll again after lockout';

    public function handle(MfaService $mfa): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error('No user found with that email.');

            return self::FAILURE;
        }

        if (! $user->requiresMfa() && ! $user->mfaIsEnrolled()) {
            $this->error('MFA reset is only available for SuperAdmin or MFA-enforced admin accounts.');

            return self::FAILURE;
        }

        $mfa->resetEnrollment($user);
        $this->info("MFA enrollment cleared for {$user->email}. They must enroll an authenticator on next login.");

        return self::SUCCESS;
    }
}
