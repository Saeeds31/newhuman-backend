<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('episode_id', 100)->index();
            $table->boolean('is_completed')->default(false);
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->timestamp('last_watched_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'product_id', 'episode_id'], 'user_product_episode_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};