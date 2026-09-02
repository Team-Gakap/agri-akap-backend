<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'planting_logs',
            'harvest_logs',
            'standing_crop_logs',
            'pest_monitoring',
            'damage_assessments',
            'tbl_subsidy_beneficiaries',
        ] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'planting_logs',
            'harvest_logs',
            'standing_crop_logs',
            'pest_monitoring',
            'damage_assessments',
            'tbl_subsidy_beneficiaries',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropSoftDeletes();
                });
            }
        }
    }
};
