<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Masterlist imports are pre-verified. Promote existing IMP- rows that are still
 * pending. Walk-ins and registry enrollments keep their pending status.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('farmers')
            ->where('verification_status', 'pending')
            ->where('transaction_code', 'like', 'IMP-%')
            ->update([
                'verification_status' => 'approved',
                'verified_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Cannot restore which IMP- rows were pending before this migration.
    }
};
