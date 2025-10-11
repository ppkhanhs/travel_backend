<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::create('user_otps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('channel', 20); // email hoặc phone
            $table->string('value');       // địa chỉ email hoặc số điện thoại
            $table->string('otp', 10);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('sent_by')->nullable(); // lưu provider gửi (brevo, twilio, ...)
            $table->ipAddress('ip_address')->nullable(); // IP đã yêu cầu OTP
            $table->timestamps();

            $table->index(['channel', 'value']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_otps');
    }
};
