<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_subsidy_program_varieties', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_subsidy_program_varieties', 'target_barangays')) {
                $table->json('target_barangays')->nullable()->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tbl_subsidy_program_varieties', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_subsidy_program_varieties', 'target_barangays')) {
                $table->dropColumn('target_barangays');
            }
        });
    }
};
