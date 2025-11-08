<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (!Schema::hasColumn('partners', 'invoice_company_name')) {
                $table->string('invoice_company_name')->nullable()->after('company_name');
            }

            if (!Schema::hasColumn('partners', 'invoice_tax_code')) {
                $table->string('invoice_tax_code')->nullable()->after('invoice_company_name');
            }

            if (!Schema::hasColumn('partners', 'invoice_address')) {
                $table->string('invoice_address')->nullable()->after('invoice_tax_code');
            }

            if (!Schema::hasColumn('partners', 'invoice_email')) {
                $table->string('invoice_email')->nullable()->after('invoice_address');
            }

            if (!Schema::hasColumn('partners', 'invoice_vat_rate')) {
                $table->decimal('invoice_vat_rate', 5, 2)->default(10)->after('invoice_email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_company_name',
                'invoice_tax_code',
                'invoice_address',
                'invoice_email',
                'invoice_vat_rate',
            ]);
        });
    }
};

