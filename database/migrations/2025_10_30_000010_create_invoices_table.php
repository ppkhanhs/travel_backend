<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id')->unique();
            $table->uuid('partner_id');
            $table->string('invoice_number')->unique();
            $table->string('status', 30)->default('issued');
            $table->string('currency', 10)->default('VND');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('total', 12, 2);
            $table->decimal('vat_rate', 5, 2)->default(10);
            $table->string('customer_name');
            $table->string('customer_tax_code')->nullable();
            $table->string('customer_address')->nullable();
            $table->string('customer_email')->nullable();
            $table->timestamp('issued_at');
            $table->string('file_path');
            $table->json('line_items');
            $table->timestamps();

            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

