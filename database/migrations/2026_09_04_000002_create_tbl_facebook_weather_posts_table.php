<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_facebook_weather_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('forecast_date');
            $table->string('window', 16);
            $table->text('caption');
            $table->string('image_path');
            $table->string('facebook_post_id')->nullable();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['forecast_date', 'window']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_facebook_weather_posts');
    }
};
