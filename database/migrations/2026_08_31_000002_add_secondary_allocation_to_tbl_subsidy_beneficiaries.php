<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Second allocation quantity for beneficiaries of a dual-unit catalog
     * item (e.g. Hybrid Seed: kg on `calculated_allocation`, bags here).
     * `calculated_allocation` becomes decimal to support kg allocations.
     */
    public function up(): void
    {
        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            $table->decimal('calculated_allocation_secondary', 12, 2)->nullable()->after('calculated_allocation');
        });

        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            $table->decimal('calculated_allocation', 12, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            $table->dropColumn('calculated_allocation_secondary');
        });

        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            $table->unsignedInteger('calculated_allocation')->default(0)->change();
        });
    }
};
