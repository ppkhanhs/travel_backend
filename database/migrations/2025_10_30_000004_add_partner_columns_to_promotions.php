<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            if (!Schema::hasColumn('promotions', 'partner_id')) {
                $table->uuid('partner_id')->nullable()->after('id');
                $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            }

            if (!Schema::hasColumn('promotions', 'tour_id')) {
                $table->uuid('tour_id')->nullable()->after('partner_id');
                $table->foreign('tour_id')->references('id')->on('tours')->cascadeOnDelete();
            }

            if (!Schema::hasColumn('promotions', 'auto_apply')) {
                $table->boolean('auto_apply')->default(false)->after('is_active');
            }

            if (!Schema::hasColumn('promotions', 'description')) {
                $table->text('description')->nullable()->after('code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            if (Schema::hasColumn('promotions', 'auto_apply')) {
                $table->dropColumn('auto_apply');
            }

            if (Schema::hasColumn('promotions', 'tour_id')) {
                $table->dropForeign(['tour_id']);
                $table->dropColumn('tour_id');
            }

            if (Schema::hasColumn('promotions', 'partner_id')) {
                $table->dropForeign(['partner_id']);
                $table->dropColumn('partner_id');
            }

            if (Schema::hasColumn('promotions', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};

