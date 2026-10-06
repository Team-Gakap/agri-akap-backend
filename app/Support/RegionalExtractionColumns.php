<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Canonical columns of a DA-RFO monthly extraction workbook.
 * Header matching accepts the raw labels and the slug form Laravel Excel
 * produces (WithHeadingRow), including the truncated RSBSA header alias.
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
            'rsbsa_no' => ['system_generated_rsbsa_no', 'system_generated_r', 'rsbsa_no', 'rsbsa', 'rsbsa_number'],
            'last_name' => ['last_name', 'surname', 'lastname'],
            'first_name' => ['first_name', 'firstname', 'given_name'],
            'middle_name' => ['middle_name', 'middlename'],
            'suffix_and_extension' => ['suffix_and_extension', 'suffix', 'ext_name', 'extension_name'],
            'farmer_address_1' => ['farmer_address_1', 'farmer_address1', 'barangay', 'permanent_brgy'],
            'farmer_address_2' => ['farmer_address_2', 'farmer_address2', 'municipality', 'city'],
            'farm_address_1' => ['farm_address_1', 'farm_address1', 'farm_barangay'],
            'farm_address_2' => ['farm_address_2', 'farm_address2', 'farm_municipality'],
            'farm_area' => ['farm_area', 'farm_area_ha', 'hectares', 'area'],
            'commodity' => ['commodity', 'crop', 'primary_commodity'],
            // Optional column — not in REQUIRED.  Presence triggers eligibility
            // checks via SubsidyExclusionRules before a beneficiary row is created.
            'remarks'   => ['remarks', 'remark', 'notes', 'status_remarks', 'beneficiary_remarks'],
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
                if ($text !== '') {
                    return $text;
                }
            }

            return null;
        };

        $rsbsa = $get('rsbsa_no');
        $lastName = $get('last_name');
        $firstName = $get('first_name');
        $farmAreaRaw = $get('farm_area');

        if (! $rsbsa || ! $lastName || ! $firstName || $farmAreaRaw === null || ! is_numeric($farmAreaRaw)) {
            return null;
        }

        $farmArea = round((float) $farmAreaRaw, 4);
        if ($farmArea <= 0) {
            return null;
        }

        return [
            'rsbsa_no' => $rsbsa,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => $get('middle_name'),
            'suffix_and_extension' => $get('suffix_and_extension'),
            'farmer_address_1' => $get('farmer_address_1'),
            'farmer_address_2' => $get('farmer_address_2'),
            'farm_address_1' => $get('farm_address_1'),
            'farm_address_2' => $get('farm_address_2'),
            'farm_area' => $farmArea,
            'commodity' => $get('commodity'),
            // Raw cell value; SubsidyExclusionRules::matchedLabel() decides
            // if it disqualifies the row.  Null when the column is absent.
            'remarks' => $get('remarks'),
        ];
    }
}
