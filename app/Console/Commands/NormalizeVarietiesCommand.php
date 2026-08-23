<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NormalizeVarietiesCommand extends Command
{
    protected $signature = 'db:normalize-varieties';

    protected $description = 'Map legacy free-text variety strings to the official Rice/Corn dropdown labels';

    /** @var array<string, string> collapsed lowercase key => official label */
    private const LABELS = [
        'nsicrc222' => 'NSIC Rc 222',
        'nsicrc216' => 'NSIC Rc 216',
        'nsicrc160' => 'NSIC Rc 160',
        'nsicrc218' => 'NSIC Rc 218',
        'nsicrc480' => 'NSIC Rc 480',
        'nsicrc402' => 'NSIC Rc 402',
        'nsicrc438' => 'NSIC Rc 438',
        'psbrc18' => 'PSB Rc 18',
        'psbrc82' => 'PSB Rc 82',
        'hybridrice' => 'Hybrid Rice',
        'hybridyellow' => 'Hybrid Yellow',
        'hybridwhite' => 'Hybrid White',
        'openpollinatedvarietyopv' => 'Open-Pollinated Variety (OPV)',
        'opv' => 'Open-Pollinated Variety (OPV)',
        'nk6410' => 'NK 6410',
        'pioneerhybrid' => 'Pioneer Hybrid',
        'pioneer' => 'Pioneer Hybrid',
        'dekalbhybrid' => 'Dekalb Hybrid',
        'dekalb' => 'Dekalb Hybrid',
    ];

    public function handle(): int
    {
        $targets = [
            ['planting_logs', 'variety'],
            ['harvest_logs', 'variety'],
            ['standing_crop_logs', 'variety'],
            ['pest_monitoring', 'variety'],
            ['damage_assessments', 'variety'],
            ['geo_tags', 'crop_variety'],
        ];

        $updated = 0;
        $skipped = 0;

        foreach ($targets as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                $this->warn("Skip {$table}.{$column} (missing).");
                continue;
            }

            $rows = DB::table($table)->whereNotNull($column)->where($column, '!=', '')->get(['id', $column]);
            $tableUpdated = 0;

            foreach ($rows as $row) {
                $current = (string) $row->{$column};
                $canonical = $this->canonicalLabel($current);
                if ($canonical === null || $canonical === $current) {
                    $skipped++;
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([$column => $canonical]);
                $updated++;
                $tableUpdated++;
            }

            $this->line("{$table}.{$column}: {$tableUpdated} updated");
        }

        $this->info("Done. Updated {$updated} row(s); left {$skipped} unchanged.");

        return self::SUCCESS;
    }

    private function canonicalLabel(string $value): ?string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
        if ($key === '') {
            return null;
        }

        return self::LABELS[$key] ?? null;
    }
}
