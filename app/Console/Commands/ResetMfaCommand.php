<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MfaService;
use Illuminate\Console\Command;

class ResetMfaCommand extends Command
{
    protected $signature = 'agri:reset-mfa {email : SuperAdmin account email}';

    protected $description = 'Clear SuperAdmin TOTP enrollment so the account can enroll again after lockout';

    public function handle(MfaService $mfa): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error('No user found with that email.');

            return self::FAILURE;
        }

        if (! $user->isSuperAdmin()) {
            $this->error('MFA reset is only available for SuperAdmin accounts.');

            return self::FAILURE;
        }

        $mfa->resetEnrollment($user);
        $this->info("MFA enrollment cleared for {$user->email}. They must enroll an authenticator on next login.");

        return self::SUCCESS;
    }
}
