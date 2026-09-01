<?php

namespace App\Support;

final class RsbsaTenurialDocuments
{
    /** @var array<string, list<string>> */
    private const DOCUMENTS = [
        'registered_owner' => [
            'Certificate of Title / Regular Title (TCT or OCT)',
            'Tax Declaration (agricultural land)',
            'Free Patent / Homestead Patent / Agricultural Sales Patent',
            'Deed of Absolute Sale / Donation / Extrajudicial Settlement',
        ],
        'arb' => [
            'Certificate of Land Ownership Award (CLOA) — Individual',
            'Certificate of Land Ownership Award (CLOA) — Collective',
            'Certificate of Land Ownership Award (CLOA) — Co-ownership',
            'Emancipation Patent (EP)',
            'Certificate of Land Transfer (CLT)',
        ],
        'ip_cc' => [
            'Certificate of Ancestral Domain Title (CADT)',
            'Certificate of Ancestral Land Title (CALT)',
            'NCIP Certification (traditional land rights)',
        ],
        'tenant' => [
            'Notarized Agricultural Leasehold Contract / Tenancy Agreement',
            'Barangay Agrarian Reform Committee (BARC) Certification',
            'Barangay Certificate / Landowner Affidavit',
        ],
        'lessee' => [
            'Lease Contract / Contract of Lease',
            'Notarized Landowner Consent / Usufruct Agreement',
        ],
        'others' => [
            'Barangay Certification of Actual Tillage / Land Occupancy',
            'Affidavit of Heirship / Consent of Co-heirs',
            'Urban/Peri-Urban Agriculture Certification',
        ],
    ];

    /**
     * @param  array<string, mixed>  $plot
     */
    public static function resolveCategory(array $plot): string
    {
        if (! empty($plot['is_ancestral_domain'])) {
            return 'ip_cc';
        }
        if (! empty($plot['is_agrarian_reform_beneficiary'])) {
            return 'arb';
        }

        return match ($plot['ownership_type'] ?? '') {
            'Registered Owner' => 'registered_owner',
            'Tenant' => 'tenant',
            'Lessee' => 'lessee',
            default => 'others',
        };
    }

    /**
     * @param  array<string, mixed>  $plot
     * @return list<string>
     */
    public static function allowedForPlot(array $plot): array
    {
        $category = self::resolveCategory($plot);

        return self::DOCUMENTS[$category] ?? self::DOCUMENTS['others'];
    }

    /**
     * @param  array<string, mixed>  $plot
     */
    public static function isAllowed(array $plot, ?string $document): bool
    {
        if ($document === null || trim($document) === '') {
            return false;
        }

        return in_array(trim($document), self::allowedForPlot($plot), true);
    }
}
