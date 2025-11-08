<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('promotion_assignments')) {
            return;
        }

        Schema::create('promotion_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('promotion_id');
            $table->uuid('user_id')->nullable();
            $table->string('voucher_code')->unique();
            $table->enum('status', ['issued', 'redeemed', 'cancelled', 'expired'])->default('issued');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->uuid('booking_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('promotion_id')->references('id')->on('promotions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_assignments');
    }
};

