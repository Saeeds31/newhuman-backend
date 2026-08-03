<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('discourses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('discourse_with');
            $table->string('video');
            $table->string('main_image');
            $table->text('short_description');
            $table->text('description');
            $table->foreignId('discourse_category_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discourse');
    }
};
