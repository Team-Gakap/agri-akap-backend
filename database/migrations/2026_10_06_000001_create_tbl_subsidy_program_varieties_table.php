<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_subsidy_program_varieties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')
                ->constrained('tbl_subsidy_programs')
                ->cascadeOnDelete();
            $table->string('variety_name', 120);
            // Nullable; when null the technician UI inherits the program's unit_of_measurement.
            $table->string('unit', 64)->nullable();
            $table->decimal('total_quantity', 12, 2)->default(0);
            $table->decimal('remaining_quantity', 12, 2)->default(0);
            $table->decimal('reorder_level', 12, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'variety_name'], 'variety_program_name_unique');
            $table->index('program_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_subsidy_program_varieties');
    }
};
