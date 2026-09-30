<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_subsidy_beneficiaries', 'priority_tier')) {
                $table->unsignedTinyInteger('priority_tier')->nullable()->after('status');
            }
            if (! Schema::hasColumn('tbl_subsidy_beneficiaries', 'selection_mode')) {
                $table->string('selection_mode', 16)->nullable()->after('priority_tier');
            }
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE tbl_subsidy_beneficiaries MODIFY COLUMN status ENUM('Pending', 'Waitlisted', 'Claimed') NOT NULL DEFAULT 'Pending'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('tbl_subsidy_beneficiaries')->where('status', 'Waitlisted')->update(['status' => 'Pending']);
            DB::statement("ALTER TABLE tbl_subsidy_beneficiaries MODIFY COLUMN status ENUM('Pending', 'Claimed') NOT NULL DEFAULT 'Pending'");
        }

        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_subsidy_beneficiaries', 'selection_mode')) {
                $table->dropColumn('selection_mode');
            }
            if (Schema::hasColumn('tbl_subsidy_beneficiaries', 'priority_tier')) {
                $table->dropColumn('priority_tier');
            }
        });
    }
};
