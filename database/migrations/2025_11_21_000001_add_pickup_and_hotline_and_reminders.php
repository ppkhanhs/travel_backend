<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tour_schedules', function (Blueprint $table) {
            if (!Schema::hasColumn('tour_schedules', 'pickup_location')) {
                $table->text('pickup_location')->nullable();
            }
            if (!Schema::hasColumn('tour_schedules', 'hotline')) {
                $table->string('hotline', 50)->nullable();
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'reminder_5d_sent_at')) {
                $table->timestamp('reminder_5d_sent_at')->nullable()->after('reminder_sent_at');
            }
            if (!Schema::hasColumn('bookings', 'reminder_2d_sent_at')) {
                $table->timestamp('reminder_2d_sent_at')->nullable()->after('reminder_5d_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tour_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('tour_schedules', 'pickup_location')) {
                $table->dropColumn('pickup_location');
            }
            if (Schema::hasColumn('tour_schedules', 'hotline')) {
                $table->dropColumn('hotline');
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'reminder_5d_sent_at')) {
                $table->dropColumn('reminder_5d_sent_at');
            }
            if (Schema::hasColumn('bookings', 'reminder_2d_sent_at')) {
                $table->dropColumn('reminder_2d_sent_at');
            }
        });
    }
};
