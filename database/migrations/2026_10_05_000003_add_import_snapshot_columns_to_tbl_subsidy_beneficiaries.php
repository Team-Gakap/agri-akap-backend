<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            $table->foreignUuid('batch_id')
                ->nullable()
                ->after('program_id')
                ->constrained('tbl_subsidy_import_batches')
                ->nullOnDelete();
            $table->decimal('source_farm_area', 10, 4)->nullable()->after('calculated_allocation_secondary');
            $table->string('source_commodity', 64)->nullable()->after('source_farm_area');
            $table->string('source_farmer_barangay', 128)->nullable()->after('source_commodity');
            $table->string('source_farmer_municipality', 128)->nullable()->after('source_farmer_barangay');
            $table->string('source_farm_barangay', 128)->nullable()->after('source_farmer_municipality');
            $table->string('source_farm_municipality', 128)->nullable()->after('source_farm_barangay');
            $table->index(['batch_id', 'farmer_rsbsa_no']);
        });
    }

    public function down(): void
    {
        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            $table->dropIndex(['batch_id', 'farmer_rsbsa_no']);
            $table->dropConstrainedForeignId('batch_id');
            $table->dropColumn([
                'source_farm_area',
                'source_commodity',
                'source_farmer_barangay',
                'source_farmer_municipality',
                'source_farm_barangay',
                'source_farm_municipality',
            ]);
        });
    }
};
