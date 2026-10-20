<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Shared RSBSA Excel column map for Farmer Registry import and masterlist upload.
 *
 * Residence (Farmer Address) → permanent_brgy / permanent_city
 * Farm location (Farm Address / BARANGAY) → farm_brgy / farm_city
 */
class RsbsaMasterlistColumns
{
    public const FARM_CITY_DEFAULT = 'Echague';

    public const PROVINCE = 'Isabela';

    public const REGION = 'Region II';

    /** Placeholder when the sheet has no contact and the column is NOT NULL. */
    public const DEFAULT_MOBILE = '09000000000';

    /** Placeholder when birthdate is blank (column is NOT NULL). */
    public const DEFAULT_BIRTHDATE = '1900-01-01';

    /**
     * @return array<string, string[]>
     */
    public static function aliases(): array
    {
        return [
            'rsbsa_no' => [
                'farm_generated_rsbsa_no',
                'system_generated_rsbsa_no',
                'system_generated_r',
                'rsbsa_no',
                'rsbsa',
                'rsbsa_number',
            ],
            'last_name' => ['last_name', 'surname', 'lastname'],
            'first_name' => ['first_name', 'firstname', 'given_name'],
            'middle_name' => ['middle_name', 'middlename'],
            'suffix' => ['suffix', 'suffix_and_extension', 'ext_name', 'extension_name'],
            // Residential / home address
            'residence_barangay' => [
                'farmer_address_1',
                'farmer_address1',
                'permanent_brgy',
                'residence_barangay',
                'home_barangay',
            ],
            'residence_municipality' => [
                'farmer_address_2',
                'farmer_address2',
                'permanent_city',
                'residence_municipality',
                'home_municipality',
            ],
            // Farm location (Echague drop-off / distribution barangay)
            'farm_barangay' => [
                'farm_address_1',
                'farm_address1',
                'farm_barangay',
                'farm_brgy',
                'barangay',
            ],
            'farm_municipality' => [
                'farm_address_2',
                'farm_address2',
                'farm_municipality',
                'farm_city',
            ],
            'mobile_number' => [
                'contact_no',
                'contact_number',
                'mobile_number',
                'phone',
                'mobile',
            ],
            'farm_area' => [
                'farm_area',
                'farm_area_ha',
                'farm_size',
                'hectares',
                'area',
                'ch',
            ],
            'commodity' => ['commodity', 'crop', 'primary_commodity'],
            'birthdate' => [
                'birthdate',
                'birthday',
                'date_of_birth',
                'dob',
            ],
            'sex' => ['sex', 'gender'],
            'is_pwd' => ['pwd', 'is_pwd', 'person_with_disability'],
            'is_4ps' => ['4_ps', '4ps', 'is_4ps_beneficiary', 'four_ps'],
            'is_indigenous' => ['indigenous', 'indegenous', 'is_icc_ip', 'icc_ip'],
            'farm_geocode' => ['farm_geo_code', 'farm_geocode', 'farm_psgc'],
            'farmer_geocode' => ['farmer_geo_code', 'farmer_geocode', 'farmer_psgc'],
            // Both remark columns are populated independently on the DA sheet.
            'barangay_remarks' => ['barangay_remarks'],
            'remarks' => [
                'remarks',
                'remark',
                'farm_remarks',
                'remarks_2',
                'notes',
                'status_remarks',
            ],
        ];
    }

    public static function slug(mixed $header): string
    {
        $text = trim((string) $header);
        if ($text === '') {
            return '';
        }

        return Str::slug(preg_replace('/\s+/', ' ', $text) ?? $text, '_');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function extractFarmerRow(array $row): ?array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $slug = self::slug($key);
            if ($slug !== '' && ! array_key_exists($slug, $normalized)) {
                $normalized[$slug] = $value;
            }
        }

        $get = function (string $field) use ($normalized): ?string {
            foreach (self::aliases()[$field] ?? [] as $alias) {
                if (! array_key_exists($alias, $normalized) || $normalized[$alias] === null) {
                    continue;
                }
                $text = trim((string) $normalized[$alias]);
                if ($text === '' || strcasecmp($text, '#N/A') === 0 || strcasecmp($text, 'N/A') === 0) {
                    continue;
                }

                return $text;
            }

            return null;
        };

        $rsbsa = $get('rsbsa_no');
        $lastName = $get('last_name');
        $firstName = $get('first_name');
        $farmBrgy = $get('farm_barangay');
        $residenceBrgy = $get('residence_barangay');

        // Farm barangay is required for Echague distribution; residence falls back to farm when absent.
        if (! $rsbsa || ! $lastName || ! $firstName || ! $farmBrgy) {
            return null;
        }

        $farmAreaRaw = $get('farm_area');
        $farmArea = 0.0;
        if ($farmAreaRaw !== null && is_numeric($farmAreaRaw)) {
            $farmArea = max(0.0, round((float) $farmAreaRaw, 4));
        }

        $remarks = self::combineRemarks($get('barangay_remarks'), $get('remarks'));
        $exclusion = SubsidyExclusionRules::matchedLabel($remarks);

