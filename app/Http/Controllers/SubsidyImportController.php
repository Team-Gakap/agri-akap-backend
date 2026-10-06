<?php

namespace App\Http\Controllers;

use App\Imports\RegionalMonthlyExtractionImport;
use App\Models\SubsidyImportBatch;
use App\Models\SubsidyProgram;
use App\Models\SubsidyProgramVariety;
use App\Support\AuditRemarks;
use App\Support\RegionalExtractionColumns;
use App\Support\SubsidyCatalog;
use App\Traits\LogsReportAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SubsidyImportController extends Controller
{
    use LogsReportAudit;

    public function index(): JsonResponse
    {
        $batches = SubsidyImportBatch::query()
            ->with('uploader:id,name')
            ->withCount('programs')
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'message' => 'Import batches loaded.',
            'data' => $batches,
        ]);
    }

    public function show(string $batchId): JsonResponse
    {
        $batch = SubsidyImportBatch::query()
            ->with(['uploader:id,name', 'programs.varieties'])
            ->withCount('programs')
            ->findOrFail($batchId);

        return response()->json([
            'status' => 'success',
            'message' => 'Import batch loaded.',
            'data' => [
                'batch' => $batch,
                'programs' => $batch->programs->map(fn (SubsidyProgram $program) => $this->serializeProgram($program)),
            ],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workbook' => 'required|file|mimes:xlsx,xls|max:20480',
            'batch_name' => 'required|string|max:255',
            'month_year' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $upload = $request->file('workbook');
        $extension = strtolower($upload->getClientOriginalExtension() ?: 'xlsx');
        $storedPath = $upload->storeAs('subsidy-imports', (string) Str::uuid().'.'.$extension, 'local');

        $batch = SubsidyImportBatch::create([
            'batch_name' => $validated['batch_name'],
            'original_filename' => $upload->getClientOriginalName(),
            'stored_path' => $storedPath,
            'month_year' => $validated['month_year'],
            'uploaded_by' => $request->user()->id,
            'status' => 'pending',
        ]);

        try {
            $sheets = $this->inspectWorkbook(Storage::disk('local')->path($storedPath));
        } catch (\Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 2000, ''),
            ]);
            Log::error('Subsidy workbook preview failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Could not read that workbook. Check that it is a valid .xlsx or .xls file.',
            ], 422);
        }

        $batch->update(['total_sheets' => count($sheets)]);

        return response()->json([
            'status' => 'success',
            'message' => 'Workbook preview ready.',
            'data' => [
                'batch' => $batch->fresh(),
                'sheets' => $sheets,
            ],
        ]);
    }

    public function commit(Request $request, string $batchId): JsonResponse
    {
        $batch = SubsidyImportBatch::query()->findOrFail($batchId);

        if (! in_array($batch->status, ['pending', 'failed', 'completed'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This batch is already being processed.',
            ], 409);
        }

        $remarks = AuditRemarks::require($request, 'A justification is required before committing a regional masterlist import.');

        $validated = $request->validate([
            'sheets' => 'required|array|min:1',
            'sheets.*.index' => 'required|integer|min:0',
            'sheets.*.sheet_name' => 'required|string|max:100',
            'sheets.*.mode' => ['required', Rule::in(['catalog', 'legacy', 'skip'])],
            'sheets.*.program_name' => 'nullable|string|max:255',
            'sheets.*.seed_class' => ['nullable', Rule::in(SubsidyCatalog::seedClasses())],
            'sheets.*.item_type' => ['nullable', Rule::in(['seed', 'abono', 'liquid_fertilizer', 'wettable', 'cash'])],
            'sheets.*.unit_of_measurement' => 'nullable|string|max:64',
            'sheets.*.target_crop' => ['nullable', Rule::in(['Rice', 'Corn', 'Both', 'HVCC'])],
            'sheets.*.max_hectares_limit' => 'nullable|numeric|min:0.01|max:9999',
            'sheets.*.min_hectares_limit' => 'nullable|numeric|min:0|max:9999',
            'sheets.*.items_per_hectare' => 'nullable|numeric|min:0.01|max:100000',
            'sheets.*.secondary_items_per_hectare' => 'nullable|numeric|min:0.01|max:100000',
            'sheets.*.total_quantity' => 'nullable|numeric|min:0|max:1000000',
            'sheets.*.secondary_total_quantity' => 'nullable|numeric|min:0|max:1000000',
            'sheets.*.reorder_level' => 'nullable|numeric|min:0|max:1000000',
            'sheets.*.secondary_reorder_level' => 'nullable|numeric|min:0|max:1000000',
            // Variety breakdown (optional). When provided, total_quantity is the sum.
            'sheets.*.varieties'                    => 'nullable|array',
            'sheets.*.varieties.*.variety_name'     => 'required_with:sheets.*.varieties|string|max:120',
            'sheets.*.varieties.*.quantity'         => 'required_with:sheets.*.varieties|numeric|min:0|max:1000000',
            'sheets.*.varieties.*.unit'             => 'nullable|string|max:64',
        ]);

        $absolutePath = Storage::disk('local')->path($batch->stored_path);
        if (! is_file($absolutePath)) {
            return response()->json([
                'status' => 'error',
                'message' => 'The uploaded workbook is no longer on disk. Upload it again.',
            ], 422);
        }

        try {
            $inspected = collect($this->inspectWorkbook($absolutePath))->keyBy('index');
        } catch (\Throwable $e) {
            Log::error('Subsidy workbook re-read failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Could not read the stored workbook.',
            ], 422);
        }

        $configsByIndex = [];
        foreach ($validated['sheets'] as $sheet) {
            $index = (int) $sheet['index'];
            $detected = $inspected->get($index);
            if (! $detected) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Sheet index {$index} is not in this workbook.",
                ], 422);
            }

            if ($sheet['mode'] === 'skip') {
                continue;
            }

            $missing = $detected['header_match']['missing'] ?? [];
            if ($missing !== []) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Sheet "'.$detected['name'].'" is missing required columns: '.implode(', ', $missing).'.',
                ], 422);
            }

            $error = $this->validateSheetConfig($sheet, $detected['name']);
            if ($error !== null) {
                return response()->json([
                    'status' => 'error',
                    'message' => $error,
                ], 422);
            }

            $config = $sheet;
            $config['sheet_name'] = $detected['name'];
            $config['month_year'] = $batch->month_year;
            $configsByIndex[$index] = $config;
        }

        if ($configsByIndex === []) {
            return response()->json([
                'status' => 'error',
                'message' => 'Map at least one sheet before committing the import.',
            ], 422);
        }

        $batch->update(['status' => 'processing', 'error_message' => null]);

        try {
            $import = new RegionalMonthlyExtractionImport($configsByIndex, $batch->id);
            Excel::import($import, $absolutePath);
            $import->sheets();
        } catch (\Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 2000, ''),
            ]);
            Log::error('Subsidy workbook commit failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Import failed. Check the workbook and the sheet mapping, then try again.',
                'error' => app()->isLocal() ? $e->getMessage() : null,
            ], 422);
        }

        $batch->update(['status' => 'completed']);

        // Sync variety rows for each imported program (uses the sheet config).
        foreach ($import->importers as $importer) {
            if ($importer->programId === null) {
                continue;
            }
            $sheetVarieties = $configsByIndex[$importer->sheetIndex]['varieties'] ?? [];
            if (! empty($sheetVarieties)) {
                $this->syncVarieties($importer->programId, $sheetVarieties);
            }
        }

        $results = collect($import->importers)->map(fn ($importer) => [
            'program_id' => $importer->programId,
            'program_name' => $importer->programName,
            'created' => $importer->rowsCreated,
            'updated' => $importer->rowsUpdated,
            'waitlisted' => $importer->rowsWaitlisted,
            'excluded' => $importer->rowsExcluded,
            'skipped' => $importer->rowsSkipped,
            'duplicates_in_file' => $importer->duplicatesInFile,
        ])->values();

        $this->logReportAudit('subsidy_import_batch.committed', $batch, [
            'after' => [
                'filename' => $batch->original_filename,
                'month_year' => $batch->month_year,
                'sheets' => $results,
            ],
            'remarks' => $remarks,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Regional masterlist imported.',
            'data' => [
                'batch' => $batch->fresh(),
                'sheets' => $results,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $sheet
     */
    private function validateSheetConfig(array $sheet, string $sheetName): ?string
    {
        if (empty($sheet['target_crop'])) {
            return "Choose a target crop for \"{$sheetName}\".";
        }
        if (empty($sheet['max_hectares_limit']) || (float) $sheet['max_hectares_limit'] <= 0) {
            return "Max hectares must be greater than 0 for \"{$sheetName}\".";
        }
        if (isset($sheet['min_hectares_limit'], $sheet['max_hectares_limit'])
            && (float) $sheet['min_hectares_limit'] > (float) $sheet['max_hectares_limit']) {
            return "Min hectares cannot exceed max hectares for \"{$sheetName}\".";
        }
        if (empty($sheet['items_per_hectare']) || (float) $sheet['items_per_hectare'] <= 0) {
            return "Enter a per-hectare rate for \"{$sheetName}\".";
        }
        if (! isset($sheet['total_quantity']) || (float) $sheet['total_quantity'] < 0) {
            return "Enter opening stock for \"{$sheetName}\".";
        }

        if ($sheet['mode'] === 'catalog') {
            $seedClass = $sheet['seed_class'] ?? null;
            $itemType = $sheet['item_type'] ?? null;
            if (! SubsidyCatalog::isValidCombo($seedClass, $itemType)) {
                return "\"{$sheetName}\" needs a valid seed class and item type from the MAO catalog.";
            }
            if (SubsidyCatalog::isDualUnit($seedClass, $itemType) && empty($sheet['secondary_items_per_hectare'])) {
                $unit = SubsidyCatalog::secondaryUnit($seedClass, $itemType);

                return "\"{$sheetName}\" needs a {$unit}/ha rate as well.";
            }

            return null;
        }

        if (trim((string) ($sheet['program_name'] ?? '')) === '') {
            return "Enter a program name for \"{$sheetName}\".";
        }
        if (trim((string) ($sheet['unit_of_measurement'] ?? '')) === '') {
            return "Enter a unit of measurement for \"{$sheetName}\".";
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inspectWorkbook(string $absolutePath): array
    {
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($absolutePath);
        $sheets = [];

        foreach ($spreadsheet->getAllSheets() as $index => $worksheet) {
            $highestRow = (int) $worksheet->getHighestDataRow();
            $highestColumn = (string) $worksheet->getHighestDataColumn();
            $header = [];
            if ($highestColumn !== '' && $highestRow >= 1) {
                $header = $worksheet->rangeToArray('A1:'.$highestColumn.'1', null, true, false)[0] ?? [];
            }
            [$seedClass, $itemType] = $this->suggestCatalog($worksheet->getTitle());
            $sheets[] = [
                'index' => $index,
                'name' => $worksheet->getTitle(),
                'row_count' => max(0, $highestRow - 1),
                'header_match' => RegionalExtractionColumns::matchReport($header),
                'suggested_seed_class' => $seedClass,
                'suggested_item_type' => $itemType,
            ];
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $sheets;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function suggestCatalog(string $title): array
    {
        $haystack = strtolower($title);
        foreach (SubsidyCatalog::all() as $seedClass => $items) {
            if (! str_contains($haystack, strtolower($seedClass))) {
                continue;
            }
            foreach ($items as $itemType => $entry) {
                if (str_contains($haystack, strtolower($entry['label']))) {
                    return [$seedClass, $itemType];
                }
            }
        }

        return [null, null];
    }

    /**
     * Sync (upsert/replace) variety rows for a program from the sheet's variety
     * breakdown config.  When the program has no Claimed beneficiaries yet the
     * variety list is fully replaced; otherwise only new varieties are added and
     * unclaimed existing varieties have their totals updated.
     *
     * @param  array<int, array{variety_name: string, quantity: float|int, unit?: string|null}>  $varieties
     */
    private function syncVarieties(string $programId, array $varieties): void
    {
        $program = SubsidyProgram::find($programId);
        if (! $program) {
            return;
        }

        $hasClaims = $program->beneficiaries()->where('status', 'Claimed')->exists();

        if (! $hasClaims) {
            // Safe to replace entire variety list (mirrors existing total/remaining reset).
            SubsidyProgramVariety::where('program_id', $programId)->delete();
        }

        $totalFromVarieties = 0.0;
        foreach ($varieties as $index => $v) {
            $name = trim((string) ($v['variety_name'] ?? ''));
            $qty  = (float) ($v['quantity'] ?? 0);
            $unit = isset($v['unit']) ? trim((string) $v['unit']) : null;
            if ($name === '' || $qty < 0) {
                continue;
            }

            $totalFromVarieties += $qty;

            SubsidyProgramVariety::updateOrCreate(
                ['program_id' => $programId, 'variety_name' => $name],
                [
                    'unit'               => $unit ?: null,
                    'total_quantity'     => $qty,
                    // Only reset remaining for programs with no claims yet.
                    'remaining_quantity' => $hasClaims
                        ? \DB::raw('remaining_quantity')  // leave untouched
                        : $qty,
                    'sort_order'         => $index,
                ]
            );
        }

        // Keep the program-level totals as a roll-up so existing dashboards work.
        if (! $hasClaims) {
            $program->total_quantity     = $totalFromVarieties;
            $program->remaining_quantity = $totalFromVarieties;
            $program->save();
        }
    }

    private function serializeProgram(SubsidyProgram $program): array
    {
        $varieties = $program->varieties->map(fn ($v) => [
            'id'                 => $v->id,
            'variety_name'       => $v->variety_name,
            'unit'               => $v->unit,
            'total_quantity'     => (float) $v->total_quantity,
            'remaining_quantity' => (float) $v->remaining_quantity,
            'reorder_level'      => $v->reorder_level !== null ? (float) $v->reorder_level : null,
        ])->values();

        return [
            'id' => $program->id,
            'program_name' => $program->program_name,
            'sheet_name' => $program->sheet_name,
            'source' => $program->source,
            'target_crop' => $program->target_crop,
            'seed_class' => $program->seed_class,
            'item_type' => $program->item_type,
            'status' => $program->status,
            'unit_of_measurement' => $program->unit_of_measurement,
            'secondary_unit' => $program->secondary_unit,
            'items_per_hectare' => (float) $program->items_per_hectare,
            'total_quantity' => (float) $program->total_quantity,
            'remaining_quantity' => (float) $program->remaining_quantity,
            'varieties' => $varieties,
        ];
    }
}
