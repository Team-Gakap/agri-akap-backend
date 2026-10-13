<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            if (! Schema::hasIndex('farmers', 'farmers_mobile_number_index')) {
                $table->index('mobile_number', 'farmers_mobile_number_index');
            }
            if (! Schema::hasIndex('farmers', 'farmers_subsidy_exclusion_reason_index')) {
                $table->index('subsidy_exclusion_reason', 'farmers_subsidy_exclusion_reason_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            if (Schema::hasIndex('farmers', 'farmers_mobile_number_index')) {
                $table->dropIndex('farmers_mobile_number_index');
            }
            if (Schema::hasIndex('farmers', 'farmers_subsidy_exclusion_reason_index')) {
                $table->dropIndex('farmers_subsidy_exclusion_reason_index');
            }
        });
    }
};
