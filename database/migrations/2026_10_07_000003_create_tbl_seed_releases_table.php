<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_seed_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignUuid('variety_id')->constrained('tbl_seed_varieties')->restrictOnDelete();
            $table->string('farmer_rsbsa_no', 64)->nullable()->index();
            $table->string('farm_barangay', 128)->nullable()->index();
            $table->decimal('farm_area_ha', 10, 4)->default(0);
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 64)->default('Bags');
            $table->uuid('released_by')->nullable()->index();
            $table->timestamp('claimed_at')->useCurrent();
            $table->string('device_id', 128)->nullable();
            $table->decimal('geo_tag_lat', 10, 7)->nullable();
            $table->decimal('geo_tag_long', 10, 7)->nullable();
            $table->string('override_reason', 255)->nullable();
            $table->string('override_reason_code', 64)->nullable();
            $table->text('override_justification')->nullable();
            $table->string('status', 32)->default('Claimed'); // Claimed | Voided
            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['farmer_id', 'variety_id'], 'seed_release_farmer_variety_index');
            $table->index('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_seed_releases');
    }
};
