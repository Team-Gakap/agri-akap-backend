<?php

namespace Tests\Feature;

use App\Imports\FarmersImport;
use App\Models\Farmer;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class RsbsaMasterlistImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_masterlist_rows_map_mobile_commodity_remarks_and_rffa(): void
    {
        $path = dirname(base_path()).DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR.'Book1 (1).xlsx';
        if (! is_file($path)) {
            $this->markTestSkipped('Masterlist workbook is not in the workspace.');
        }

        $wanted = [
            '02-31-12-033-000171',
            '02-31-12-033-000039',
            '02-31-12-047-000171',
            '02-31-12-023-000138',
            '02-031-12-048-578F29',
        ];

        $import = new FarmersImport;
        $import->collection(collect($this->rowsFromWorkbook($path, $wanted)));

        $normal = Farmer::query()->where('rsbsa_no', '02-31-12-033-000171')->first();
        $this->assertNotNull($normal);
        $this->assertSame('09556482835', $normal->mobile_number);
        $this->assertNull($normal->subsidy_exclusion_reason);
        $this->assertSame('Rice/Palay', $normal->farmPlots()->value('commodity'));
        $this->assertTrue($normal->fresh()->is_rffa_eligible);

        $this->assertSame(
            'Deceased',
            Farmer::query()->where('rsbsa_no', '02-31-12-033-000039')->value('subsidy_exclusion_reason')
        );
        $this->assertSame(
            'Double Entry',
            Farmer::query()->where('rsbsa_no', '02-31-12-047-000171')->value('subsidy_exclusion_reason')
        );
        $this->assertSame(
            'No Farm',
            Farmer::query()->where('rsbsa_no', '02-31-12-023-000138')->value('subsidy_exclusion_reason')
        );
        $this->assertNull(
            Farmer::query()->where('rsbsa_no', '02-031-12-048-578F29')->value('subsidy_exclusion_reason')
        );

        $cleared = new FarmersImport;
        $cleared->collection(collect([
            $this->sheetRow([
                'SYSTEM_GENERATED_RSBSA_NO' => '02-31-12-033-000039',
                'LAST NAME' => 'ORARA',
                'FIRST NAME' => 'DANILO',
                'FARMER ADDRESS 1' => 'NILUMISU',
                'FARM ADDRESS 1' => 'NILUMISU',
                'CONTACT NO' => 9355331820,
                'FARM AREA' => 0.5,
                'COMMODITY' => 'Rice/Palay',
                'SEX' => 'MALE',
                'BIRTHDATE' => '1960-01-01',
                'BARANGAY REMARKS' => '#N/A',
                'REMARKS 2' => null,
            ]),
        ]));

        $this->assertSame(1, $cleared->updated);
        $this->assertNull(
            Farmer::query()->where('rsbsa_no', '02-31-12-033-000039')->value('subsidy_exclusion_reason')
        );
        $this->assertSame(
            '09355331820',
            Farmer::query()->where('rsbsa_no', '02-31-12-033-000039')->value('mobile_number')
        );
    }

    public function test_reimport_replaces_unspecified_plot_commodity_only(): void
    {
        $farmer = Farmer::factory()->create([
            'rsbsa_no' => '02-31-12-033-000171',
        ]);
        $farmer->farmPlots()->create([
            'location_brgy' => 'NILUMISU',
            'location_city' => 'Echague',
            'location_province' => 'Isabela',
            'total_parcel_area_ha' => 0.5,
            'ownership_type' => 'Others',
            'proof_of_ownership_document' => 'Pending Manual Verification',
            'commodity' => 'Unspecified',
            'size_ha' => 0.5,
            'farm_type' => 'Other',
        ]);
        $kept = Farmer::factory()->create([
            'rsbsa_no' => '02-31-12-099-000001',
        ]);
        $kept->farmPlots()->create([
            'location_brgy' => 'NILUMISU',
            'location_city' => 'Echague',
            'location_province' => 'Isabela',
            'total_parcel_area_ha' => 1,
            'ownership_type' => 'Owner',
            'proof_of_ownership_document' => 'deed.pdf',
            'commodity' => 'Corn',
            'size_ha' => 1,
            'farm_type' => 'Irrigated',
        ]);

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
                'BARANGAY REMARKS' => '#N/A',
                'REMARKS 2' => null,
            ]),
            $this->sheetRow([
                'SYSTEM_GENERATED_RSBSA_NO' => '02-31-12-099-000001',
                'LAST NAME' => 'REYES',
                'FIRST NAME' => 'MARIA',
                'FARMER ADDRESS 1' => 'NILUMISU',
                'FARM ADDRESS 1' => 'NILUMISU',
                'CONTACT NO' => 9170000001,
                'FARM AREA' => 1,
                'COMMODITY' => 'Rice/Palay',
                'SEX' => 'FEMALE',
                'BIRTHDATE' => '1990-01-01',
                'BARANGAY REMARKS' => '#N/A',
                'REMARKS 2' => null,
            ]),
        ]));

        $this->assertSame('Rice/Palay', $farmer->farmPlots()->value('commodity'));
        $this->assertSame('Corn', $kept->farmPlots()->value('commodity'));
        $this->assertTrue($farmer->fresh()->is_rffa_eligible);
    }

    public function test_broadcast_targets_farm_barangay_and_chunks_large_sends(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        Farmer::factory()->create([
            'permanent_brgy' => 'San Fabian',
            'farm_brgy' => 'Angoluan',
            'mobile_number' => '09170000001',
        ]);
        Farmer::factory()->create([
            'permanent_brgy' => 'Angoluan',
            'farm_brgy' => null,
            'mobile_number' => '09170000002',
        ]);
        Farmer::factory()->create([
            'permanent_brgy' => 'Angoluan',
            'farm_brgy' => 'San Fabian',
            'mobile_number' => '09170000003',
        ]);

        $preview = $this->postJson('/api/broadcasts/preview', [
            'target_barangays' => ['Angoluan'],
        ]);
        $preview->assertOk();
        $this->assertSame(2, (int) $preview->json('data.recipient_count'));

        $numbers = [];
        for ($i = 0; $i < (SmsService::BULK_CHUNK_SIZE * 2) + 1; $i++) {
            $numbers[] = '09'.str_pad((string) $i, 9, '0', STR_PAD_LEFT);
        }

        $result = app(SmsService::class)->sendBulk($numbers, 'Seed release tomorrow.');
        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['raw']['chunks']);
        $this->assertSame(count($numbers), $result['recipients']);
        $this->assertSame(SmsService::BULK_CHUNK_SIZE, $result['raw']['chunks'][0]['recipients']);
        $this->assertSame(1, $result['raw']['chunks'][2]['recipients']);
    }

    public function test_registry_list_does_not_query_plots_per_farmer(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        Farmer::factory()->count(5)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/farmers?per_page=15')->assertOk();

        $plotQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'farm_plots'))
            ->count();

        $this->assertLessThanOrEqual(3, $plotQueries);
    }

    /**
     * @param  list<string>  $rsbsaNos
     * @return list<object>
     */
    private function rowsFromWorkbook(string $path, array $rsbsaNos): array
    {
        $sheet = IOFactory::load($path)->getSheetByName('Echague_Rice (1)');
        $this->assertNotNull($sheet);

        $header = null;
        $found = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = $cell->getValue();
            }
            if ($header === null) {
                $header = $cells;
                continue;
            }
            $assoc = [];
            foreach ($header as $index => $name) {
                if ($name === null || trim((string) $name) === '') {
                    continue;
                }
                $assoc[trim((string) $name)] = $cells[$index] ?? null;
            }
            $rsbsa = (string) ($assoc['SYSTEM_GENERATED_RSBSA_NO'] ?? '');
            if (in_array($rsbsa, $rsbsaNos, true)) {
                $found[] = $this->sheetRow($assoc);
            }
            if (count($found) === count($rsbsaNos)) {
                break;
            }
        }

        $this->assertCount(count($rsbsaNos), $found);

        return $found;
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
