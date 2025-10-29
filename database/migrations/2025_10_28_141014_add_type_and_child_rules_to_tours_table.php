<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            $table->string('type', 20)->default('domestic')->after('destination');
            $table->unsignedTinyInteger('child_age_limit')->default(12)->after('type');
            $table->boolean('requires_passport')->default(false)->after('child_age_limit');
            $table->boolean('requires_visa')->default(false)->after('requires_passport');
        });
    }

    public function down(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'child_age_limit',
                'requires_passport',
                'requires_visa',
            ]);
        });
    }
};
