<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tour_schedules', function (Blueprint $table) {
            if (!Schema::hasColumn('tour_schedules', 'departure_location')) {
                $table->text('departure_location')->nullable();
            }
            if (!Schema::hasColumn('tour_schedules', 'departure_time')) {
                $table->string('departure_time', 50)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tour_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('tour_schedules', 'departure_location')) {
                $table->dropColumn('departure_location');
            }
            if (Schema::hasColumn('tour_schedules', 'departure_time')) {
                $table->dropColumn('departure_time');
            }
        });
    }
};
