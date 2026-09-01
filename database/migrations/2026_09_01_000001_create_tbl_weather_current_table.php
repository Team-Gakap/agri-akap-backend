<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_weather_current', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('barangay_name')->unique();
            $table->dateTime('observed_at');
            $table->decimal('temperature', 5, 2)->nullable();
            $table->decimal('precipitation', 6, 2)->nullable();
            $table->decimal('rain', 6, 2)->nullable();
            $table->unsignedTinyInteger('precipitation_probability')->nullable();
            $table->decimal('wind_speed', 6, 2)->nullable();
            $table->unsignedSmallInteger('weather_code')->nullable();
            $table->timestamps();

            $table->index('observed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_weather_current');
    }
};
