<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'created_at')) {
                $table->timestamp('created_at')->useCurrent();
            }

            if (!Schema::hasColumn('bookings', 'updated_at')) {
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'updated_at')) {
                $table->dropColumn('updated_at');
            }

            if (Schema::hasColumn('bookings', 'created_at')) {
                $table->dropColumn('created_at');
            }
        });
    }
};
