<?php

namespace App\Imports;

use App\Support\RsbsaMasterlistColumns;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Bulk upsert of the official RSBSA masterlist (~7k+ rows).
 *
 * Chunked reads + upsert by rsbsa_no so re-uploads correct farm barangay,
 * contact, area, and exclusion without locking farmers for the whole file.
 */
class FarmersImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public int $excluded = 0;

    public function chunkSize(): int
    {
        return 400;
    }

    public function collection(Collection $rows): void
    {
        $payload = [];
        $seenInChunk = [];

        foreach ($rows as $row) {
            $data = RsbsaMasterlistColumns::extractFarmerRow($row->toArray());
            if ($data === null) {
                $this->skipped++;
                continue;
            }

            $rsbsa = $data['rsbsa_no'];
            if (isset($seenInChunk[$rsbsa])) {
                $this->skipped++;
                continue;
            }
            $seenInChunk[$rsbsa] = true;

            if (! empty($data['subsidy_exclusion_reason'])) {
                $this->excluded++;
            }

            $payload[] = $data;
        }

        if ($payload === []) {
            return;
        }

        $rsbsaList = array_column($payload, 'rsbsa_no');
        $existingKeys = DB::table('farmers')
            ->whereIn('rsbsa_no', $rsbsaList)
            ->pluck('rsbsa_no')
            ->flip();

        $now = now()->toDateTimeString();
        $upsertRows = [];

        foreach ($payload as $data) {
            $isUpdate = $existingKeys->has($data['rsbsa_no']);
            if ($isUpdate) {
                $this->updated++;
            } else {
                $this->created++;
            }

            $upsertRows[] = [
                'id' => (string) Str::uuid(),
                'rsbsa_no' => $data['rsbsa_no'],
                'transaction_code' => 'IMP-'.Str::upper(Str::random(10)),
                'qr_code_hash' => (string) Str::uuid(),
                'surname' => $data['surname'],
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'],
                'ext_name' => $data['ext_name'],
                'no_middle_name' => empty($data['middle_name']) ? 1 : 0,
                'no_ext_name' => empty($data['ext_name']) ? 1 : 0,
                'sex' => $data['sex'],
                'birthdate' => $data['birthdate'],
                'permanent_house_no' => 'N/A',
                'permanent_street' => 'N/A',
                'permanent_brgy' => $data['permanent_brgy'],
                'permanent_city' => $data['permanent_city'],
                'permanent_province' => $data['permanent_province'],
                'permanent_region' => $data['permanent_region'],
                'farm_brgy' => $data['farm_brgy'],
                'farm_city' => $data['farm_city'],
                'mobile_number' => $data['mobile_number'],
                'is_mobile_owner' => 1,
                'mothers_maiden_first_name' => 'N/A',
                'mothers_maiden_surname' => 'N/A',
                'civil_status' => 'Single',
                'highest_education' => 'None',
                'livelihood_type' => 'Farmer',
                'total_farm_area_ha' => $data['total_farm_area_ha'],
                'is_pwd' => $data['is_pwd'] ? 1 : 0,
                'is_4ps_beneficiary' => $data['is_4ps_beneficiary'] ? 1 : 0,
                'is_icc_ip' => $data['is_icc_ip'] ? 1 : 0,
                'subsidy_exclusion_reason' => $data['subsidy_exclusion_reason'],
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($upsertRows) {
            foreach (array_chunk($upsertRows, 200) as $chunk) {
                DB::table('farmers')->upsert(
                    $chunk,
                    ['rsbsa_no'],
                    [
                        'surname',
                        'first_name',
                        'middle_name',
                        'ext_name',
                        'no_middle_name',
                        'no_ext_name',
                        'sex',
                        'birthdate',
                        'permanent_brgy',
                        'permanent_city',
                        'permanent_province',
                        'permanent_region',
                        'farm_brgy',
                        'farm_city',
                        'mobile_number',
                        'total_farm_area_ha',
                        'is_pwd',
                        'is_4ps_beneficiary',
                        'is_icc_ip',
                        'subsidy_exclusion_reason',
                        'deleted_at',
                        'updated_at',
                    ]
                );
            }
        });
    }
}
