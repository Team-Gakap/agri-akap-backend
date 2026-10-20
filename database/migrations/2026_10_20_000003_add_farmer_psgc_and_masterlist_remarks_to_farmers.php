<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            if (! Schema::hasColumn('farmers', 'farmer_psgc')) {
                $table->string('farmer_psgc', 12)->nullable()->after('farm_psgc');
            }
            if (! Schema::hasColumn('farmers', 'masterlist_remarks')) {
                $table->text('masterlist_remarks')->nullable()->after('subsidy_exclusion_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            if (Schema::hasColumn('farmers', 'masterlist_remarks')) {
                $table->dropColumn('masterlist_remarks');
            }
            if (Schema::hasColumn('farmers', 'farmer_psgc')) {
                $table->dropColumn('farmer_psgc');
            }
        });
    }
};
