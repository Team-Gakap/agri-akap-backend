<?php

namespace App\Support;

/**
 * Exclusion is stored on the farmer when the masterlist remarks match.
 * A later upload with a blank remark clears the exclusion for that RSBSA.
 */
class SubsidyExclusionRules
{
    /**
     * Canonical disqualifying keyword => human-readable label used in UIs and
     * stored in exclusion_reason on tbl_subsidy_beneficiaries.
     *
     * Keys are the normalised form (uppercase, no spaces) that the input is
     * tested against.  Values are the display-friendly label.
     */
    public const KEYWORDS = [
        'DECEASED'  => 'Deceased',
        'OFW'       => 'OFW',
        'NOFARM'    => 'No Farm',
        'INACTIVE'  => 'Inactive',
        'INACTIVE1' => 'Inactive',  // alternate spelling seen in DA sheets
    ];

    /**
     * Returns the human-readable label (e.g. "Deceased") if the remark is
     * disqualifying, or null if the remark is absent/acceptable.
     */
    public static function matchedLabel(?string $remark): ?string
    {
        if ($remark === null || trim($remark) === '') {
            return null;
        }

        // Normalise: upper-case, strip all whitespace.
        $normalised = strtoupper(preg_replace('/\s+/', '', trim($remark)) ?? '');

        // Direct key match (e.g. "NOFARM").
        if (array_key_exists($normalised, self::KEYWORDS)) {
            return self::KEYWORDS[$normalised];
        }

        // Substring match so "NO FARM (barangay confirmed)" still triggers.
        foreach (self::KEYWORDS as $keyword => $label) {
            if (str_contains($normalised, $keyword)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Convenience — returns the normalised keyword string (map key) or null.
     */
    public static function matchedKeyword(?string $remark): ?string
    {
        if ($remark === null || trim($remark) === '') {
            return null;
        }

        $normalised = strtoupper(preg_replace('/\s+/', '', trim($remark)) ?? '');

        foreach (array_keys(self::KEYWORDS) as $keyword) {
            if ($normalised === $keyword || str_contains($normalised, $keyword)) {
                return $keyword;
            }
        }

        return null;
    }
}
