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
use Modules\Products\Models\ProductFile;
use Modules\Products\Models\ProductImage;

class ProductController extends Controller
{
    public function frontDetail($productId)
    {
        $product = Product::with(['productType', 'images', 'categories', 'attributeValues', 'isFree'])->findOrFail($productId);
        return response()->json(['data' => $product]);
    }
    public function frontProductType()
    {
        $types = ProductType::latest()->get();
        return response()->json(['data' => $types]);
    }
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
    public function frontIndex(Request $request)
    {
        $query = Product::with(['productType', 'categories', 'images']);

        if ($search = $request->get('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        if ($request->filled('product_type_id')) {
            $query->where('product_type_id', $request->product_type_id);
        }
        if ($request->filled('category_ids')) {
            $categoryIds = explode(',', $request->category_ids);
            $query->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('categories.id', $categoryIds);
            });
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
        $productFiles = $request->input('product_files', []);

        if (is_string($categories)) {
            $categories = json_decode($categories, true) ?? [];
        }

        if (is_string($attributes)) {
            $attributes = json_decode($attributes, true) ?? [];
        }

        if (is_string($productFiles)) {
            $productFiles = json_decode($productFiles, true) ?? [];
        }

        $request->merge([
            'categories' => $categories,
            'attributes' => $attributes,
            'product_files' => $productFiles
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
            'product_files' => 'nullable|array',
            'product_files.*.title' => 'nullable|string|max:255',
            'product_files.*.description' => 'nullable|string',
            'product_files.*.path' => 'required|string|min:10', // 100MB
            'product_files.*.is_free' => 'nullable|boolean',
            'product_files.*.sort_order' => 'nullable|integer|min:0',
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
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products/images', 'public');
                if ($index === 0) {
                    $product->update(['main_image' => $path]);
                } else {
                    ProductImage::create([
                        'product_id' => $product->id,
                        'path' => $path,
                        'alt' => $request->alt ?? null,
                        'sort_order' => $index
                    ]);
                }
            }
        }

        // آپلود ویدیو
        if ($request->hasFile('video')) {
            $videoPath = $request->file('video')->store('products/videos', 'public');
            $product->update(['video' => $videoPath]);
        }

        // ========== آپلود فایل‌های محصول ==========
        if (!empty($validated['product_files'])) {
            foreach ($validated['product_files'] as $index => $fileData) {
                // فقط آدرس ذخیره میشه
                ProductFile::create([
                    'product_id' => $product->id,
                    'title' => $fileData['title'] ?? 'فایل بدون عنوان',
                    'description' => $fileData['description'] ?? null,
                    'path' => $fileData['path'], // فقط آدرس
                    'original_name' => basename($fileData['path']),
                    'extension' => pathinfo($fileData['path'], PATHINFO_EXTENSION),
                    'size' => 0, // چون فایل آپلود نمیشه
                    'is_free' => $fileData['is_free'] ?? false,
                    'sort_order' => $fileData['sort_order'] ?? $index,
                ]);
            }
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
            'data' => $product->load(['productType', 'categories', 'images', 'attributeValues', 'files']),
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
                'attributeValues',
                'files' => function ($q) {
                    $q->orderBy('sort_order');
                }
            ])
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $categories = $request->input('categories', []);
        $attributes = $request->input('attributes', []);
        $deletedImages = $request->input('deleted_images', []);
        $deletedFiles = $request->input('deleted_files', []);
        $updatedFiles = $request->input('updated_files', []);
        $newFiles = $request->input('new_files', []);
    
        if (is_string($categories)) {
            $categories = json_decode($categories, true) ?? [];
        }
    
        if (is_string($attributes)) {
            $attributes = json_decode($attributes, true) ?? [];
        }
    
        if (is_string($deletedImages)) {
            $deletedImages = json_decode($deletedImages, true) ?? [];
        }
    
        if (is_string($deletedFiles)) {
            $deletedFiles = json_decode($deletedFiles, true) ?? [];
        }
    
        if (is_string($updatedFiles)) {
            $updatedFiles = json_decode($updatedFiles, true) ?? [];
        }
    
        if (is_string($newFiles)) {
            $newFiles = json_decode($newFiles, true) ?? [];
        }
    
        if ($request->has('delete_video')) {
            $deleteVideo = filter_var($request->delete_video, FILTER_VALIDATE_BOOLEAN);
            $request->merge(['delete_video' => $deleteVideo]);
        }
    
