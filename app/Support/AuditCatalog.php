<?php

namespace App\Support;

final class AuditCatalog
{
    public const TIMEZONE = 'Asia/Manila';

    /** @var list<string> */
    public const MODULES = [
        'auth', 'staff', 'rsbsa', 'plots', 'subsidy', 'calamity', 'sms', 'reports', 'export', 'sync',
    ];

    /** @var list<string> */
    public const VERBS = [
        'CREATE', 'UPDATE', 'DELETE', 'APPROVE', 'REJECT', 'EXPORT', 'LOGIN',
    ];

    public static function inferModule(string $action): string
    {
        if (str_starts_with($action, 'auth.') || str_starts_with($action, 'mfa.') || $action === 'password.changed') {
            return 'auth';
        }
        if (str_starts_with($action, 'user.') || str_starts_with($action, 'password.') || str_starts_with($action, 'session.')) {
            return 'staff';
        }
        if (str_starts_with($action, 'farmer.') || str_starts_with($action, 'rsbsa.')) {
            return 'rsbsa';
        }
        if (str_starts_with($action, 'farm_plot.') || str_starts_with($action, 'geo_tag')) {
            return 'plots';
        }
        if (
            str_starts_with($action, 'subsidy')
            || str_starts_with($action, 'program.')
            || str_starts_with($action, 'distribution.')
        ) {
            return 'subsidy';
        }
        if (str_starts_with($action, 'damage') || str_starts_with($action, 'calamity')) {
            return 'calamity';
        }
        if (
            str_starts_with($action, 'sms.')
            || str_starts_with($action, 'broadcast.')
            || str_contains($action, 'advisory')
        ) {
            return 'sms';
        }
        if (str_starts_with($action, 'export.') || str_contains($action, '.exported')) {
            return 'export';
        }
        if (str_starts_with($action, 'sync.')) {
            return 'sync';
        }

        return 'reports';
    }

    public static function inferVerb(string $action): string
    {
        if (str_contains($action, 'login')) {
            return 'LOGIN';
        }
        if (str_contains($action, 'export')) {
            return 'EXPORT';
        }
        if (str_contains($action, 'reject')) {
            return 'REJECT';
        }
        if (
            str_contains($action, 'approve')
            || str_contains($action, '.verified')
            || str_contains($action, 'verify.success')
        ) {
            return 'APPROVE';
        }
        if (
            str_contains($action, '.created')
            || str_contains($action, '.registered')
            || str_contains($action, '.sent')
            || str_contains($action, '.imported')
        ) {
            return 'CREATE';
        }
        if (
            str_contains($action, '.deleted')
            || str_contains($action, '.archived')
            || str_contains($action, '.deactivated')
            || str_contains($action, '.voided')
        ) {
            return 'DELETE';
        }

        return 'UPDATE';
    }
}
