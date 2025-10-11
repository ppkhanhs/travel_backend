<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestampTz('email_verified_at')->nullable()->after('email');
            });
        }

        if (!Schema::hasColumn('users', 'phone_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestampTz('phone_verified_at')->nullable()->after('phone');
            });
        }

        $this->setPasswordNullable(true);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('email_verified_at');
            });
        }

        if (Schema::hasColumn('users', 'phone_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('phone_verified_at');
            });
        }

        $this->setPasswordNullable(false);
    }

    /**
     * Thiết lập cột password là nullable hay bắt buộc tùy theo driver DB.
     */
    private function setPasswordNullable(bool $nullable): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                $nullable
                    ? 'ALTER TABLE users ALTER COLUMN password DROP NOT NULL'
                    : 'ALTER TABLE users ALTER COLUMN password SET NOT NULL'
            );
        } elseif ($driver === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE `users` MODIFY `password` VARCHAR(255) %s',
                $nullable ? 'NULL' : 'NOT NULL'
            ));
        } elseif ($driver === 'sqlsrv') {
            DB::statement(sprintf(
                'ALTER TABLE [users] ALTER COLUMN [password] NVARCHAR(255) %s',
                $nullable ? 'NULL' : 'NOT NULL'
            ));
        } else {
            // sqlite và các driver khác không hỗ trợ alter column dễ dàng -> bỏ qua
        }
    }
};
