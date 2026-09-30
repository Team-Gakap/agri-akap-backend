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

        if (! Schema::hasColumn('tbl_subsidy_programs', 'hvcc_commodity')) {
            Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
                $table->string('hvcc_commodity', 64)->nullable()->after('target_crop');
            });
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE tbl_subsidy_programs MODIFY target_crop ENUM('Rice', 'Corn', 'Both', 'HVCC') NOT NULL");
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
                ->where('target_crop', 'HVCC')
                ->update(['target_crop' => 'Rice', 'hvcc_commodity' => null]);
            DB::statement("ALTER TABLE tbl_subsidy_programs MODIFY target_crop ENUM('Rice', 'Corn', 'Both') NOT NULL");
        }

        if (Schema::hasColumn('tbl_subsidy_programs', 'hvcc_commodity')) {
            Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
                $table->dropColumn('hvcc_commodity');
            });
        }
    }
};
