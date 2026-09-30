<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            $table->enum('registration_type', ['rsbsa', 'manual_walkin'])
                ->default('rsbsa')
                ->after('rsbsa_no');
            $table->boolean('is_temporary')->default(false)->index()->after('registration_type');
            $table->foreignUuid('enlisted_by_user_id')
                ->nullable()
                ->after('is_temporary')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('enlistment_remarks')->nullable()->after('enlisted_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enlisted_by_user_id');
            $table->dropColumn(['registration_type', 'is_temporary', 'enlistment_remarks']);
        });
    }
};
