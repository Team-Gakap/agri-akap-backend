<?php

use App\Support\OfficialBarangays;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const RESTORE = [
        'Cabugao Pob.' => 'Cabugao (Poblacion)',
        'San Manuel' => 'San Manuel (formerly Atelan)',
        'Silauan Norte Pob.' => 'Silauan Norte (Poblacion)',
        'Silauan Sur Pob.' => 'Silauan Sur (Poblacion)',
        'Soyung' => 'Soyung (Poblacion)',
        'Taggappan' => 'Taggappan (Poblacion)',
    ];

    public function up(): void
    {
        $this->rewriteStored(fn (?string $name) => OfficialBarangays::canonicalize($name));
    }

    public function down(): void
    {
        $this->rewriteStored(function (?string $name) {
            $trimmed = trim((string) $name);

            return self::RESTORE[$trimmed] ?? $trimmed;
        });
    }

    private function rewriteStored(callable $map): void
    {
        $this->rewriteColumn('tbl_barangays', 'name', $map, true);
        $this->rewriteColumn('users', 'assigned_barangay', $map);
        foreach (['farm_brgy', 'permanent_brgy', 'provincial_brgy'] as $column) {
            $this->rewriteColumn('farmers', $column, $map);
        }
        $this->rewriteColumn('farm_plots', 'location_brgy', $map);

        foreach ([
            'tbl_weather_cache',
            'tbl_weather_hourly',
            'tbl_weather_historical',
            'tbl_weather_current',
        ] as $table) {
            $this->rewriteColumn($table, 'barangay_name', $map, true);
        }

        foreach ([
            'tbl_subsidy_programs',
            'tbl_subsidy_program_varieties',
            'programs',
        ] as $table) {
            $this->rewriteJsonList($table, 'target_barangays', $map);
        }
    }

    private function rewriteColumn(string $table, string $column, callable $map, bool $dropCollisions = false): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $names = DB::table($table)->whereNotNull($column)->distinct()->pluck($column);
        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }
            $canonical = $map($name);
            if (! is_string($canonical) || $canonical === '' || $canonical === $name) {
                continue;
            }

            try {
                DB::table($table)->where($column, $name)->update([$column => $canonical]);
            } catch (\Throwable) {
                if (! $dropCollisions) {
                    throw new \RuntimeException("Could not rename {$table}.{$column} from {$name} to {$canonical}.");
                }
                DB::table($table)->where($column, $name)->delete();
            }
        }
    }

    private function rewriteJsonList(string $table, string $column, callable $map): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)->whereNotNull($column)->orderBy('id')->chunkById(200, function ($rows) use ($table, $column, $map) {
            foreach ($rows as $row) {
                $decoded = is_array($row->{$column})
                    ? $row->{$column}
                    : json_decode((string) $row->{$column}, true);
                if (! is_array($decoded)) {
                    continue;
                }

                $next = [];
                foreach ($decoded as $name) {
                    if (! is_string($name) && ! is_numeric($name)) {
                        continue;
                    }
                    $canonical = $map((string) $name);
                    if (is_string($canonical) && $canonical !== '') {
                        $next[$canonical] = $canonical;
                    }
                }
                $next = array_values($next);
                if ($next === array_values($decoded)) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([
                    $column => json_encode($next),
                ]);
            }
        });
    }
};
