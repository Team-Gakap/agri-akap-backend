<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            if (! Schema::hasColumn('farmers', 'farm_psgc')) {
                $table->string('farm_psgc', 12)->nullable()->after('farm_city');
            }
            if (! Schema::hasIndex('farmers', 'farmers_farm_psgc_index')) {
                $table->index('farm_psgc', 'farmers_farm_psgc_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            if (Schema::hasIndex('farmers', 'farmers_farm_psgc_index')) {
                $table->dropIndex('farmers_farm_psgc_index');
            }
            if (Schema::hasColumn('farmers', 'farm_psgc')) {
                $table->dropColumn('farm_psgc');
            }
        });
    }
};
