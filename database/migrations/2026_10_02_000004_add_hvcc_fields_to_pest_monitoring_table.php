<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pest_monitoring', function (Blueprint $table) {
            $table->string('crop_category', 64)->nullable()->after('crop');
            $table->string('hvcc_commodity', 64)->nullable()->after('crop_category');
        });
    }

    public function down(): void
    {
        Schema::table('pest_monitoring', function (Blueprint $table) {
            $table->dropColumn(['crop_category', 'hvcc_commodity']);
        });
    }
};
