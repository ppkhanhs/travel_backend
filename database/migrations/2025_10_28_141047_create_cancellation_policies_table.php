<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tour_id');
            $table->unsignedInteger('days_before');
            $table->unsignedDecimal('refund_rate', 5, 2);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->foreign('tour_id')->references('id')->on('tours')->cascadeOnDelete();
            $table->unique(['tour_id', 'days_before']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_policies');
    }
};
