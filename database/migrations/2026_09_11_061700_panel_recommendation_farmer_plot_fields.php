<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            if (! Schema::hasColumn('farmers', 'id_type_other')) {
                $table->string('id_type_other', 150)->nullable()->after('id_type');
            }
            if (! Schema::hasColumn('farmers', 'other_livelihood_type')) {
                $table->string('other_livelihood_type', 100)->nullable()->after('livelihood_detail');
            }
            if (! Schema::hasColumn('farmers', 'other_livelihood_detail')) {
                $table->string('other_livelihood_detail', 150)->nullable()->after('other_livelihood_type');
            }
        });

        Schema::table('farm_plots', function (Blueprint $table) {
            if (! Schema::hasColumn('farm_plots', 'land_owner_middle_name')) {
                $table->string('land_owner_middle_name', 100)->nullable()->after('land_owner_first_name');
            }
            if (! Schema::hasColumn('farm_plots', 'ownership_type_other')) {
                $table->string('ownership_type_other', 150)->nullable()->after('ownership_type');
            }
            if (! Schema::hasColumn('farm_plots', 'proof_of_ownership_other')) {
                $table->string('proof_of_ownership_other', 255)->nullable()->after('proof_of_ownership_document');
            }
            if (! Schema::hasColumn('farm_plots', 'farm_type_other')) {
                $table->string('farm_type_other', 150)->nullable()->after('farm_type');
            }
            if (! Schema::hasColumn('farm_plots', 'rotational_tiller_surname')) {
                $table->string('rotational_tiller_surname', 100)->nullable()->after('rotational_tiller_full_name');
            }
            if (! Schema::hasColumn('farm_plots', 'rotational_tiller_first_name')) {
                $table->string('rotational_tiller_first_name', 100)->nullable()->after('rotational_tiller_surname');
            }
            if (! Schema::hasColumn('farm_plots', 'rotational_tiller_middle_name')) {
                $table->string('rotational_tiller_middle_name', 100)->nullable()->after('rotational_tiller_first_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            foreach (['id_type_other', 'other_livelihood_type', 'other_livelihood_detail'] as $col) {
                if (Schema::hasColumn('farmers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('farm_plots', function (Blueprint $table) {
            foreach ([
                'land_owner_middle_name',
                'ownership_type_other',
                'proof_of_ownership_other',
                'farm_type_other',
                'rotational_tiller_surname',
                'rotational_tiller_first_name',
                'rotational_tiller_middle_name',
            ] as $col) {
                if (Schema::hasColumn('farm_plots', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
