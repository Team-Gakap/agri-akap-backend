<?php

namespace App\Support;

use App\Models\User;

final class StaffAccess
{
    /** @var list<string> */
    public const OPERATIONAL_ROLES = [
        User::ROLE_TECHNICIAN,
        User::ROLE_BARANGAY_OFFICIAL,
    ];

    /** @var list<string> */
    public const SUPERADMIN_CREATABLE = [
        User::ROLE_ADMIN,
        User::ROLE_TECHNICIAN,
        User::ROLE_BARANGAY_OFFICIAL,
    ];

    /** @return list<string> */
    public static function listableRoles(User $actor): array
    {
        if ($actor->isSuperAdmin()) {
            return [
                User::ROLE_SUPER_ADMIN,
                User::ROLE_ADMIN,
                User::ROLE_TECHNICIAN,
                User::ROLE_BARANGAY_OFFICIAL,
            ];
        }

        return self::OPERATIONAL_ROLES;
    }

    /** @return list<string> */
    public static function creatableRoles(User $actor): array
    {
        if ($actor->isSuperAdmin()) {
            return self::SUPERADMIN_CREATABLE;
        }

        if ($actor->role === User::ROLE_ADMIN) {
            return self::OPERATIONAL_ROLES;
        }

        return [];
    }

    public static function canManage(User $actor, User $target): bool
    {
        if ($actor->isSuperAdmin()) {
            if ($target->isSuperAdmin() && $actor->id !== $target->id) {
                return false;
            }

            return true;
        }

        if ($actor->role === User::ROLE_ADMIN) {
            return in_array($target->role, self::OPERATIONAL_ROLES, true);
        }

        return false;
    }

    public static function canAssignRole(User $actor, string $role): bool
    {
        return in_array($role, self::creatableRoles($actor), true);
    }

    public static function isLastActiveSuperAdmin(User $user): bool
    {
        if (! $user->isSuperAdmin() || ! $user->is_active) {
            return false;
        }

        return User::query()
            ->where('role', User::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->count() <= 1;
    }
}
