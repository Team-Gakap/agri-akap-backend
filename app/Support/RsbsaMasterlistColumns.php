<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Shared RSBSA Excel column map for Farmer Registry import and masterlist upload.
 *
 * Address rule: the farmer's registry barangay is the FARM location
 * (always within Echague), never the residential "Farmer Address".
 */
class RsbsaMasterlistColumns
{
    public const CITY = 'Echague';

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
            // Farm barangay only — never farmer_address_1 / residential address.
            'farm_barangay' => [
                'barangay',
                'farm_address_1',
                'farm_address1',
                'farm_barangay',
                'farm_brgy',
            ],
            'farm_municipality' => [
                'farm_address_2',
                'farm_address2',
                'farm_municipality',
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
            'birthdate' => [
                'birthdate',
                'birthday',
                'date_of_birth',
                'dob',
            ],
            'sex' => ['sex', 'gender'],
            'is_pwd' => ['pwd', 'is_pwd', 'person_with_disability'],
            'is_4ps' => ['4_ps', '4ps', 'is_4ps_beneficiary', 'four_ps'],
            'is_indigenous' => ['indigenous', 'is_icc_ip', 'icc_ip'],
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
                // Treat Excel #N/A as empty.
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

        if (! $rsbsa || ! $lastName || ! $firstName || ! $farmBrgy) {
            return null;
        }

        $farmAreaRaw = $get('farm_area');
        $farmArea = 0.0;
        if ($farmAreaRaw !== null && is_numeric($farmAreaRaw)) {
            $farmArea = max(0.0, round((float) $farmAreaRaw, 4));
        }

        $remarks = $get('remarks');
        $exclusion = SubsidyExclusionRules::matchedLabel($remarks);

        $sexRaw = $get('sex');
        $sex = 'Male';
        if ($sexRaw !== null) {
            $sexNorm = strtoupper(preg_replace('/\s+/', '', $sexRaw) ?? '');
            if (str_starts_with($sexNorm, 'F')) {
                $sex = 'Female';
            }
        }

        $mobile = $get('mobile_number');
        if ($mobile === null || $mobile === '') {
            $mobile = self::DEFAULT_MOBILE;
        } else {
            $digits = preg_replace('/\D+/', '', $mobile) ?? '';
            $mobile = $digits !== '' ? $digits : self::DEFAULT_MOBILE;
        }

        return [
            'rsbsa_no' => $rsbsa,
            'surname' => $lastName,
            'first_name' => $firstName,
            'middle_name' => $get('middle_name'),
            'ext_name' => $get('suffix'),
            'sex' => $sex,
            'birthdate' => self::parseDate($get('birthdate')) ?? self::DEFAULT_BIRTHDATE,
            'permanent_brgy' => $farmBrgy,
            'permanent_city' => self::CITY,
            'permanent_province' => self::PROVINCE,
            'permanent_region' => self::REGION,
            'mobile_number' => $mobile,
            'total_farm_area_ha' => $farmArea,
            'is_pwd' => self::parseYesNo($get('is_pwd')),
            'is_4ps_beneficiary' => self::parseYesNo($get('is_4ps')),
            'is_icc_ip' => self::parseYesNo($get('is_indigenous')),
            'subsidy_exclusion_reason' => $exclusion,
            'remarks_raw' => $remarks,
        ];
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

            // DD/MM/YYYY common in DA sheets
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
