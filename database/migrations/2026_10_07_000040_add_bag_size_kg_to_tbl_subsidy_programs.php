<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_subsidy_programs')) {
            return;
        }

        Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_subsidy_programs', 'bag_size_kg')) {
                $table->decimal('bag_size_kg', 8, 2)->nullable()->after('secondary_items_per_hectare');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_subsidy_programs')) {
            return;
        }

        Schema::table('tbl_subsidy_programs', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_subsidy_programs', 'bag_size_kg')) {
                $table->dropColumn('bag_size_kg');
            }
        });
    }
};
