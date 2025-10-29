<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_popularities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tour_id');
            $table->unsignedInteger('bookings_count')->default(0);
            $table->unsignedInteger('wishlist_count')->default(0);
            $table->unsignedInteger('views_count')->default(0);
            $table->float('score')->default(0);
            $table->string('window', 20)->default('overall');
            $table->timestamps();

            $table->foreign('tour_id')->references('id')->on('tours')->cascadeOnDelete();
            $table->unique(['tour_id', 'window']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_popularities');
    }
};
