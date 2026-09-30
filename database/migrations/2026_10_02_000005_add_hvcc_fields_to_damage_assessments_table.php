<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('damage_assessments', function (Blueprint $table) {
            $table->string('crop_category', 64)->nullable()->after('crop_stage');
            $table->string('hvcc_commodity', 64)->nullable()->after('crop_category');
            $table->unsignedInteger('num_hills_trees')->nullable()->after('area_planted_ha');
        });
    }

    public function down(): void
    {
        Schema::table('damage_assessments', function (Blueprint $table) {
            $table->dropColumn(['crop_category', 'hvcc_commodity', 'num_hills_trees']);
        });
    }
};
