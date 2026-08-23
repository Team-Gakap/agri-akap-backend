<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('damage_assessments', function (Blueprint $table) {
            $table->string('variety', 128)->nullable()->after('crop_stage');
        });
    }

    public function down(): void
    {
        Schema::table('damage_assessments', function (Blueprint $table) {
            $table->dropColumn('variety');
        });
    }
};
