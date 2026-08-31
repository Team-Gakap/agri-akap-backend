<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the MAO Hybrid/Inbred catalog fields (seed class, item type, and a
     * second simultaneous unit for items like Seed/Abono in kg + bags) to
     * tbl_subsidy_programs. Existing columns become decimal so kg-based rates
     * and stock can carry two decimal places; old integer data casts cleanly.
     */
    public function up(): void
    {
        Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
            $table->enum('seed_class', ['Hybrid', 'Inbred'])->nullable()->after('target_crop');
            $table->enum('item_type', ['seed', 'abono', 'liquid_fertilizer', 'wettable', 'cash'])->nullable()->after('seed_class');
            $table->string('secondary_unit')->nullable()->after('unit_of_measurement');
            $table->decimal('secondary_items_per_hectare', 12, 2)->nullable()->after('items_per_hectare');
            $table->decimal('secondary_total_quantity', 12, 2)->nullable()->after('total_quantity');
            $table->decimal('secondary_remaining_quantity', 12, 2)->nullable()->after('remaining_quantity');
            $table->decimal('secondary_reorder_level', 12, 2)->nullable()->after('reorder_level');
        });

        Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
            $table->decimal('items_per_hectare', 12, 2)->default(0)->change();
            $table->decimal('total_quantity', 12, 2)->default(0)->change();
            $table->decimal('remaining_quantity', 12, 2)->default(0)->change();
            $table->decimal('reorder_level', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
            $table->dropColumn([
                'seed_class',
                'item_type',
                'secondary_unit',
                'secondary_items_per_hectare',
                'secondary_total_quantity',
                'secondary_remaining_quantity',
                'secondary_reorder_level',
            ]);
        });

        Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
            $table->unsignedInteger('items_per_hectare')->default(0)->change();
            $table->unsignedInteger('total_quantity')->default(0)->change();
            $table->unsignedInteger('remaining_quantity')->default(0)->change();
            $table->unsignedInteger('reorder_level')->nullable()->change();
        });
    }
};
