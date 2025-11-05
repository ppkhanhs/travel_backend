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
        Schema::table('tour_schedules', function (Blueprint $table) {
            $table->unsignedInteger('min_participants')
                ->nullable()
                ->after('seats_available');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tour_schedules', function (Blueprint $table) {
            $table->dropColumn('min_participants');
        });
    }
};