        $sexRaw = $get('sex');
        $sex = 'Male';
        if ($sexRaw !== null) {
            $sexNorm = strtoupper(preg_replace('/\s+/', '', $sexRaw) ?? '');
            if (str_starts_with($sexNorm, 'F')) {
                $sex = 'Female';
            }
        }

        $mobile = self::normalizeMobile($get('mobile_number'));

        // Store the masterlist spelling (Garit Norte, Cabugao Pob.), not the sheet's capitals.
        $farmBrgy = OfficialBarangays::canonicalize($farmBrgy);
        $residenceBrgy = $residenceBrgy !== null ? OfficialBarangays::canonicalize($residenceBrgy) : null;

        // Home address stays as written; do not force Echague onto residence.
        $permanentBrgy = $residenceBrgy ?: $farmBrgy;
        $permanentCity = $get('residence_municipality') ?: 'Unknown';
        $farmCity = $get('farm_municipality') ?: self::FARM_CITY_DEFAULT;

        return [
            'rsbsa_no' => $rsbsa,
            'surname' => $lastName,
            'first_name' => $firstName,
            'middle_name' => $get('middle_name'),
            'ext_name' => $get('suffix'),
            'sex' => $sex,
            'birthdate' => self::parseDate($get('birthdate')) ?? self::DEFAULT_BIRTHDATE,
            'permanent_brgy' => $permanentBrgy,
            'permanent_city' => $permanentCity,
            'permanent_province' => self::PROVINCE,
            'permanent_region' => self::REGION,
            'farm_brgy' => $farmBrgy,
            'farm_city' => $farmCity,
            'mobile_number' => $mobile,
            'total_farm_area_ha' => $farmArea,
            'commodity' => $get('commodity'),
            'is_pwd' => self::parseYesNo($get('is_pwd')),
            'is_4ps_beneficiary' => self::parseYesNo($get('is_4ps')),
            'is_icc_ip' => self::parseYesNo($get('is_indigenous')),
            'subsidy_exclusion_reason' => $exclusion,
            'remarks_raw' => $remarks,
            'farm_psgc' => self::normalizePsgc($get('farm_geocode')),
            'farmer_psgc' => self::normalizePsgc($get('farmer_geocode')),
        ];
    }

    /**
     * Excel stores CONTACT NO as a number, so the leading 0 is dropped (955… → 10 digits).
     * 63-prefixed numbers are converted to the local 09… form. An already-local 11-digit
     * number is left unchanged.
     */
    /**
     * Excel drops the leading 0 on a 10-digit PSGC (203112033).
     * Pad to 10 digits and prefix PH so it matches GeoJSON adm4_pcode.
     */
    public static function normalizePsgc(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $digits = sprintf('%.0f', $value);
        } else {
            $text = trim((string) $value);
            if ($text === '' || strcasecmp($text, '#N/A') === 0 || strcasecmp($text, 'N/A') === 0) {
                return null;
            }
            if (str_starts_with(strtoupper($text), 'PH')) {
                $text = substr($text, 2);
            }
            $digits = (is_numeric($text) && preg_match('/e/i', $text))
                ? sprintf('%.0f', (float) $text)
                : (preg_replace('/\D+/', '', $text) ?? '');
        }

        $digits = ltrim($digits, '0') === '' ? '' : $digits;
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        return 'PH'.str_pad($digits, 10, '0', STR_PAD_LEFT);
    }

    public static function normalizeMobile(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_MOBILE;
        }

        if (is_int($value) || is_float($value)) {
            $digits = sprintf('%.0f', $value);
        } else {
            $text = trim((string) $value);
            if ($text === '' || strcasecmp($text, '#N/A') === 0 || strcasecmp($text, 'N/A') === 0) {
                return self::DEFAULT_MOBILE;
            }
            $digits = (is_numeric($text) && preg_match('/e/i', $text))
                ? sprintf('%.0f', (float) $text)
                : (preg_replace('/\D+/', '', $text) ?? '');
        }

        if ($digits === '') {
            return self::DEFAULT_MOBILE;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            return '0'.substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '0'.$digits;
        }

        return $digits;
    }

    private static function combineRemarks(?string $barangayRemarks, ?string $otherRemarks): ?string
    {
        $parts = array_values(array_filter(
            [$barangayRemarks, $otherRemarks],
            fn (?string $part) => $part !== null && trim($part) !== ''
        ));

        if ($parts === []) {
            return null;
        }

        return implode(' ', $parts);
    }

    private static function parseYesNo(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        $n = strtoupper(preg_replace('/\s+/', '', $value) ?? '');

        return in_array($n, ['YES', 'Y', 'TRUE', '1'], true);
    }

    private static function parseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value))
                    ->format('Y-m-d');
            }

            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m)) {
                return Carbon::createFromFormat('d/m/Y', sprintf('%02d/%02d/%04d', (int) $m[1], (int) $m[2], (int) $m[3]))
                    ->format('Y-m-d');
            }

            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
