<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->uuid('user_id');
            $table->uuid('partner_id');
            $table->string('status', 40)->default('pending_partner');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('VND');
            $table->string('bank_account_name');
            $table->string('bank_account_number');
            $table->string('bank_name');
            $table->string('bank_branch')->nullable();
            $table->text('customer_message')->nullable();
            $table->text('partner_message')->nullable();
            $table->string('proof_url')->nullable();
            $table->timestamp('partner_marked_at')->nullable();
            $table->timestamp('customer_confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};

