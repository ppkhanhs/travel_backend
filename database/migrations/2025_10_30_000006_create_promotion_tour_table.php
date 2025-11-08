<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('promotion_tour')) {
            return;
        }

        Schema::create('promotion_tour', function (Blueprint $table) {
            $table->uuid('promotion_id');
            $table->uuid('tour_id');
            $table->timestamps();

            $table->primary(['promotion_id', 'tour_id']);
            $table->foreign('promotion_id')->references('id')->on('promotions')->cascadeOnDelete();
            $table->foreign('tour_id')->references('id')->on('tours')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_tour');
    }
};

