<?php

namespace App\Support;

/**
 * Determines whether a masterlist row's REMARKS value disqualifies that
 * farmer from receiving a subsidy in this particular program/batch.
 *
 * Exclusion is per-batch only — a farmer marked DECEASED in one upload can
 * still appear as eligible in a different program's masterlist (user decision).
 *
 * To extend the list: add an entry to KEYWORDS.  Matching is case-insensitive
 * and whitespace-insensitive, so "IN ACTIVE", "INACTIVE", and "in active" all
 * resolve to the same canonical keyword "INACTIVE".
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
