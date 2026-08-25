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
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('product_type_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->enum('product_kind', ['simple', 'parent', 'child'])
                ->default('simple')
                ->after('parent_id');
            $table->enum('child_type', ['online', 'in_person', 'recorded'])
                ->nullable()
                ->after('product_kind');
            $table->integer('child_price')
                ->nullable()
                ->after('child_type')
                ->comment('قیمت این نوع خاص');

            $table->integer('child_discount_price')
                ->nullable()
                ->after('child_price')
                ->comment('قیمت تخفیف‌خورده این نوع خاص');

            $table->string('meeting_link')
                ->nullable()
                ->after('child_discount_price')
                ->comment('لینک جلسه آنلاین (برای نوع online)');
            $table->string('location')
                ->nullable()
                ->after('meeting_link')
                ->comment('مکان برگزاری حضوری (برای نوع in_person)');
            $table->integer('max_attendees')
                ->nullable()
                ->after('location')
                ->comment('ظرفیت شرکت‌کنندگان حضوری');
            $table->integer('sold_count')
                ->default(0)
                ->after('max_attendees')
                ->comment('تعداد فروش این نوع خاص');
            $table->string('sku')
                ->nullable()
                ->unique()
                ->after('sold_count');
            $table->integer('stock')
                ->nullable()
                ->after('sku')
                ->comment('موجودی (برای نسخه ضبط شده)');
            $table->timestamp('start_date')
                ->nullable()
                ->after('stock');
            $table->timestamp('end_date')
                ->nullable()
                ->after('start_date');
            $table->boolean('is_variation_active')
                ->default(true)
                ->after('end_date');
            $table->integer('sort_order')
                ->default(0)
                ->after('is_variation_active');
            $table->index(['parent_id', 'child_type']);
            $table->index('product_kind');
            $table->index('sku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {});
    }
};
