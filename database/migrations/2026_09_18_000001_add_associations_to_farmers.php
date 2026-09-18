<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('farmers', 'associations')) {
            Schema::table('farmers', function (Blueprint $table) {
                $table->json('associations')->nullable()->after('association_3');
            });
        }

        // Preserve existing registrations by making their three legacy entries
        // the initial values of the new unbounded list.
        DB::table('farmers')->whereNull('associations')->orderBy('id')->each(function ($farmer) {
            $values = array_values(array_filter([
                $farmer->association_1,
                $farmer->association_2,
                $farmer->association_3,
            ], fn ($value) => is_string($value) && trim($value) !== ''));

            DB::table('farmers')->where('id', $farmer->id)->update([
                'associations' => json_encode($values, JSON_THROW_ON_ERROR),
            ]);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('farmers', 'associations')) {
            Schema::table('farmers', function (Blueprint $table) {
                $table->dropColumn('associations');
            });
        }
    }
};
