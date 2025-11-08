<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('booking_promotions')) {
            Schema::table('booking_promotions', function (Blueprint $table) {
                if (!Schema::hasColumn('booking_promotions', 'discount_amount')) {
                    $table->decimal('discount_amount', 12, 2)->default(0);
                }
                if (!Schema::hasColumn('booking_promotions', 'discount_type')) {
                    $table->string('discount_type', 20)->nullable();
                }
                if (!Schema::hasColumn('booking_promotions', 'applied_value')) {
                    $table->decimal('applied_value', 12, 2)->nullable();
                }
            });

            return;
        }

        Schema::create('booking_promotions', function (Blueprint $table) {
            $table->uuid('booking_id');
            $table->uuid('promotion_id');
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_type', 20)->nullable();
            $table->decimal('applied_value', 12, 2)->nullable();

            $table->primary(['booking_id', 'promotion_id']);
            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
            $table->foreign('promotion_id')->references('id')->on('promotions')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_promotions');
    }
};

