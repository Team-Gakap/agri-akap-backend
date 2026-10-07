<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MAO field-release fields mapped onto Capstone tables:
 * - programs  = campaign / season header (delivery window)
 * - varieties = inventory items (optional FCA / barangay pre-assignment)
 * - beneficiaries (Claimed) = release transactions (drop-off, FCA, offline hash)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_subsidy_programs', 'delivery_start_date')) {
                $table->date('delivery_start_date')->nullable()->after('status');
            }
            if (! Schema::hasColumn('tbl_subsidy_programs', 'delivery_end_date')) {
                $table->date('delivery_end_date')->nullable()->after('delivery_start_date');
            }
        });

        Schema::table('tbl_subsidy_program_varieties', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_subsidy_program_varieties', 'target_fca')) {
                $table->string('target_fca', 150)->nullable()->after('variety_name');
            }
            if (! Schema::hasColumn('tbl_subsidy_program_varieties', 'target_barangays')) {
                $table->json('target_barangays')->nullable()->after('target_fca');
            }
        });

        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_subsidy_beneficiaries', 'drop_off_point')) {
                $table->string('drop_off_point', 150)->nullable()->after('source_farm_municipality');
            }
            if (! Schema::hasColumn('tbl_subsidy_beneficiaries', 'fca_name')) {
                $table->string('fca_name', 150)->nullable()->after('drop_off_point');
            }
            if (! Schema::hasColumn('tbl_subsidy_beneficiaries', 'offline_sync_hash')) {
                $table->string('offline_sync_hash', 64)->nullable()->unique()->after('fca_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_subsidy_beneficiaries', 'offline_sync_hash')) {
                $table->dropUnique(['offline_sync_hash']);
                $table->dropColumn('offline_sync_hash');
            }
            if (Schema::hasColumn('tbl_subsidy_beneficiaries', 'fca_name')) {
                $table->dropColumn('fca_name');
            }
            if (Schema::hasColumn('tbl_subsidy_beneficiaries', 'drop_off_point')) {
                $table->dropColumn('drop_off_point');
            }
        });

        Schema::table('tbl_subsidy_program_varieties', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_subsidy_program_varieties', 'target_barangays')) {
                $table->dropColumn('target_barangays');
            }
            if (Schema::hasColumn('tbl_subsidy_program_varieties', 'target_fca')) {
                $table->dropColumn('target_fca');
            }
        });

        Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_subsidy_programs', 'delivery_end_date')) {
                $table->dropColumn('delivery_end_date');
            }
            if (Schema::hasColumn('tbl_subsidy_programs', 'delivery_start_date')) {
                $table->dropColumn('delivery_start_date');
            }
        });
    }
};
