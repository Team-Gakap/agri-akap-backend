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
            $table->foreignUuid('farmer_id')
                ->nullable()
                ->after('program_id')
                ->constrained('farmers')
                ->nullOnDelete();
            $table->boolean('is_walkin')->default(false)->after('farmer_rsbsa_no');
            $table->foreignUuid('override_by_admin_id')
                ->nullable()
                ->after('photo_proof_path')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('override_timestamp')->nullable()->after('override_by_admin_id');
            $table->text('override_justification')->nullable()->after('override_timestamp');
            $table->string('override_reason_code', 32)->nullable()->after('override_justification');
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE tbl_subsidy_beneficiaries MODIFY farmer_rsbsa_no VARCHAR(255) NULL');
        } else {
            Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
                $table->string('farmer_rsbsa_no')->nullable()->change();
            });
        }

        $pending = DB::table('tbl_subsidy_beneficiaries')
            ->whereNull('farmer_id')
            ->whereNotNull('farmer_rsbsa_no')
            ->get(['id', 'farmer_rsbsa_no']);

        foreach ($pending as $row) {
            $farmer = DB::table('farmers')
                ->where('rsbsa_no', $row->farmer_rsbsa_no)
                ->first(['id', 'is_temporary']);
            if (! $farmer) {
                continue;
            }
            DB::table('tbl_subsidy_beneficiaries')->where('id', $row->id)->update([
                'farmer_id' => $farmer->id,
                'is_walkin' => (bool) $farmer->is_temporary,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('tbl_subsidy_beneficiaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('override_by_admin_id');
            $table->dropConstrainedForeignId('farmer_id');
            $table->dropColumn([
                'is_walkin',
                'override_timestamp',
                'override_justification',
                'override_reason_code',
            ]);
        });
    }
};
