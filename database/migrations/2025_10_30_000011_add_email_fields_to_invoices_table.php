<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'delivery_method')) {
                $table->string('delivery_method', 20)->default('download')->after('customer_email');
            }

            if (!Schema::hasColumn('invoices', 'emailed_at')) {
                $table->timestamp('emailed_at')->nullable()->after('delivery_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['delivery_method', 'emailed_at']);
        });
    }
};