        $request->merge([
            'categories' => $categories,
            'attributes' => $attributes,
            'deleted_images' => $deletedImages,
            'deleted_files' => $deletedFiles,
            'updated_files' => $updatedFiles,
            'new_files' => $newFiles
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
            'delete_video' => 'nullable|boolean',
            'deleted_files' => 'nullable|array',
            'deleted_files.*' => 'exists:product_files,id',
            'updated_files' => 'nullable|array',
            'updated_files.*.id' => 'required|exists:product_files,id',
            'updated_files.*.title' => 'nullable|string|max:255',
            'updated_files.*.path' => 'nullable|string|max:500',
            'updated_files.*.is_free' => 'nullable|boolean',
            'updated_files.*.sort_order' => 'nullable|integer|min:0',
            'new_files' => 'nullable|array',
            'new_files.*.title' => 'nullable|string|max:255',
            'new_files.*.path' => 'required|string|max:500',
            'new_files.*.is_free' => 'nullable|boolean',
            'new_files.*.sort_order' => 'nullable|integer|min:0',
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
                Storage::disk('public')->delete($image->path);
                $image->delete();
            }
        }
    
        // ۲. آپلود تصاویر جدید
        if ($request->hasFile('images')) {
            $lastSortOrder = $product->images()->max('sort_order') ?? -1;
    
            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products/images', 'public');
    
                ProductImage::create([
                    'product_id' => $product->id,
                    'path' => $path,
                    'alt' => $request->alt ?? null,
                    'sort_order' => $lastSortOrder + $index + 1
                ]);
            }
        }
    
        // ۳. مدیریت main_image
        $remainingImages = $product->images()->orderBy('sort_order')->get();
    
        if ($remainingImages->count() > 0) {
            $firstImage = $remainingImages->first();
            if ($product->main_image !== $firstImage->path) {
                $product->update(['main_image' => $firstImage->path]);
            }
        } else {
            $product->update(['main_image' => null]);
        }
    
        // ========== مدیریت ویدیو ==========
    
        if ($request->has('delete_video') && $request->delete_video == true) {
            if ($product->video) {
                Storage::disk('public')->delete($product->video);
                $product->update(['video' => null]);
            }
        }
    
        if ($request->hasFile('video')) {
            if ($product->video) {
                Storage::disk('public')->delete($product->video);
            }
            $videoPath = $request->file('video')->store('products/videos', 'public');
            $product->update(['video' => $videoPath]);
        }
    
        // ========== مدیریت فایل‌های محصول ==========
    
        // ۱. حذف فایل‌های انتخاب شده
        if (!empty($validated['deleted_files'])) {
            $filesToDelete = ProductFile::whereIn('id', $validated['deleted_files'])
                ->where('product_id', $product->id)
                ->get();
    
            foreach ($filesToDelete as $file) {
                Storage::disk('public')->delete($file->path);
                $file->delete();
            }
        }
    
        // ۲. ویرایش فایل‌های موجود
        if (!empty($validated['updated_files'])) {
            foreach ($validated['updated_files'] as $fileData) {
                $existingFile = ProductFile::where('id', $fileData['id'])
                    ->where('product_id', $product->id)
                    ->first();
    
                if ($existingFile) {
                    $existingFile->update([
                        'title' => $fileData['title'] ?? $existingFile->title,
                        'path' => $fileData['path'] ?? $existingFile->path,
                        'is_free' => $fileData['is_free'] ?? $existingFile->is_free,
                        'sort_order' => $fileData['sort_order'] ?? $existingFile->sort_order,
                    ]);
                }
            }
        }
    
        // ۳. افزودن فایل‌های جدید
        if (!empty($validated['new_files'])) {
            foreach ($validated['new_files'] as $fileData) {
                ProductFile::create([
                    'product_id' => $product->id,
                    'title' => $fileData['title'] ?? 'فایل بدون عنوان',
                    'description' => $fileData['description'] ?? null,
                    'path' => $fileData['path'],
                    'original_name' => basename($fileData['path']),
                    'extension' => pathinfo($fileData['path'], PATHINFO_EXTENSION),
                    'size' => 0,
                    'is_free' => $fileData['is_free'] ?? false,
                    'sort_order' => $fileData['sort_order'] ?? 0,
                ]);
            }
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
            'data' => $product->load(['productType', 'categories', 'images', 'attributeValues', 'files']),
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
