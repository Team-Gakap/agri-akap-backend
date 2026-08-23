<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_subsidy_programs')) {
            return;
        }

        if (! Schema::hasColumn('tbl_subsidy_programs', 'min_hectares_limit')) {
            Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
                $table->decimal('min_hectares_limit', 10, 4)->default(0)->after('max_hectares_limit');
            });
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE tbl_subsidy_programs MODIFY target_crop ENUM('Rice', 'Corn', 'Both') NOT NULL");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_subsidy_programs')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::table('tbl_subsidy_programs')
                ->where('target_crop', 'Both')
                ->update(['target_crop' => 'Rice']);
            DB::statement("ALTER TABLE tbl_subsidy_programs MODIFY target_crop ENUM('Rice', 'Corn') NOT NULL");
        }

        if (Schema::hasColumn('tbl_subsidy_programs', 'min_hectares_limit')) {
            Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
                $table->dropColumn('min_hectares_limit');
            });
        }
    }
};
