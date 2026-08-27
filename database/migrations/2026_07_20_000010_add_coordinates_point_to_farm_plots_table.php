<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add POINT `coordinates` for spatial collision checks (double-claim prevention)
 * and consolidated `landowner_name`. Keeps latitude/longitude for map consumers.
 *
 * Note: On MariaDB, spatial indexes require NOT NULL geometry columns, so we
 * omit a spatial index and rely on ST_Distance_Sphere for the 15m rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farm_plots', function (Blueprint $table) {
            if (! Schema::hasColumn('farm_plots', 'landowner_name')) {
                $table->string('landowner_name')->nullable()->after('land_owner_ext_name');
            }
        });

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! Schema::hasColumn('farm_plots', 'coordinates')) {
            // MariaDB / MySQL: X = longitude, Y = latitude
            DB::statement('ALTER TABLE farm_plots ADD COLUMN coordinates POINT NULL AFTER longitude');
        }

        DB::statement('
            UPDATE farm_plots
            SET coordinates = POINT(longitude, latitude)
            WHERE latitude IS NOT NULL
              AND longitude IS NOT NULL
              AND coordinates IS NULL
        ');

        DB::statement("
            UPDATE farm_plots
            SET landowner_name = TRIM(CONCAT_WS(' ', land_owner_first_name, land_owner_surname, land_owner_ext_name))
            WHERE landowner_name IS NULL
              AND (land_owner_first_name IS NOT NULL OR land_owner_surname IS NOT NULL)
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('farm_plots', 'coordinates')) {
            DB::statement('ALTER TABLE farm_plots DROP COLUMN coordinates');
        }

        Schema::table('farm_plots', function (Blueprint $table) {
            if (Schema::hasColumn('farm_plots', 'landowner_name')) {
                $table->dropColumn('landowner_name');
            }
        });
    }
};
