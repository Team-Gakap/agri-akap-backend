<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Header matching for regional / masterlist workbooks.
 * Farmer address fields are kept for snapshot display only;
 * registry barangay always comes from farm address via RsbsaMasterlistColumns.
 */
class RegionalExtractionColumns
{
    /** @var string[] */
    public const REQUIRED = ['rsbsa_no', 'last_name', 'first_name', 'farm_area'];

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
            'suffix_and_extension' => ['suffix_and_extension', 'suffix', 'ext_name', 'extension_name'],
            // Residential (home) — stored as snapshot only, not registry address.
            'farmer_address_1' => ['farmer_address_1', 'farmer_address1'],
            'farmer_address_2' => ['farmer_address_2', 'farmer_address2'],
            // Farm location inside Echague — used as registry barangay.
            'farm_address_1' => ['farm_address_1', 'farm_address1', 'farm_barangay', 'barangay'],
            'farm_address_2' => ['farm_address_2', 'farm_address2', 'farm_municipality'],
            'farm_area' => ['farm_area', 'farm_area_ha', 'farm_size', 'hectares', 'area', 'ch'],
            'commodity' => ['commodity', 'crop', 'primary_commodity'],
            'remarks' => ['remarks', 'remark', 'farm_remarks', 'remarks_2', 'notes', 'status_remarks', 'beneficiary_remarks'],
            'contact_no' => ['contact_no', 'contact_number', 'mobile_number', 'phone', 'mobile'],
            'birthdate' => ['birthdate', 'birthday', 'date_of_birth', 'dob'],
            'sex' => ['sex', 'gender'],
        ];
    }

    public static function slug(mixed $header): string
    {
        return RsbsaMasterlistColumns::slug($header);
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array{matched: array<string, string>, missing: string[]}
     */
    public static function matchReport(array $headerRow): array
    {
        $present = [];
        foreach ($headerRow as $cell) {
            $slug = self::slug($cell);
            if ($slug !== '') {
                $present[$slug] = $slug;
            }
        }

        $matched = [];
        $missing = [];
        foreach (self::aliases() as $field => $aliases) {
            $hit = null;
            foreach ($aliases as $alias) {
                if (isset($present[$alias])) {
                    $hit = $alias;
                    break;
                }
            }
            if ($hit !== null) {
                $matched[$field] = $hit;
            } elseif (in_array($field, self::REQUIRED, true)) {
                $missing[] = $field;
            }
        }

        return [
            'matched' => $matched,
            'missing' => $missing,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function extractRow(array $row): ?array
    {
        // Prefer the shared farmer mapper so registry address = farm barangay.
        $farmer = RsbsaMasterlistColumns::extractFarmerRow($row);
        if ($farmer === null) {
            return null;
        }

        return [
            'rsbsa_no' => $farmer['rsbsa_no'],
            'last_name' => $farmer['surname'],
            'first_name' => $farmer['first_name'],
            'middle_name' => $farmer['middle_name'],
            'suffix_and_extension' => $farmer['ext_name'],
            'farmer_address_1' => $farmer['permanent_brgy'],
            'farmer_address_2' => $farmer['permanent_city'],
            'farm_address_1' => $farmer['permanent_brgy'],
            'farm_address_2' => $farmer['permanent_city'],
            'farm_area' => $farmer['total_farm_area_ha'],
            'commodity' => null,
            'remarks' => $farmer['remarks_raw'],
            'mobile_number' => $farmer['mobile_number'],
            'sex' => $farmer['sex'],
            'birthdate' => $farmer['birthdate'],
            'subsidy_exclusion_reason' => $farmer['subsidy_exclusion_reason'],
        ];
    }
}
