<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (Schema::hasColumn('partners', 'user_id')) {
                DB::statement('ALTER TABLE partners ALTER COLUMN user_id DROP NOT NULL');
            }

            if (!Schema::hasColumn('partners', 'contact_name')) {
                $table->string('contact_name')->nullable()->after('company_name');
            }

            if (!Schema::hasColumn('partners', 'contact_email')) {
                $table->string('contact_email')->nullable()->after('contact_name');
            }

            if (!Schema::hasColumn('partners', 'contact_phone')) {
                $table->string('contact_phone', 30)->nullable()->after('contact_email');
            }

            if (!Schema::hasColumn('partners', 'business_type')) {
                $table->string('business_type')->nullable()->after('contact_phone');
            }

            if (!Schema::hasColumn('partners', 'description')) {
                $table->text('description')->nullable()->after('business_type');
            }

            if (!Schema::hasColumn('partners', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('status');
            }

            if (!Schema::hasColumn('partners', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('approved_at');
            }

            if (!Schema::hasColumn('partners', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $columns = [
                'contact_name',
                'contact_email',
                'contact_phone',
                'business_type',
                'description',
                'approved_at',
                'created_at',
                'updated_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('partners', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('partners', 'user_id')) {
                DB::statement("UPDATE partners SET user_id = NULL WHERE user_id IS NULL");
                DB::statement('ALTER TABLE partners ALTER COLUMN user_id SET NOT NULL');
            }
        });
    }
};

