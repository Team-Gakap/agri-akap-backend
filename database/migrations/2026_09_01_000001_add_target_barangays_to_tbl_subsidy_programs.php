<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
            $table->json('target_barangays')->nullable()->after('target_crop');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
            $table->dropColumn('target_barangays');
        });
    }
};
