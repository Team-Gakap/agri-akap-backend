<?php

namespace App\Imports;

use App\Models\Farmer;
use App\Models\SubsidyBeneficiary;
use App\Models\SubsidyProgram;
use App\Support\OfficialLocations;
use App\Support\RegionalExtractionColumns;
use App\Support\SubsidyCatalog;
use App\Support\SubsidyExclusionRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class RegionalProgramSheetImport implements ToCollection, WithHeadingRow
{
    public int $rowsCreated = 0;

    public int $rowsUpdated = 0;

    public int $rowsWaitlisted = 0;

    public int $rowsSkipped = 0;

    public int $duplicatesInFile = 0;

    /** Rows excluded due to a disqualifying remark (DECEASED, OFW, NO FARM, INACTIVE…). */
    public int $rowsExcluded = 0;

    public ?string $programId = null;

    public ?string $programName = null;

    /**
     * @param  array<string, mixed>  $sheetConfig
     */
    public function __construct(
        protected array $sheetConfig,
        protected string $batchId,
        public int $sheetIndex = 0,
    ) {
    }

    public function collection(Collection $rows): void
    {
        $program = $this->resolveProgram();
        $this->programId = $program->id;
        $this->programName = $program->program_name;

        $seen = [];
        $prepared = [];
        $excluded = [];

        foreach ($rows as $row) {
            $data = RegionalExtractionColumns::extractRow($row->toArray());
            if ($data === null) {
                $this->rowsSkipped++;
                continue;
            }

            $key = strtoupper($data['rsbsa_no']);
            if (isset($seen[$key])) {
                $this->duplicatesInFile++;
                $this->rowsSkipped++;
                continue;
            }
            $seen[$key] = true;

            // Check for a disqualifying remark BEFORE computing allocation.
            $exclusionLabel = SubsidyExclusionRules::matchedLabel($data['remarks'] ?? null);
            if ($exclusionLabel !== null) {
                $excluded[] = ['row' => $data, 'reason' => $exclusionLabel];
                continue;
            }

            $allocation = $this->allocationFor($program, (float) $data['farm_area']);
            if ($allocation === null) {
                $this->rowsSkipped++;
                continue;
            }

            $prepared[] = [
                'row' => $data,
                'primary' => $allocation['primary'],
                'secondary' => $allocation['secondary'],
            ];
        }

        $rsbsas = array_map(fn ($item) => $item['row']['rsbsa_no'], $prepared);
        $reserved = $this->reservedPendingOutside($program, $rsbsas);
        $primaryLeft = max(0, (float) $program->remaining_quantity - $reserved['primary']);
        $secondaryLeft = $program->secondary_unit !== null
            ? max(0, (float) ($program->secondary_remaining_quantity ?? 0) - $reserved['secondary'])
            : null;

        $cutoff = false;
        $now = now();

        DB::transaction(function () use ($prepared, $excluded, $program, &$primaryLeft, &$secondaryLeft, &$cutoff, $now) {
            // ── Eligible rows ──────────────────────────────────────────────────────
            foreach ($prepared as $item) {
                $data = $item['row'];
                $primary = $item['primary'];
                $secondary = $item['secondary'];

                $farmer = $this->upsertFarmer($data);

                $existing = SubsidyBeneficiary::withTrashed()
                    ->where('program_id', $program->id)
                    ->where('farmer_rsbsa_no', $data['rsbsa_no'])
                    ->first();

                if ($existing && $existing->status === 'Claimed' && ! $existing->trashed()) {
                    continue;
                }

                $fitsPrimary = ($primaryLeft + 0.0000001) >= $primary;
                $fitsSecondary = $secondaryLeft === null
                    || $secondary === null
                    || ($secondaryLeft + 0.0000001) >= $secondary;

                if ($cutoff || ! $fitsPrimary || ! $fitsSecondary) {
                    $cutoff = true;
                    $status = 'Waitlisted';
                    $this->rowsWaitlisted++;
                } else {
                    $status = 'Pending';
                    $primaryLeft -= $primary;
                    if ($secondaryLeft !== null && $secondary !== null) {
                        $secondaryLeft -= $secondary;
                    }
                }

                $payload = [
                    'batch_id' => $this->batchId,
                    'farmer_id' => $farmer->id,
                    'farmer_rsbsa_no' => $data['rsbsa_no'],
                    'is_walkin' => false,
                    'calculated_allocation' => $primary,
                    'calculated_allocation_secondary' => $secondary,
                    'source_farm_area' => $data['farm_area'],
                    'source_commodity' => $data['commodity'],
                    'source_farmer_barangay' => $data['farmer_address_1'],
                    'source_farmer_municipality' => $data['farmer_address_2'],
                    'source_farm_barangay' => $data['farm_address_1'],
                    'source_farm_municipality' => $data['farm_address_2'],
                    'status' => $status,
                    'selection_mode' => 'regional_import',
                    'exclusion_reason' => null,
                    'updated_at' => $now,
                ];

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    $existing->update($payload);
                    $this->rowsUpdated++;
                    continue;
                }

                SubsidyBeneficiary::create($payload + [
                    'program_id' => $program->id,
                    'created_at' => $now,
                ]);
                $this->rowsCreated++;
            }

            // ── Excluded rows (disqualifying remark) ──────────────────────────────
            // We still upsert the farmer record (preserves name/address data) but
            // the beneficiary row gets status = Excluded and cannot be claimed.
            // Excluded rows do NOT consume any stock allocation.
            foreach ($excluded as $item) {
                $data = $item['row'];
                $reason = $item['reason'];

                $farmer = $this->upsertFarmer($data);

                $existing = SubsidyBeneficiary::withTrashed()
                    ->where('program_id', $program->id)
                    ->where('farmer_rsbsa_no', $data['rsbsa_no'])
                    ->first();

                // Never overwrite a Claimed row with Excluded.
                if ($existing && $existing->status === 'Claimed' && ! $existing->trashed()) {
                    continue;
                }

                $payload = [
                    'batch_id' => $this->batchId,
                    'farmer_id' => $farmer->id,
                    'farmer_rsbsa_no' => $data['rsbsa_no'],
                    'is_walkin' => false,
                    'calculated_allocation' => 0,
                    'calculated_allocation_secondary' => null,
                    'source_farm_area' => $data['farm_area'],
                    'source_commodity' => $data['commodity'],
                    'source_farmer_barangay' => $data['farmer_address_1'],
                    'source_farmer_municipality' => $data['farmer_address_2'],
                    'source_farm_barangay' => $data['farm_address_1'],
                    'source_farm_municipality' => $data['farm_address_2'],
                    'status' => 'Excluded',
                    'selection_mode' => 'regional_import',
                    'exclusion_reason' => $reason,
                    'updated_at' => $now,
                ];

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    $existing->update($payload);
                } else {
                    SubsidyBeneficiary::create($payload + [
                        'program_id' => $program->id,
                        'created_at' => $now,
                    ]);
                }
                $this->rowsExcluded++;
            }
        });
    }

    private function resolveProgram(): SubsidyProgram
    {
        $sheetName = (string) $this->sheetConfig['sheet_name'];
        $monthYear = $this->sheetConfig['month_year'] ?? null;

        $existing = SubsidyProgram::query()
            ->where('source', 'regional_import')
            ->where('sheet_name', $sheetName)
            ->where(function ($query) use ($monthYear) {
                $query->where('batch_id', $this->batchId);
                if (is_string($monthYear) && $monthYear !== '') {
                    $query->orWhereHas('importBatch', fn ($batch) => $batch->where('month_year', $monthYear));
                }
            })
            ->first();

        $attributes = $this->programAttributes($sheetName);

        if (! $existing) {
            return SubsidyProgram::create($attributes + ['status' => 'Draft']);
        }

        $claimed = $existing->beneficiaries()->where('status', 'Claimed')->exists();
        if (! $claimed && $existing->status !== 'Completed') {
            $existing->fill($attributes);
        } else {
            $existing->batch_id = $this->batchId;
            $existing->sheet_name = $sheetName;
        }
        $existing->save();

        return $existing->fresh() ?? $existing;
    }

    /**
     * @return array<string, mixed>
     */
    private function programAttributes(string $sheetName): array
    {
        $mode = (string) ($this->sheetConfig['mode'] ?? 'legacy');
        $seedClass = $mode === 'catalog' ? ($this->sheetConfig['seed_class'] ?? null) : null;
        $itemType = $mode === 'catalog' ? ($this->sheetConfig['item_type'] ?? null) : null;
        $secondaryUnit = $mode === 'catalog' ? SubsidyCatalog::secondaryUnit($seedClass, $itemType) : null;
        $unit = $mode === 'catalog'
            ? (SubsidyCatalog::unit($seedClass, $itemType) ?: 'Bags')
            : (string) ($this->sheetConfig['unit_of_measurement'] ?? 'Bags');

        $total = (float) ($this->sheetConfig['total_quantity'] ?? 0);
        $secondaryTotal = $secondaryUnit !== null
            ? (float) ($this->sheetConfig['secondary_total_quantity'] ?? 0)
            : null;

        $programName = $mode === 'catalog'
            ? (string) ($this->sheetConfig['program_name'] ?? $sheetName)
            : (string) ($this->sheetConfig['program_name'] ?? $sheetName);

        return [
            'batch_id' => $this->batchId,
            'program_name' => $programName !== '' ? $programName : $sheetName,
            'sheet_name' => $sheetName,
            'source' => 'regional_import',
            'target_crop' => $this->sheetConfig['target_crop'] ?? 'Rice',
            'seed_class' => $seedClass,
            'item_type' => $itemType,
            'max_hectares_limit' => $this->sheetConfig['max_hectares_limit'] ?? 9999,
            'min_hectares_limit' => $this->sheetConfig['min_hectares_limit'] ?? 0,
            'items_per_hectare' => $this->sheetConfig['items_per_hectare'] ?? 0,
            'secondary_items_per_hectare' => $secondaryUnit !== null
                ? ($this->sheetConfig['secondary_items_per_hectare'] ?? null)
                : null,
            'unit_of_measurement' => $unit ?: 'Bags',
            'secondary_unit' => $secondaryUnit,
            'total_quantity' => $total,
            'remaining_quantity' => $total,
            'reorder_level' => $this->sheetConfig['reorder_level'] ?? null,
            'secondary_total_quantity' => $secondaryTotal,
            'secondary_remaining_quantity' => $secondaryTotal,
            'secondary_reorder_level' => $secondaryUnit !== null
                ? ($this->sheetConfig['secondary_reorder_level'] ?? null)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertFarmer(array $data): Farmer
    {
        $existing = Farmer::withTrashed()->where('rsbsa_no', $data['rsbsa_no'])->first();
        $profile = [
            'surname' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'],
            'ext_name' => $data['suffix_and_extension'],
            'no_middle_name' => empty($data['middle_name']),
            'no_ext_name' => empty($data['suffix_and_extension']),
            'permanent_brgy' => $data['farm_address_1'] ?: ($data['farmer_address_1'] ?: ($existing->permanent_brgy ?? 'Unknown')),
            'permanent_city' => 'Echague',
            'permanent_province' => 'Isabela',
            'total_farm_area_ha' => $data['farm_area'],
            'subsidy_exclusion_reason' => $data['subsidy_exclusion_reason'] ?? null,
        ];
        if (! empty($data['mobile_number'])) {
            $profile['mobile_number'] = $data['mobile_number'];
        }
        if (! empty($data['sex'])) {
            $profile['sex'] = $data['sex'];
        }

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->update($profile);

            return $existing;
        }

        // sex, birthdate, and mobile are still NOT NULL and are not in the RFO extract.
        $defaults = OfficialLocations::catalog()['defaults'];

        return Farmer::create($profile + [
            'rsbsa_no' => $data['rsbsa_no'],
            'registration_type' => 'rsbsa',
            'is_temporary' => false,
            'transaction_code' => 'RFO-'.Str::upper(Str::random(10)),
            'qr_code_hash' => (string) Str::uuid(),
            'sex' => 'Male',
            'birthdate' => '1970-01-01',
            'mobile_number' => '09000000000',
            'is_mobile_owner' => true,
            'permanent_city' => $data['farmer_address_2'] ?: $defaults['city'],
            'permanent_province' => $defaults['province'] ?? null,
            'permanent_region' => $defaults['region'] ?? null,
            'livelihood_type' => 'Farmer',
            'verification_status' => 'pending',
        ]);
    }

    /**
     * @return array{primary: int, secondary: ?int}|null
     */
    private function allocationFor(SubsidyProgram $program, float $farmArea): ?array
    {
        $minHa = (float) ($program->min_hectares_limit ?? 0);
        if ($minHa > 0 && $farmArea + 0.0000001 < $minHa) {
            return null;
        }

        $eligibleArea = min($farmArea, (float) $program->max_hectares_limit);
        $primary = (int) floor(($eligibleArea * (float) $program->items_per_hectare) + 0.0000001);
        $secondary = null;
        if ($program->secondary_unit !== null) {
            $secondary = (int) floor(
                ($eligibleArea * (float) $program->secondary_items_per_hectare) + 0.0000001
            );
        }

        if ($primary < 1 && ($secondary === null || $secondary < 1)) {
            return null;
        }

        return ['primary' => $primary, 'secondary' => $secondary];
    }

    /**
     * @param  array<int, string>  $rsbsaNos
     * @return array{primary: float, secondary: float}
     */
    private function reservedPendingOutside(SubsidyProgram $program, array $rsbsaNos): array
    {
        $query = DB::table('tbl_subsidy_beneficiaries')
            ->where('program_id', $program->id)
            ->where('status', 'Pending');
        SubsidyBeneficiary::applyNotDeleted($query);

        if (count($rsbsaNos) > 0) {
            $query->whereNotIn('farmer_rsbsa_no', $rsbsaNos);
        }

        $secondaryQuery = clone $query;

        return [
            'primary' => (float) ($query->sum('calculated_allocation') ?? 0),
            'secondary' => (float) ($secondaryQuery->sum('calculated_allocation_secondary') ?? 0),
        ];
    }
}
