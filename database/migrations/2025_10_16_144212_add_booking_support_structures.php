<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tour_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('adult_price', 12, 2);
            $table->decimal('child_price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tour_id')->references('id')->on('tours')->cascadeOnDelete();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->uuid('package_id')->nullable()->after('tour_schedule_id');
            $table->unsignedInteger('total_adults')->default(0)->after('total_price');
            $table->unsignedInteger('total_children')->default(0)->after('total_adults');
            $table->string('contact_name')->nullable()->after('total_children');
            $table->string('contact_email')->nullable()->after('contact_name');
            $table->string('contact_phone')->nullable()->after('contact_email');
            $table->text('notes')->nullable()->after('contact_phone');

            $table->foreign('package_id')->references('id')->on('tour_packages')->nullOnDelete();
        });

        Schema::create('booking_passengers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->string('type', 20); // adult | child
            $table->string('full_name');
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('document_number')->nullable();
            $table->timestamps();

            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_passengers');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropColumn([
                'package_id',
                'total_adults',
                'total_children',
                'contact_name',
                'contact_email',
                'contact_phone',
                'notes',
            ]);
        });

        Schema::dropIfExists('tour_packages');
    }
};
