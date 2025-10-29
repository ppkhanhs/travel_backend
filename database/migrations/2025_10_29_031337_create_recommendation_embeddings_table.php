<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_embeddings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 30);
            $table->uuid('entity_id');
            $table->json('vector');
            $table->json('extra')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_embeddings');
    }
};
