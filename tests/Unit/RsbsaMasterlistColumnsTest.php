<?php

namespace Tests\Unit;

use App\Support\RsbsaMasterlistColumns;
use App\Support\SubsidyExclusionRules;
use Tests\TestCase;

class RsbsaMasterlistColumnsTest extends TestCase
{
    public function test_excel_numeric_contact_gains_leading_zero(): void
    {
        $this->assertSame('09556482835', RsbsaMasterlistColumns::normalizeMobile(9556482835));
        $this->assertSame('09556482835', RsbsaMasterlistColumns::normalizeMobile(9556482835.0));
        $this->assertSame('09556482835', RsbsaMasterlistColumns::normalizeMobile('9556482835'));
    }

    public function test_country_code_and_local_numbers_are_normalized(): void
    {
        $this->assertSame('09556482835', RsbsaMasterlistColumns::normalizeMobile('639556482835'));
        $this->assertSame('09556482835', RsbsaMasterlistColumns::normalizeMobile('09556482835'));
        $this->assertSame(RsbsaMasterlistColumns::DEFAULT_MOBILE, RsbsaMasterlistColumns::normalizeMobile(null));
        $this->assertSame(RsbsaMasterlistColumns::DEFAULT_MOBILE, RsbsaMasterlistColumns::normalizeMobile('#N/A'));
    }

    public function test_extracts_commodity_and_either_remarks_column(): void
    {
        $deceased = RsbsaMasterlistColumns::extractFarmerRow($this->row([
            'BARANGAY REMARKS' => 'DECEASED',
            'REMARKS 2' => null,
        ]));
        $this->assertNotNull($deceased);
        $this->assertSame('Deceased', $deceased['subsidy_exclusion_reason']);
        $this->assertSame('Rice/Palay', $deceased['commodity']);
        $this->assertSame('09556482835', $deceased['mobile_number']);

        $sold = RsbsaMasterlistColumns::extractFarmerRow($this->row([
            'BARANGAY REMARKS' => '#N/A',
            'REMARKS 2' => 'NO FARM SOLD',
            'CONTACT NO' => '09171234567',
        ]));
        $this->assertSame('No Farm', $sold['subsidy_exclusion_reason']);
        $this->assertSame('09171234567', $sold['mobile_number']);

        $double = RsbsaMasterlistColumns::extractFarmerRow($this->row([
            'BARANGAY REMARKS' => 'DOUBLE ENTRY',
            'REMARKS 2' => null,
        ]));
        $this->assertSame('Double Entry', $double['subsidy_exclusion_reason']);

        $student = RsbsaMasterlistColumns::extractFarmerRow($this->row([
            'BARANGAY REMARKS' => 'STUDENT',
            'REMARKS 2' => 'NOT RESIDENCE',
        ]));
        $this->assertNull($student['subsidy_exclusion_reason']);
    }

    public function test_farm_geocode_pads_to_geojson_psgc(): void
    {
        $this->assertSame('PH0203112033', RsbsaMasterlistColumns::normalizePsgc(203112033));
        $this->assertSame('PH0203112033', RsbsaMasterlistColumns::normalizePsgc('0203112033'));
        $this->assertSame('PH0203112033', RsbsaMasterlistColumns::normalizePsgc('PH0203112033'));
        $this->assertNull(RsbsaMasterlistColumns::normalizePsgc(null));
        $this->assertNull(RsbsaMasterlistColumns::normalizePsgc(''));
        $this->assertNull(RsbsaMasterlistColumns::normalizePsgc('#N/A'));

        $row = RsbsaMasterlistColumns::extractFarmerRow($this->row([
            'FARM GEOCODE' => 203112033,
        ]));
        $this->assertSame('PH0203112033', $row['farm_psgc']);
    }

    public function test_blank_remarks_clear_exclusion_and_inactive_spacing_matches(): void
    {
        $row = RsbsaMasterlistColumns::extractFarmerRow($this->row([
            'BARANGAY REMARKS' => 'IN ACTIVE',
            'REMARKS 2' => null,
        ]));
        $this->assertSame('Inactive', $row['subsidy_exclusion_reason']);
        $this->assertSame('Inactive', SubsidyExclusionRules::matchedLabel('IN ACTIVE'));
        $this->assertNull(SubsidyExclusionRules::matchedLabel('STUDENT'));
        $this->assertNull(SubsidyExclusionRules::matchedLabel('NOT RESIDENCE'));
        $this->assertSame('No Farm', SubsidyExclusionRules::matchedLabel('NO FARM SOLD'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides): array
    {
        return array_merge([
            'SYSTEM_GENERATED_RSBSA_NO' => '02-31-12-033-000171',
            'LAST NAME' => 'ABA',
            'FIRST NAME' => 'ROBINSON',
            'MIDDLE NAME' => 'REYES',
            'SUFFIX AND EXTENSION' => null,
            'FARMER ADDRESS 1' => 'NILUMISU',
            'FARMER ADDRESS 2' => 'ECHAGUE',
            'FARM ADDRESS 1' => 'NILUMISU',
            'FARM ADDRESS 2' => 'ECHAGUE',
            'BIRTHDATE' => '1984-11-16',
            'SEX' => 'MALE',
            'CONTACT NO' => 9556482835,
            '4Ps' => 'NO',
            'Indegenous' => 'NO',
            'PWD' => 'NO',
            'FARM AREA' => 0.5,
            'COMMODITY' => 'Rice/Palay',
            'BARANGAY REMARKS' => '#N/A',
            'REMARKS 2' => null,
        ], $overrides);
    }
}
