<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_subsidy_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('batch_name');
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('month_year', 7);
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->unsignedInteger('total_sheets')->default(0);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['month_year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_subsidy_import_batches');
    }
};
