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
        Schema::create('product_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();        // عنوان فایل (می‌تونه خالی باشه - مثلاً وقتی محصول فقط یک فایل بدون عنوان دارد)
            $table->text('description')->nullable();     // توضیحات فایل
            $table->string('path');                       // مسیر فیزیکی/استوریج فایل
            $table->string('original_name')->nullable();  // نام اصلی فایل هنگام آپلود
            $table->string('extension', 20)->nullable();  // پسوند: pdf, mp3, zip, ...
            $table->unsignedBigInteger('size')->nullable(); // حجم فایل به بایت
            $table->boolean('is_free')->default(false)->index(); // دسترسی رایگان مستقل از قیمت محصول
            $table->integer('sort_order')->default(0);
 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_files');
    }
};
