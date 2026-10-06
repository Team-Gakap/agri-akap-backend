<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            if (! Schema::hasColumn('farmers', 'subsidy_exclusion_reason')) {
                $table->string('subsidy_exclusion_reason', 128)->nullable()->after('total_farm_area_ha');
            }
            $table->index('permanent_brgy', 'farmers_permanent_brgy_index');
        });
    }

    public function down(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            $table->dropIndex('farmers_permanent_brgy_index');
            if (Schema::hasColumn('farmers', 'subsidy_exclusion_reason')) {
                $table->dropColumn('subsidy_exclusion_reason');
            }
        });
    }
};
