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
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_attribute_id')->constrained()->cascadeOnDelete();

            $table->string('value')->nullable(); // مقدار رشته‌ای ویژگی برای این محصول

            $table->timestamps();

            // هر محصول برای هر ویژگی فقط یک مقدار دارد
            $table->unique(['product_id', 'product_attribute_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
    }
};
