<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            $table->foreignUuid('claimed_by')
                ->nullable()
                ->after('claimed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claimed_by');
        });
    }
};
