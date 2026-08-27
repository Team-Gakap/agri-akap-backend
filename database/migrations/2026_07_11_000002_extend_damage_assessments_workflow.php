<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extend the damage assessment table into a two-tier validation
     * workflow: Technician files (Pending) -> Barangay Official
     * pre-assesses (Verified) -> MAO Admin finalizes (Approved/Rejected).
     */
    public function up(): void
    {
        Schema::table('damage_assessments', function (Blueprint $table) {
            // Denormalized farmer link so review queues can display the
            // beneficiary without joining through farm_plots.
            $table->foreignUuid('farmer_id')->nullable()->after('farm_plot_id')
                ->constrained('farmers')->nullOnDelete();

            // Barangay Official pre-assessment audit
            $table->foreignUuid('verified_by')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');

            // MAO Admin final decision audit
            $table->foreignUuid('approved_by')->nullable()->after('verified_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            $table->text('remarks')->nullable()->after('approved_at');

            // Offline-first sync metadata
            $table->string('device_id')->nullable()->after('remarks');
        });

        // Expand the status workflow states.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE damage_assessments MODIFY COLUMN status ENUM('Pending', 'Verified', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending'");
        }
    }

    public function down(): void
    {
        Schema::table('damage_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('farmer_id');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['verified_at', 'approved_at', 'remarks', 'device_id']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE damage_assessments MODIFY COLUMN status ENUM('Pending', 'Verified', 'Claimed') NOT NULL DEFAULT 'Pending'");
        }
    }
};
