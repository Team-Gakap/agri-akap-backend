<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            $table->string('photo_proof_path')->nullable()->after('claimed_by');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            $table->dropColumn('photo_proof_path');
        });
    }
};
