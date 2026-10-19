<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropColumns('planting_logs', ['crop_category', 'hvcc_commodity', 'num_hills_trees']);
        $this->dropColumns('standing_crop_logs', ['crop_category', 'hvcc_commodity', 'num_hills_trees']);
        $this->dropColumns('harvest_logs', ['crop_category', 'hvcc_commodity', 'num_hills_trees']);
        $this->dropColumns('pest_monitoring', ['crop_category', 'hvcc_commodity']);
        $this->dropColumns('damage_assessments', ['crop_category', 'hvcc_commodity', 'num_hills_trees']);

        if (! Schema::hasTable('tbl_subsidy_programs')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasColumn('tbl_subsidy_programs', 'target_crop')) {
            DB::table('tbl_subsidy_programs')
                ->where('target_crop', 'HVCC')
                ->update(['target_crop' => 'Both', 'status' => 'Completed']);
            DB::statement("ALTER TABLE tbl_subsidy_programs MODIFY target_crop ENUM('Rice', 'Corn', 'Both') NOT NULL");
        }

        $this->dropColumns('tbl_subsidy_programs', ['hvcc_commodity']);
    }

    public function down(): void
    {
        // HVCC columns are not restored. Re-run the October 2026 add migrations only on a fresh database.
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $present = array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($table, $column)
        ));

        if ($present === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($present) {
            $blueprint->dropColumn($present);
        });
    }
};
