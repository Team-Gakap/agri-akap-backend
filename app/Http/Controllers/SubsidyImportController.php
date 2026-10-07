<?php

namespace App\Http\Controllers;

use App\Imports\FarmersImport;
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

        $remarks = AuditRemarks::require($request, 'A justification is required before committing a masterlist import.');

        $absolutePath = Storage::disk('local')->path($batch->stored_path);
        if (! is_file($absolutePath)) {
            return response()->json([
                'status' => 'error',
                'message' => 'The uploaded workbook is no longer on disk. Upload it again.',
            ], 422);
        }

        $batch->update(['status' => 'processing', 'error_message' => null]);

        try {
            // Farmer-registry only — does not create subsidy programs or beneficiaries.
            $import = new FarmersImport;
            Excel::import($import, $absolutePath);
        } catch (\Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 2000, ''),
            ]);
            Log::error('Masterlist farmer import failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Import failed. Check the workbook columns and try again.',
                'error' => app()->isLocal() ? $e->getMessage() : null,
            ], 422);
        }

        $batch->update(['status' => 'completed']);

        $result = [
            'created' => $import->created,
            'updated' => $import->updated,
            'skipped' => $import->skipped,
            'excluded' => $import->excluded,
        ];

        $this->logReportAudit('subsidy_import_batch.committed', $batch, [
            'after' => [
                'filename' => $batch->original_filename,
                'month_year' => $batch->month_year,
                'farmers' => $result,
            ],
            'remarks' => $remarks,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Masterlist imported into the Farmer Registry. No subsidy program was created.',
            'data' => [
                'batch' => $batch->fresh(),
                'farmers' => $result,
                // Keep sheets shape empty so older UIs do not crash.
                'sheets' => [],
            ],
        ]);
    }

    /**
     * @deprecated Program sheets are no longer created on masterlist upload.
     * Kept temporarily so older admin clients that still POST sheet configs do not 500.
     */
    public function commitLegacyPrograms(Request $request, string $batchId): JsonResponse
    {
        return $this->commit($request, $batchId);
    }

    /**
     * @param  array<string, mixed>  $sheet
     */
    private function validateSheetConfig(array $sheet, string $sheetName): ?string
    {
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
