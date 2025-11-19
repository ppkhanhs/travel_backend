<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'reminder_sent_at')) {
                $table->timestamp('reminder_sent_at')->nullable()->after('notes');
            }

            if (!Schema::hasColumn('bookings', 'review_notified_at')) {
                $table->timestamp('review_notified_at')->nullable()->after('reminder_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'reminder_sent_at')) {
                $table->dropColumn('reminder_sent_at');
            }

            if (Schema::hasColumn('bookings', 'review_notified_at')) {
                $table->dropColumn('review_notified_at');
            }
        });
    }
};
