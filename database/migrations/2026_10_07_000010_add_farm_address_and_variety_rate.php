<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            if (! Schema::hasColumn('farmers', 'farm_brgy')) {
                $table->string('farm_brgy')->nullable()->after('permanent_region');
            }
            if (! Schema::hasColumn('farmers', 'farm_city')) {
                $table->string('farm_city')->nullable()->after('farm_brgy');
            }
        });

        // Backfill: previously permanent_brgy held the farm location for imported rows.
        DB::table('farmers')
            ->whereNull('farm_brgy')
            ->whereNotNull('permanent_brgy')
            ->update([
                'farm_brgy' => DB::raw('permanent_brgy'),
                'farm_city' => DB::raw("COALESCE(farm_city, 'Echague')"),
            ]);

        Schema::table('farmers', function (Blueprint $table) {
            $table->index('farm_brgy', 'farmers_farm_brgy_index');
        });

        Schema::table('tbl_subsidy_program_varieties', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_subsidy_program_varieties', 'bags_per_hectare')) {
                $table->decimal('bags_per_hectare', 10, 4)->nullable()->after('unit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            try {
                $table->dropIndex('farmers_farm_brgy_index');
            } catch (\Throwable) {
                //
            }
            if (Schema::hasColumn('farmers', 'farm_city')) {
                $table->dropColumn('farm_city');
            }
            if (Schema::hasColumn('farmers', 'farm_brgy')) {
                $table->dropColumn('farm_brgy');
            }
        });

        Schema::table('tbl_subsidy_program_varieties', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_subsidy_program_varieties', 'bags_per_hectare')) {
                $table->dropColumn('bags_per_hectare');
            }
        });
    }
};
