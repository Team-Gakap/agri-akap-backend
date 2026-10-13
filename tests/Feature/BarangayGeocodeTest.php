<?php

namespace Tests\Feature;

use App\Imports\FarmersImport;
use App\Models\Farmer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BarangayGeocodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_reimport_sets_psgc_without_replacing_qr_or_clearing_a_blank_cell(): void
    {
        $import = new FarmersImport;
        $import->collection(collect([
            $this->sheetRow([
                'SYSTEM_GENERATED_RSBSA_NO' => '02-31-12-033-000171',
                'LAST NAME' => 'ABA',
                'FIRST NAME' => 'ROBINSON',
                'FARMER ADDRESS 1' => 'NILUMISU',
                'FARM ADDRESS 1' => 'NILUMISU',
                'CONTACT NO' => 9556482835,
                'FARM AREA' => 0.5,
                'COMMODITY' => 'Rice/Palay',
                'SEX' => 'MALE',
                'BIRTHDATE' => '1984-11-16',
                'FARM GEOCODE' => 203112033,
            ]),
        ]));

        $farmer = Farmer::query()->where('rsbsa_no', '02-31-12-033-000171')->first();
        $this->assertNotNull($farmer);
        $this->assertSame('PH0203112033', $farmer->farm_psgc);
        $id = $farmer->id;
        $qr = $farmer->qr_code_hash;

        $again = new FarmersImport;
        $again->collection(collect([
            $this->sheetRow([
                'SYSTEM_GENERATED_RSBSA_NO' => '02-31-12-033-000171',
                'LAST NAME' => 'ABA',
                'FIRST NAME' => 'ROBINSON',
                'FARMER ADDRESS 1' => 'NILUMISU',
                'FARM ADDRESS 1' => 'NILUMISU',
                'CONTACT NO' => 9556482835,
                'FARM AREA' => 0.75,
                'SEX' => 'MALE',
                'BIRTHDATE' => '1984-11-16',
            ]),
        ]));

        $farmer->refresh();
        $this->assertSame($id, $farmer->id);
        $this->assertSame($qr, $farmer->qr_code_hash);
        $this->assertSame('PH0203112033', $farmer->farm_psgc);
        $this->assertSame('0.7500', (string) $farmer->total_farm_area_ha);
    }

    public function test_barangay_stats_group_farmers_that_share_a_geocode(): void
    {
        Farmer::factory()->create([
            'farm_brgy' => 'Nilumisu',
            'farm_psgc' => 'PH0203112033',
        ]);
        Farmer::factory()->create([
            'farm_brgy' => 'Nilumisu',
            'farm_psgc' => 'PH0203112033',
        ]);
        Farmer::factory()->create([
            'farm_brgy' => 'Angoluan',
            'farm_psgc' => 'PH0203112001',
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/dashboard/barangay-stats?municipality=Echague');
        $response->assertOk();

        $nilumisu = collect($response->json('data'))
            ->first(fn (array $row) => $row['psgc'] === 'PH0203112033');
        $this->assertNotNull($nilumisu);
        $this->assertSame(2, (int) $nilumisu['farmer_count']);
        $this->assertSame('Nilumisu', $nilumisu['barangay']);

        $geoPath = dirname(base_path()).DIRECTORY_SEPARATOR.'agri-akap-frontend'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'geo'.DIRECTORY_SEPARATOR.'echague-barangays.geojson';
        $geo = json_decode((string) file_get_contents($geoPath), true);
        $codes = array_map(
            fn (array $feature) => $feature['properties']['adm4_pcode'] ?? null,
            $geo['features'] ?? []
        );
        $this->assertContains('PH0203112033', $codes);
    }

    private function sheetRow(array $data): object
    {
        return new class($data)
        {
            public function __construct(private array $data)
            {
            }

            public function toArray(): array
            {
                return $this->data;
            }
        };
    }
}
