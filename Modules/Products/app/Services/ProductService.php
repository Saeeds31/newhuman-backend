<?php

namespace Modules\Products\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductFile;
use Modules\Products\Models\ProductImage;

class ProductService
{
    /**
     * ایجاد محصول جدید به همراه تصاویر، فایل‌ها، ویژگی‌ها و دسته‌بندی‌ها
     */
    public function create(array $data): Product
    {
        $product = Product::create($this->extractProductFields($data));

        $this->syncMainImage($product, $data);
        $this->syncImages($product, $data['images'] ?? []);
        $this->syncFiles($product, $data['files'] ?? []);
        $this->syncAttributes($product, $data['attributes'] ?? []);
        $this->syncCategories($product, $data['category_ids'] ?? []);

        $this->updateFinalPrice($product);

        return $product->fresh(['images', 'files', 'attributeValues', 'categories', 'type']);
    }

    /**
     * ویرایش محصول موجود
     */
    public function update(Product $product, array $data): Product
    {
        $product->update($this->extractProductFields($data));

        $this->syncMainImage($product, $data);
        $this->syncImages($product, $data['images'] ?? []);
        $this->syncFiles($product, $data['files'] ?? []);
        $this->syncAttributes($product, $data['attributes'] ?? []);
        $this->syncCategories($product, $data['category_ids'] ?? null);

        $this->updateFinalPrice($product);

        return $product->fresh(['images', 'files', 'attributeValues', 'categories', 'type']);
    }

    /**
     * فقط فیلدهای ستونی خود جدول products را برمی‌گرداند
     */
    private function extractProductFields(array $data): array
    {
        return collect($data)->only([
            'product_type_id',
            'title',
            'description',
            'meta_title',
            'meta_description',
            'status',
            'price',
            'discount_value',
            'discount_type',
            'is_free',
            'video',
        ])->toArray();
    }

    /**
     * آپلود/جایگزینی تصویر اصلی شاخص محصول
     */
    private function syncMainImage(Product $product, array $data): void
    {
        if (! isset($data['main_image']) || ! $data['main_image'] instanceof UploadedFile) {
            return;
        }

        // حذف تصویر قبلی از استوریج، در صورت وجود
        if ($product->main_image) {
            Storage::disk('public')->delete($product->main_image);
        }

        $path = $data['main_image']->store('products/main', 'public');
        $product->update(['main_image' => $path]);
    }

    /**
     * مدیریت گالری تصاویر: آپلود جدید، ویرایش متادیتای موجود
     *
     * هر آیتم: ['id' => nullable, 'file' => UploadedFile|null, 'alt' => string|null, 'sort_order' => int|null]
     */
    private function syncImages(Product $product, array $images): void
    {
        foreach ($images as $item) {
            if (! empty($item['id'])) {
                $image = ProductImage::where('product_id', $product->id)->find($item['id']);

                if (! $image) {
                    continue;
                }

                if (! empty($item['file']) && $item['file'] instanceof UploadedFile) {
                    Storage::disk('public')->delete($image->path);
                    $image->path = $item['file']->store('products/images', 'public');
                }

                $image->alt = $item['alt'] ?? $image->alt;
                $image->sort_order = $item['sort_order'] ?? $image->sort_order;
                $image->save();

                continue;
            }

            if (empty($item['file']) || ! $item['file'] instanceof UploadedFile) {
                continue;
            }

            $path = $item['file']->store('products/images', 'public');

            ProductImage::create([
                'product_id' => $product->id,
                'path' => $path,
                'alt' => $item['alt'] ?? null,
                'sort_order' => $item['sort_order'] ?? 0,
            ]);
        }
    }

    /**
     * مدیریت فایل‌های دانلودی محصول: آپلود جدید، ویرایش متادیتای موجود
     *
     * هر آیتم: ['id' => nullable, 'file' => UploadedFile|null, 'title', 'description', 'is_free', 'sort_order']
     */
    private function syncFiles(Product $product, array $files): void
    {
        foreach ($files as $item) {
            if (! empty($item['id'])) {
                $file = ProductFile::where('product_id', $product->id)->find($item['id']);

                if (! $file) {
                    continue;
                }

                if (! empty($item['file']) && $item['file'] instanceof UploadedFile) {
                    Storage::disk('local')->delete($file->path);
                    $uploaded = $item['file'];
                    $file->path = $uploaded->store('products/files', 'local');
                    $file->original_name = $uploaded->getClientOriginalName();
                    $file->extension = $uploaded->getClientOriginalExtension();
                    $file->size = $uploaded->getSize();
                }

                $file->title = $item['title'] ?? $file->title;
                $file->description = $item['description'] ?? $file->description;
                $file->is_free = $item['is_free'] ?? $file->is_free;
                $file->sort_order = $item['sort_order'] ?? $file->sort_order;
                $file->save();

                continue;
            }

            if (empty($item['file']) || ! $item['file'] instanceof UploadedFile) {
                continue;
            }

            $uploaded = $item['file'];

            // فایل‌های دانلودی روی دیسک local ذخیره می‌شوند (نه public) چون دسترسی باید کنترل‌شده باشد
            $path = $uploaded->store('products/files', 'local');

            ProductFile::create([
                'product_id' => $product->id,
                'title' => $item['title'] ?? null,
                'description' => $item['description'] ?? null,
                'path' => $path,
                'original_name' => $uploaded->getClientOriginalName(),
                'extension' => $uploaded->getClientOriginalExtension(),
                'size' => $uploaded->getSize(),
                'is_free' => $item['is_free'] ?? false,
                'sort_order' => $item['sort_order'] ?? 0,
            ]);
        }
    }

    /**
     * ست‌کردن مقدار ویژگی‌های نوع‌محور
     *
     * $attributes به شکل: [attribute_id => value, ...]
     */
    private function syncAttributes(Product $product, array $attributes): void
    {
        foreach ($attributes as $attributeId => $value) {
            if (blank($value)) {
                continue;
            }

            $product->attributeValues()->updateOrCreate(
                ['product_attribute_id' => $attributeId],
                ['value' => $value]
            );
        }
    }

    /**
     * sync دسته‌بندی‌های محصول. اگر null باشد (در update) دسته‌بندی‌ها دست‌نخورده باقی می‌مانند.
     */
    private function syncCategories(Product $product, ?array $categoryIds): void
    {
        if ($categoryIds === null) {
            return;
        }

        $product->categories()->sync($categoryIds);
    }

    /**
     * محاسبه و ذخیره قیمت نهایی بر اساس تخفیف
     */
    private function updateFinalPrice(Product $product): void
    {
        $finalPrice = $product->price;

        if ($product->discount_value) {
            $finalPrice = $product->discount_type === 'percent'
                ? (int) round($product->price - ($product->price * $product->discount_value / 100))
                : (int) max(0, $product->price - $product->discount_value);
        }

        $product->update(['final_price' => $finalPrice]);
    }

    /**
     * حذف کامل محصول و فایل‌های مرتبط از استوریج
     */
    public function delete(Product $product): void
    {
        if ($product->main_image) {
            Storage::disk('public')->delete($product->main_image);
        }

        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->path);
        }

        foreach ($product->files as $file) {
            Storage::disk('local')->delete($file->path);
        }

        $product->delete();
    }
}