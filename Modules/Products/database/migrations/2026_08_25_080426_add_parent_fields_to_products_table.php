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
            // ========== 1. حذف ایندکس‌های قدیمی با مدیریت Foreign Key ==========

            // ابتدا بررسی می‌کنیم که ایندکس وجود داره یا نه
            $conn = Schema::getConnection();
            $dbName = $conn->getDatabaseName();

            // بررسی وجود ایندکس
            $indexExists = $conn->select("
                SELECT COUNT(*) as count 
                FROM information_schema.STATISTICS 
                WHERE TABLE_SCHEMA = ? 
                AND TABLE_NAME = 'products' 
                AND INDEX_NAME = 'products_parent_id_child_type_index'
            ", [$dbName]);

            if ($indexExists[0]->count > 0) {
                // 1.1 حذف constraint خارجی (foreign key)
                try {
                    $table->dropForeign(['parent_id']);
                } catch (\Exception $e) {
                    // اگر constraint وجود نداشت، خطا رو نادیده بگیر
                }

                // 1.2 حذف ایندکس
                try {
                    $table->dropIndex('products_parent_id_child_type_index');
                } catch (\Exception $e) {
                    // اگر ایندکس وجود نداشت، خطا رو نادیده بگیر
                }

                // 1.3 دوباره ایجاد constraint خارجی
                try {
                    $table->foreign('parent_id')
                        ->references('id')
                        ->on('products')
                        ->cascadeOnDelete();
                } catch (\Exception $e) {
                    // اگر constraint قبلاً وجود داشت، خطا رو نادیده بگیر
                }
            }

            // ========== 2. اصلاح نوع فیلدها ==========

            if (Schema::hasColumn('products', 'child_price')) {
                $table->bigInteger('child_price')->nullable()->change();
            } else {
                $table->bigInteger('child_price')->nullable()->after('child_type');
            }

            if (Schema::hasColumn('products', 'child_discount_price')) {
                $table->bigInteger('child_discount_price')->nullable()->change();
            } else {
                $table->bigInteger('child_discount_price')->nullable()->after('child_price');
            }

            // ========== 3. اضافه کردن فیلدهای جدید ==========

            if (!Schema::hasColumn('products', 'child_description')) {
                $table->text('child_description')->nullable()->after('child_discount_price')
                    ->comment('توضیحات مختص این نوع');
            }

            if (!Schema::hasColumn('products', 'child_meta_title')) {
                $table->string('child_meta_title')->nullable()->after('child_description')
                    ->comment('عنوان متا مختص این نوع');
            }

            if (!Schema::hasColumn('products', 'child_meta_description')) {
                $table->text('child_meta_description')->nullable()->after('child_meta_title')
                    ->comment('توضیحات متا مختص این نوع');
            }

            if (!Schema::hasColumn('products', 'child_thumbnail')) {
                $table->string('child_thumbnail')->nullable()->after('child_meta_description')
                    ->comment('تصویر کوچک مختص این نوع');
            }

            if (!Schema::hasColumn('products', 'is_child_free')) {
                $table->boolean('is_child_free')->default(false)->after('child_thumbnail')
                    ->comment('آیا این نوع خاص رایگان است؟');
            }

            if (!Schema::hasColumn('products', 'child_discount_value')) {
                $table->integer('child_discount_value')->nullable()->after('is_child_free')
                    ->comment('مقدار تخفیف مختص این نوع');
            }

            if (!Schema::hasColumn('products', 'child_discount_type')) {
                $table->enum('child_discount_type', ['percent', 'fixed'])->nullable()->after('child_discount_value')
                    ->comment('نوع تخفیف مختص این نوع');
            }

            if (!Schema::hasColumn('products', 'remaining_count')) {
                $table->integer('remaining_count')->nullable()->after('sold_count')
                    ->comment('تعداد باقی‌مانده (محاسبه‌شده)');
            }

            if (!Schema::hasColumn('products', 'registration_deadline')) {
                $table->timestamp('registration_deadline')->nullable()->after('end_date')
                    ->comment('مهلت ثبت‌نام برای این نوع');
            }

            if (!Schema::hasColumn('products', 'show_in_front')) {
                $table->boolean('show_in_front')->default(true)->after('is_variation_active')
                    ->comment('آیا در فرانت نمایش داده شود؟');
            }

            if (!Schema::hasColumn('products', 'display_order')) {
                $table->integer('display_order')->default(0)->after('sort_order')
                    ->comment('ترتیب نمایش در لیست فرزندان');
            }

            if (!Schema::hasColumn('products', 'child_coupon_code')) {
                $table->string('child_coupon_code')->nullable()->after('sku')
                    ->comment('کد تخفیف اختصاصی این نوع');
            }

            // ========== 4. ایجاد ایندکس‌های جدید ==========

            // بررسی اینکه ایندکس وجود نداشته باشه قبل از ایجاد
            $newIndexes = [
                'products_parent_child_active_index' => ['parent_id', 'child_type', 'is_variation_active'],
                'products_kind_status_index' => ['product_kind', 'status'],
                'products_child_type_active_index' => ['child_type', 'is_variation_active'],
                'products_parent_sort_index' => ['parent_id', 'sort_order'],
            ];

            foreach ($newIndexes as $indexName => $columns) {
                try {
                    $table->index($columns, $indexName);
                } catch (\Exception $e) {
                    // اگر ایندکس وجود داشت، خطا رو نادیده بگیر
                }
            }
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
