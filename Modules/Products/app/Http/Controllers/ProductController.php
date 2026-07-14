<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Products\Models\ProductType;
use Illuminate\Support\Str;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductAttribute;
use Modules\Products\Models\ProductAttributeValue;
use Modules\Products\Models\ProductImage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['productType', 'categories', 'images']);

        if ($search = $request->get('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        if ($request->filled('product_type_id')) {
            $query->where('product_type_id', $request->product_type_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('is_free')) {
            $query->where('is_free', $request->is_free);
        }

        $products = $query->orderBy('id', 'desc')->paginate(20);

        return response()->json(['data' => $products]);
    }

    // ذخیره محصول
    public function store(Request $request)
    {
        $categories = $request->input('categories', []);
        $attributes = $request->input('attributes', []);

        // اگر string هست، تبدیل به آرایه کن
        if (is_string($categories)) {
            $categories = json_decode($categories, true) ?? [];
        }

        if (is_string($attributes)) {
            $attributes = json_decode($attributes, true) ?? [];
        }

        // اضافه کردن به request برای ولیدیشن
        $request->merge([
            'categories' => $categories,
            'attributes' => $attributes
        ]);
        $validated = $request->validate([
            'product_type_id' => 'required|exists:product_types,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:draft,published,unpublished',
            'price' => 'nullable|integer|min:0',
            'discount_value' => 'nullable|integer|min:0',
            'discount_type' => 'nullable|in:percent,fixed',
            'is_free' => 'boolean',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'attributes' => 'nullable|array',
            'images' => 'nullable|array',
            'images.*' => 'file|image|max:5120',
            'video' => 'nullable|file|max:204800|mimes:mp4,avi,mkv,mov',
        ]);

        // محاسبه قیمت نهایی
        $finalPrice = $validated['price'] ?? 0;
        if (!empty($validated['discount_value']) && $validated['discount_value'] > 0) {
            if ($validated['discount_type'] === 'percent') {
                $finalPrice = $validated['price'] - ($validated['price'] * $validated['discount_value'] / 100);
            } else {
                $finalPrice = $validated['price'] - $validated['discount_value'];
            }
            $finalPrice = max(0, $finalPrice);
        }

        // ایجاد محصول
        $product = Product::create([
            'product_type_id' => $validated['product_type_id'],
            'title' => $validated['title'],
            'slug' => Str::slug($validated['title']),
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
            'price' => $validated['price'] ?? 0,
            'final_price' => $finalPrice,
            'discount_value' => $validated['discount_value'] ?? null,
            'discount_type' => $validated['discount_type'] ?? null,
            'is_free' => $validated['is_free'] ?? false,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
        ]);

        // آپلود تصاویر
        $imagePaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products', 'public');
                if ($index === 0) {
                    $product->update(['main_image' => $path]);
                } else {
                    ProductImage::create([
                        'product_id' => $product->id,
                        'path' => $path,
                        'alt' => $request->alt ?? null,
                        'sort_order' => $index
                    ]);
                    $imagePaths[] = $path;
                }
            }
        }

        // آپلود ویدیو
        if ($request->hasFile('video')) {
            $videoPath = $request->file('video')->store('products/videos', 'public');
            $product->update(['video' => $videoPath]);
        }

        // ذخیره دسته‌بندی‌ها
        if (!empty($validated['categories'])) {
            $product->categories()->attach($validated['categories']);
        }

        // ذخیره ویژگی‌ها
        if (!empty($validated['attributes'])) {
            foreach ($validated['attributes'] as $attrId => $value) {
                if (!empty($value)) {
                    ProductAttributeValue::create([
                        'product_id' => $product->id,
                        'product_attribute_id' => $attrId,
                        'value' => $value
                    ]);
                }
            }
        }
        return response()->json([
            'data' => $product->load(['productType', 'categories', 'images', 'attributeValues']),
            'message' => 'محصول با موفقیت ایجاد شد'
        ], 201);
    }
    // نمایش یک محصول
    public function show(Product $product)
    {
        return response()->json([
            'data' => $product->load([
                'productType',
                'categories',
                'images' => function ($q) {
                    $q->orderBy('sort_order');
                },
                'attributeValues'
            ])
        ]);
    }

    public function update(Request $request, Product $product)
    {
        // دریافت داده‌ها
        $categories = $request->input('categories', []);
        $attributes = $request->input('attributes', []);
        $deletedImages = $request->input('deleted_images', []);

        if (is_string($categories)) {
            $categories = json_decode($categories, true) ?? [];
        }

        if (is_string($attributes)) {
            $attributes = json_decode($attributes, true) ?? [];
        }

        if (is_string($deletedImages)) {
            $deletedImages = json_decode($deletedImages, true) ?? [];
        }

        $request->merge([
            'categories' => $categories,
            'attributes' => $attributes,
            'deleted_images' => $deletedImages
        ]);

        $validated = $request->validate([
            'product_type_id' => 'required|exists:product_types,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:draft,published,unpublished',
            'price' => 'nullable|integer|min:0',
            'discount_value' => 'nullable|integer|min:0',
            'discount_type' => 'nullable|in:percent,fixed',
            'is_free' => 'boolean',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'attributes' => 'nullable|array',
            'images' => 'nullable|array',
            'images.*' => 'file|image|max:5120',
            'deleted_images' => 'nullable|array',
            'deleted_images.*' => 'exists:product_images,id',
            'video' => 'nullable|file|max:204800|mimes:mp4,avi,mkv,mov',
            'delete_video' => 'nullable|in:true,false,1,0,on,off',
        ]);
        if ($request->has('delete_video')) {
            $deleteVideo = filter_var($request->delete_video, FILTER_VALIDATE_BOOLEAN);
            $request->merge(['delete_video' => $deleteVideo]);
        }
        // محاسبه قیمت نهایی
        $finalPrice = $validated['price'] ?? 0;
        if (!empty($validated['discount_value']) && $validated['discount_value'] > 0) {
            if ($validated['discount_type'] === 'percent') {
                $finalPrice = $validated['price'] - ($validated['price'] * $validated['discount_value'] / 100);
            } else {
                $finalPrice = $validated['price'] - $validated['discount_value'];
            }
            $finalPrice = max(0, $finalPrice);
        }

        // بروزرسانی اطلاعات اصلی
        $product->update([
            'product_type_id' => $validated['product_type_id'],
            'title' => $validated['title'],
            'slug' => Str::slug($validated['title']),
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
            'price' => $validated['price'] ?? 0,
            'final_price' => $finalPrice,
            'discount_value' => $validated['discount_value'] ?? null,
            'discount_type' => $validated['discount_type'] ?? null,
            'is_free' => $validated['is_free'] ?? false,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
        ]);

        // ========== مدیریت تصاویر ==========

        // ۱. حذف تصاویر انتخاب شده
        if (!empty($validated['deleted_images'])) {
            $imagesToDelete = ProductImage::whereIn('id', $validated['deleted_images'])
                ->where('product_id', $product->id)
                ->get();

            foreach ($imagesToDelete as $image) {
                // حذف فایل
                Storage::disk('public')->delete($image->path);
                // حذف رکورد
                $image->delete();
            }
        }

        // ۲. آپلود تصاویر جدید
        $newImagePaths = [];
        if ($request->hasFile('images')) {
            // دریافت آخرین sort_order موجود
            $lastSortOrder = $product->images()->max('sort_order') ?? -1;

            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products', 'public');

                ProductImage::create([
                    'product_id' => $product->id,
                    'path' => $path,
                    'alt' => $request->alt ?? null,
                    'sort_order' => $lastSortOrder + $index + 1
                ]);

                $newImagePaths[] = $path;
            }
        }

        // ۳. مدیریت main_image
        // اگر تصاویر باقی مونده داریم
        $remainingImages = $product->images()->orderBy('sort_order')->get();

        if ($remainingImages->count() > 0) {
            // اگر main_image فعلی حذف شده یا اصلی‌ترین تصویر نیست
            $firstImage = $remainingImages->first();
            if ($product->main_image !== $firstImage->path) {
                $product->update(['main_image' => $firstImage->path]);
            }
        } else {
            // اگر هیچ تصویری باقی نمونده
            $product->update(['main_image' => null]);
        }

        // ========== مدیریت ویدیو ==========

        // حذف ویدیو
        if ($request->has('delete_video') && $request->delete_video == true) {
            if ($product->video) {
                Storage::disk('public')->delete($product->video);
                $product->update(['video' => null]);
            }
        }

        // آپلود ویدیو جدید
        if ($request->hasFile('video')) {
            // حذف ویدیو قدیمی
            if ($product->video) {
                Storage::disk('public')->delete($product->video);
            }
            $videoPath = $request->file('video')->store('products/videos', 'public');
            $product->update(['video' => $videoPath]);
        }

        // ========== بروزرسانی دسته‌بندی‌ها ==========
        if (isset($validated['categories'])) {
            $product->categories()->sync($validated['categories']);
        }

        // ========== بروزرسانی ویژگی‌ها ==========
        if (isset($validated['attributes'])) {
            foreach ($validated['attributes'] as $attrId => $value) {
                $attributeValue = ProductAttributeValue::where([
                    'product_id' => $product->id,
                    'product_attribute_id' => $attrId
                ])->first();

                if ($attributeValue) {
                    if (!empty($value)) {
                        $attributeValue->update(['value' => $value]);
                    } else {
                        $attributeValue->delete();
                    }
                } else {
                    if (!empty($value)) {
                        ProductAttributeValue::create([
                            'product_id' => $product->id,
                            'product_attribute_id' => $attrId,
                            'value' => $value
                        ]);
                    }
                }
            }
        }

        return response()->json([
            'data' => $product->load(['productType', 'categories', 'images', 'attributeValues']),
            'message' => 'محصول با موفقیت بروزرسانی شد'
        ]);
    }

    // حذف محصول
    public function destroy(Product $product)
    {
        // حذف تصاویر
        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->path);
        }

        // حذف ویدیو
        if ($product->video) {
            Storage::disk('public')->delete($product->video);
        }

        $product->delete();
        return response()->json(['message' => 'محصول حذف شد']);
    }

    // دریافت ویژگی‌های یک نوع محصول
    public function getAttributes(ProductType $productType)
    {
        $attributes = ProductAttribute::where('product_type_id', $productType->id)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $attributes]);
    }

    // تغییر وضعیت
    public function toggleStatus(Product $product)
    {
        $statuses = ['draft', 'published', 'unpublished'];
        $currentIndex = array_search($product->status, $statuses);
        $nextIndex = ($currentIndex + 1) % count($statuses);

        $product->update(['status' => $statuses[$nextIndex]]);

        return response()->json([
            'data' => $product,
            'message' => 'وضعیت محصول تغییر کرد'
        ]);
    }
}
