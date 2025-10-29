<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tour_id');
            $table->json('features');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->foreign('tour_id')->references('id')->on('tours')->cascadeOnDelete();
            $table->unique('tour_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_features');
    }
};
