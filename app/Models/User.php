<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'role', 'assigned_barangay', 'is_active',
    'failed_login_attempts', 'locked_until', 'must_change_password', 'password_changed_at',
    'mobile_number', 'enforce_mfa',
])]
#[Hidden(['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'])]
class User extends Authenticatable
{
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_TECHNICIAN = 'technician';

    public const ROLE_BARANGAY_OFFICIAL = 'barangay_official';

    public const MAX_LOGIN_ATTEMPTS = 5;

    public const LOCKOUT_MINUTES = 15;

    public const TEMPORARY_PASSWORD = 'Echague2026!';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuid, Notifiable, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'mfa_secret' => 'encrypted',
            'mfa_confirmed_at' => 'datetime',
            'mfa_recovery_codes' => 'array',
            'enforce_mfa' => 'boolean',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isMunicipalAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function requiresMfa(): bool
    {
        return $this->isSuperAdmin()
            || ($this->role === self::ROLE_ADMIN && (bool) $this->enforce_mfa);
    }

    public function mfaIsEnrolled(): bool
    {
        return $this->mfa_confirmed_at !== null && filled($this->mfa_secret);
    }

    /** @return array<string, mixed> */
    public function toAuthPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'assigned_barangay' => $this->assigned_barangay,
            'must_change_password' => (bool) $this->must_change_password,
            'is_active' => (bool) $this->is_active,
            'requires_mfa' => $this->requiresMfa(),
        ];
    }

    /** @return array<string, mixed> */
    public function toStaffPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'assigned_barangay' => $this->assigned_barangay,
            'is_active' => (bool) $this->is_active,
            'must_change_password' => (bool) $this->must_change_password,
            'failed_login_attempts' => (int) $this->failed_login_attempts,
            'locked_until' => $this->locked_until?->toIso8601String(),
            'is_locked' => $this->isLocked(),
            'password_changed_at' => $this->password_changed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'tokens_count' => (int) ($this->tokens_count ?? $this->tokens()->count()),
            'enforce_mfa' => (bool) $this->enforce_mfa,
            'mfa_enrolled' => $this->mfaIsEnrolled(),
        ];
    }

    public function processedDistributions(): HasMany
    {
        return $this->hasMany(Distribution::class, 'distributed_by');
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
