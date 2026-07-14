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
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
 
            // هر ویژگی فقط به یک نوع محصول تعلق دارد (مثلاً «نویسنده» فقط برای کتاب)
            $table->foreignId('product_type_id')->constrained()->cascadeOnDelete();
 
            $table->string('name');   // عنوان نمایشی ویژگی: نویسنده، مدت زمان، تعداد سرفصل و ...
            $table->string('slug');    // کلید برای استفاده در کد: author, duration, chapters_count
 
            $table->boolean('is_required')->default(false);
            $table->integer('sort_order')->default(0);
 
            $table->timestamps();
 
            // یک نوع محصول نمی‌تونه دو ویژگی با slug یکسان داشته باشه
            $table->unique(['product_type_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};
