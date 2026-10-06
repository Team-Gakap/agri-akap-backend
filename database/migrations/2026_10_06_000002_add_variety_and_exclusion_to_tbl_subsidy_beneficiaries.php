<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            // Which specific variety (e.g. LP 937, JACKPOT) was handed to this farmer.
            // Null for legacy rows and for beneficiaries in programs without variety breakdown.
            $table->foreignUuid('variety_id')
                ->nullable()
                ->after('program_id')
                ->constrained('tbl_subsidy_program_varieties')
                ->nullOnDelete();

            // Stored when status = Excluded; contains the normalised remark keyword
            // (e.g. "DECEASED", "OFW", "NOFARM", "INACTIVE") from the masterlist upload.
            $table->string('exclusion_reason', 128)->nullable()->after('selection_mode');
        });

        // Extend the status enum to include 'Excluded'.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE tbl_subsidy_beneficiaries MODIFY COLUMN status ENUM('Pending', 'Waitlisted', 'Claimed', 'Excluded') NOT NULL DEFAULT 'Pending'");
        }
    }

    public function down(): void
    {
        // Revert status enum (drop Excluded rows first to avoid constraint errors).
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('tbl_subsidy_beneficiaries')->where('status', 'Excluded')->delete();
            DB::statement("ALTER TABLE tbl_subsidy_beneficiaries MODIFY COLUMN status ENUM('Pending', 'Waitlisted', 'Claimed') NOT NULL DEFAULT 'Pending'");
        }

        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variety_id');
            $table->dropColumn('exclusion_reason');
        });
    }
};
