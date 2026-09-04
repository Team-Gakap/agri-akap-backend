<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_weather_cache', function (Blueprint $table) {
            $table->decimal('precipitation_sum', 8, 2)
                ->nullable()
                ->after('precipitation_probability');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_weather_cache', function (Blueprint $table) {
            $table->dropColumn('precipitation_sum');
        });
    }
};
