<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop PCIC integration scope.
     * - Remove PCIC notice filing audit fields from `damage_assessments`
     * - Remove the `pcic_enrollments` table
     * - Ensure `damage_assessments.status` reflects LGU-only workflow states
     */
    public function up(): void
    {
        // Normalize legacy PCIC state if any rows exist.
        if (Schema::hasColumn('damage_assessments', 'status')) {
            DB::statement("UPDATE damage_assessments SET status = 'Approved' WHERE status = 'Claimed'");
        }

        // Update status enum to LGU-only states.
        // Keep it compatible even if DB previously had Claimed.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE damage_assessments MODIFY COLUMN status ENUM('Pending', 'Verified', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending'");
        }

        Schema::table('damage_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('damage_assessments', 'pcic_notice_filed_by')) {
                $table->dropConstrainedForeignId('pcic_notice_filed_by');
            }

            if (Schema::hasColumn('damage_assessments', 'is_pcic_notice_filed')) {
                $table->dropColumn('is_pcic_notice_filed');
            }

            if (Schema::hasColumn('damage_assessments', 'pcic_notice_filed_at')) {
                $table->dropColumn('pcic_notice_filed_at');
            }
        });

        Schema::dropIfExists('pcic_enrollments');
    }

    public function down(): void
    {
        // Re-creating the full PCIC scope is out of scope for this cleanup.
        // This down() intentionally no-ops.
    }
};
