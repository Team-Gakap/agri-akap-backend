<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PCIC claim fields: structured calamity metadata, area destroyed,
     * and notice-of-claim filing audit trail.
     */
    public function up(): void
    {
        Schema::table('damage_assessments', function (Blueprint $table) {
            $table->string('calamity_type')->nullable()->after('calamity_name');
            $table->string('crop_stage')->nullable()->after('calamity_type');
            $table->decimal('area_destroyed_ha', 8, 4)->nullable()->after('crop_stage');
            $table->boolean('is_pcic_notice_filed')->default(false)->after('status');
            $table->timestamp('pcic_notice_filed_at')->nullable()->after('is_pcic_notice_filed');
            $table->foreignUuid('pcic_notice_filed_by')->nullable()->after('pcic_notice_filed_at')
                ->constrained('users')->nullOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE damage_assessments MODIFY COLUMN status ENUM('Pending', 'Verified', 'Approved', 'Rejected', 'Claimed') NOT NULL DEFAULT 'Pending'");
        }
    }

    public function down(): void
    {
        Schema::table('damage_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pcic_notice_filed_by');
            $table->dropColumn([
                'calamity_type',
                'crop_stage',
                'area_destroyed_ha',
                'is_pcic_notice_filed',
                'pcic_notice_filed_at',
            ]);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE damage_assessments MODIFY COLUMN status ENUM('Pending', 'Verified', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending'");
        }
    }
};
