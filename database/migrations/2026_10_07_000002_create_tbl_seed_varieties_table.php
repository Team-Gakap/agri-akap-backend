<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_seed_varieties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('variety_name', 120)->unique();
            $table->string('unit', 64)->default('Bags');
            $table->decimal('bags_per_hectare', 10, 4)->default(1);
            $table->decimal('total_quantity', 12, 2)->default(0);
            $table->decimal('remaining_quantity', 12, 2)->default(0);
            $table->decimal('reorder_level', 12, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_seed_varieties');
    }
};
