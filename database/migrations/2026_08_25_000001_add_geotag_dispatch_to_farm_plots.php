<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dispatch unmapped parcels to a technician (or named surveyor) for field
     * geo-tagging, and track pending vs mapped status on each farm plot.
     */
    public function up(): void
    {
        Schema::table('farm_plots', function (Blueprint $table) {
            $table->string('geotag_status', 20)->default('unmapped')->after('has_discrepancy');
            $table->uuid('geotag_assigned_user_id')->nullable()->after('geotag_status');
            $table->string('geotag_assigned_name', 255)->nullable()->after('geotag_assigned_user_id');
            $table->string('geotag_priority', 20)->nullable()->after('geotag_assigned_name');
            $table->text('geotag_notes')->nullable()->after('geotag_priority');
            $table->date('geotag_deadline')->nullable()->after('geotag_notes');

            $table->foreign('geotag_assigned_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->index(['geotag_status', 'geotag_assigned_user_id']);
        });

        DB::table('farm_plots')
            ->where(function ($q) {
                $q->where(function ($g) {
                    $g->whereNotNull('georef_id')->where('georef_id', '!=', '');
                })->orWhereNotNull('boundary_points');
            })
            ->update(['geotag_status' => 'mapped']);
    }

    public function down(): void
    {
        Schema::table('farm_plots', function (Blueprint $table) {
            $table->dropIndex(['geotag_status', 'geotag_assigned_user_id']);
            $table->dropForeign(['geotag_assigned_user_id']);
            $table->dropColumn([
                'geotag_status',
                'geotag_assigned_user_id',
                'geotag_assigned_name',
                'geotag_priority',
                'geotag_notes',
                'geotag_deadline',
            ]);
        });
    }
};
