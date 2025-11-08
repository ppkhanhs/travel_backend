<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            if (!Schema::hasColumn('promotions', 'type')) {
                $table->string('type', 30)->default('voucher')->after('partner_id');
            }

            if (!Schema::hasColumn('promotions', 'auto_issue_on_cancel')) {
                $table->boolean('auto_issue_on_cancel')->default(false)->after('auto_apply');
            }
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            if (Schema::hasColumn('promotions', 'auto_issue_on_cancel')) {
                $table->dropColumn('auto_issue_on_cancel');
            }

            if (Schema::hasColumn('promotions', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};

