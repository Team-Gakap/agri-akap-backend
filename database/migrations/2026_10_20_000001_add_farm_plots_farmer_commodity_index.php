<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farm_plots', function (Blueprint $table) {
            $table->index(['farmer_id', 'commodity'], 'farm_plots_farmer_commodity_index');
        });
    }

    public function down(): void
    {
        Schema::table('farm_plots', function (Blueprint $table) {
            $table->dropIndex('farm_plots_farmer_commodity_index');
        });
    }
};
