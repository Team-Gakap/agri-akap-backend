<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable()->after('password_changed_at');
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_secret');
            $table->json('mfa_recovery_codes')->nullable()->after('mfa_confirmed_at');
            $table->string('mobile_number', 20)->nullable()->after('email');
        });

        Schema::create('tbl_mfa_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('device_name');
            $table->text('pending_secret')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('sms_code_hash')->nullable();
            $table->dateTime('sms_sent_at')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_mfa_challenges');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_confirmed_at', 'mfa_recovery_codes', 'mobile_number']);
        });
    }
};
