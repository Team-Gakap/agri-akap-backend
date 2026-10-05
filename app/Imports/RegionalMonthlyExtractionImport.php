<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class RegionalMonthlyExtractionImport implements WithMultipleSheets
{
    /** @var array<int, RegionalProgramSheetImport> */
    public array $importers = [];

    /** @var array<int, RegionalProgramSheetImport>|null */
    private ?array $sheetMap = null;

    /**
     * @param  array<int, array<string, mixed>>  $sheetConfigsByIndex
     */
    public function __construct(
        protected array $sheetConfigsByIndex,
        protected string $batchId,
    ) {
    }

    public function sheets(): array
    {
        if ($this->sheetMap !== null) {
            return $this->sheetMap;
        }

        $map = [];
        foreach ($this->sheetConfigsByIndex as $index => $config) {
            if (($config['mode'] ?? 'skip') === 'skip') {
                continue;
            }
            $importer = new RegionalProgramSheetImport($config, $this->batchId);
            $this->importers[] = $importer;
            $map[(int) $index] = $importer;
        }

        $this->sheetMap = $map;

        return $map;
    }
}
