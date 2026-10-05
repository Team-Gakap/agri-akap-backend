<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
            $table->foreignUuid('batch_id')
                ->nullable()
                ->after('id')
                ->constrained('tbl_subsidy_import_batches')
                ->nullOnDelete();
            $table->string('sheet_name', 100)->nullable()->after('program_name');
            $table->enum('source', ['manual', 'regional_import'])->default('manual')->after('sheet_name');
            $table->index(['batch_id', 'sheet_name']);
        });
    }

    public function down(): void
    {
        Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
            $table->dropIndex(['batch_id', 'sheet_name']);
            $table->dropConstrainedForeignId('batch_id');
            $table->dropColumn(['sheet_name', 'source']);
        });
    }
};
