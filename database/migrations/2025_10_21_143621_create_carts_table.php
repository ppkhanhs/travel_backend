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
        Schema::create('carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cart_id');
            $table->uuid('tour_id');
            $table->uuid('schedule_id')->nullable();
            $table->uuid('package_id')->nullable();
            $table->unsignedInteger('adult_quantity')->default(0);
            $table->unsignedInteger('child_quantity')->default(0);
            $table->timestamps();

            $table->foreign('cart_id')
                ->references('id')
                ->on('carts')
                ->cascadeOnDelete();

            $table->foreign('tour_id')
                ->references('id')
                ->on('tours')
                ->cascadeOnDelete();

            $table->foreign('schedule_id')
                ->references('id')
                ->on('tour_schedules')
                ->nullOnDelete();

            $table->foreign('package_id')
                ->references('id')
                ->on('tour_packages')
                ->nullOnDelete();

            $table->unique(
                ['cart_id', 'tour_id', 'schedule_id', 'package_id'],
                'cart_items_unique_cart_tour_schedule_package'
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
