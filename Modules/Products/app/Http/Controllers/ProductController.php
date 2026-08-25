<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Modules\Products\Models\ProductType;
use Illuminate\Support\Str;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductAttribute;
use Modules\Products\Models\ProductAttributeValue;
use Modules\Products\Models\ProductFile;
use Modules\Products\Models\ProductImage;

class ProductController extends Controller
{
    /**
     * نمایش جزئیات محصول در فرانت (با پشتیبانی از فرزندان)
     */
    public function frontDetail($productId)
    {
        $product = Product::with([
            'productType',
            'images',
            'categories',
            'attributeValues',
            'children' => function ($q) {
                $q->where('is_variation_active', true)
                    ->orderBy('sort_order');
            },
            'children.files',
            'files'
        ])->findOrFail($productId);

        // اگر محصول والد است، اطلاعات فرزندان رو هم برگردون
        if ($product->isParent()) {
            $product->load(['activeChildren.attributeValues', 'activeChildren.files']);
        }

        return response()->json(['data' => $product]);
    }

    /**
     * لیست محصولات برای فرانت (فقط محصولات ساده و والد)
     */
    public function frontIndex(Request $request)
    {
        $query = Product::with(['productType', 'categories', 'images'])
            ->whereIn('product_kind', ['simple', 'parent'])
            ->where('status', 'published');

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

    /**
     * لیست محصولات برای پنل ادمین
     */
    public function index(Request $request)
    {
        $query = Product::with(['productType', 'categories', 'images'])
            ->whereIn('product_kind', ['simple', 'parent']);

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

    /**
     * ذخیره محصول جدید (با پشتیبانی از فرزندان)
     */
    public function store(Request $request)
    {
        // دریافت داده‌های JSON
        $categories = $this->parseJsonInput($request->input('categories', []));
        $attributes = $this->parseJsonInput($request->input('attributes', []));
        $productFiles = $this->parseJsonInput($request->input('product_files', []));
        $children = $this->parseJsonInput($request->input('children', []));

        $request->merge([
            'categories' => $categories,
            'attributes' => $attributes,
            'product_files' => $productFiles,
            'children' => $children
        ]);

        $validated = $request->validate([
            // اطلاعات اصلی
            'product_type_id' => 'required|exists:product_types,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:draft,published,unpublished',
            'product_kind' => 'required|in:simple,parent',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',

            // قیمت و تخفیف (برای محصولات ساده)
            'price' => 'nullable|integer|min:0',
            'discount_value' => 'nullable|integer|min:0',
            'discount_type' => 'nullable|in:percent,fixed',
            'is_free' => 'boolean',

            // دسته‌بندی و ویژگی‌ها
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'attributes' => 'nullable|array',

            // تصاویر و ویدیو
            'images' => 'nullable|array',
            'images.*' => 'file|image|max:5120',
            'video' => 'nullable|file|max:204800|mimes:mp4,avi,mkv,mov',

            // فایل‌های محصول
            'product_files' => 'nullable|array',
            'product_files.*.title' => 'nullable|string|max:255',
            'product_files.*.description' => 'nullable|string',
            'product_files.*.path' => 'required|string|min:10',
            'product_files.*.is_free' => 'nullable|boolean',
            'product_files.*.sort_order' => 'nullable|integer|min:0',

            // ========== اعتبارسنجی فرزندان ==========
            'children' => 'nullable|array',
            'children.*.child_type' => 'required|in:online,in_person,recorded',
            'children.*.child_price' => 'nullable|integer|min:0',
            'children.*.child_discount_price' => 'nullable|integer|min:0',
            'children.*.meeting_link' => 'nullable|string|max:500',
            'children.*.location' => 'nullable|string|max:500',
            'children.*.max_attendees' => 'nullable|integer|min:0',
            'children.*.stock' => 'nullable|integer|min:0',
            'children.*.start_date' => 'nullable|date',
            'children.*.end_date' => 'nullable|date',
            'children.*.is_variation_active' => 'boolean',
            'children.*.sort_order' => 'nullable|integer|min:0',
            'children.*.sku' => 'nullable|string|max:100|unique:products,sku',
        ]);

        DB::beginTransaction();

        try {
            // محاسبه قیمت نهایی برای محصول ساده یا والد
            $finalPrice = $this->calculateFinalPrice(
                $validated['price'] ?? 0,
                $validated['discount_value'] ?? null,
                $validated['discount_type'] ?? null
            );

            // ایجاد محصول اصلی (والد یا ساده)
            $product = Product::create([
                'product_type_id' => $validated['product_type_id'],
                'title' => $validated['title'],
                'slug' => Str::slug($validated['title']),
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'],
                'product_kind' => $validated['product_kind'],
                'price' => $validated['price'] ?? 0,
                'final_price' => $finalPrice,
                'discount_value' => $validated['discount_value'] ?? null,
                'discount_type' => $validated['discount_type'] ?? null,
                'is_free' => $validated['is_free'] ?? false,
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
            ]);

            // ========== ایجاد فرزندان (اگر محصول والد باشد) ==========
            if ($validated['product_kind'] === 'parent' && !empty($validated['children'])) {
                foreach ($validated['children'] as $childData) {
                    $this->createChildProduct($product->id, $childData);
                }
            }

            // ========== مدیریت تصاویر ==========
            $this->handleImages($request, $product);

            // ========== مدیریت ویدیو ==========
            $this->handleVideo($request, $product);

            // ========== مدیریت فایل‌ها ==========
            $this->handleFiles($validated['product_files'] ?? [], $product);

            // ========== دسته‌بندی‌ها ==========
            if (!empty($validated['categories'])) {
                $product->categories()->attach($validated['categories']);
            }

            // ========== ویژگی‌ها ==========
            $this->handleAttributes($validated['attributes'] ?? [], $product);

            DB::commit();

            return response()->json([
                'data' => $product->load([
                    'productType',
                    'categories',
                    'images',
                    'attributeValues',
                    'files',
                    'children'
                ]),
                'message' => 'محصول با موفقیت ایجاد شد'
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'خطا در ایجاد محصول',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * نمایش یک محصول
     */
    public function show(Product $product)
    {
        $product->load([
            'productType',
            'categories',
            'images' => function ($q) {
                $q->orderBy('sort_order');
            },
            'attributeValues',
            'files' => function ($q) {
                $q->orderBy('sort_order');
            },
            'children' => function ($q) {
                $q->orderBy('sort_order');
            },
            'children.files',
            'children.attributeValues'
        ]);

        return response()->json(['data' => $product]);
    }

    /**
     * بروزرسانی محصول
     */
    public function update(Request $request, Product $product)
    {
        $categories = $this->parseJsonInput($request->input('categories', []));
        $attributes = $this->parseJsonInput($request->input('attributes', []));
        $deletedImages = $this->parseJsonInput($request->input('deleted_images', []));
        $deletedFiles = $this->parseJsonInput($request->input('deleted_files', []));
        $updatedFiles = $this->parseJsonInput($request->input('updated_files', []));
        $newFiles = $this->parseJsonInput($request->input('new_files', []));
        $children = $this->parseJsonInput($request->input('children', []));
        $deletedChildren = $this->parseJsonInput($request->input('deleted_children', []));
        $updatedChildren = $this->parseJsonInput($request->input('updated_children', []));

        $request->merge([
            'categories' => $categories,
            'attributes' => $attributes,
            'deleted_images' => $deletedImages,
            'deleted_files' => $deletedFiles,
            'updated_files' => $updatedFiles,
            'new_files' => $newFiles,
            'children' => $children,
            'deleted_children' => $deletedChildren,
            'updated_children' => $updatedChildren
        ]);

        if ($request->has('delete_video')) {
            $deleteVideo = filter_var($request->delete_video, FILTER_VALIDATE_BOOLEAN);
            $request->merge(['delete_video' => $deleteVideo]);
        }

        $validated = $request->validate([
            // اطلاعات اصلی
            'product_type_id' => 'required|exists:product_types,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:draft,published,unpublished',
            'product_kind' => 'required|in:simple,parent',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',

            // قیمت و تخفیف
            'price' => 'nullable|integer|min:0',
            'discount_value' => 'nullable|integer|min:0',
            'discount_type' => 'nullable|in:percent,fixed',
            'is_free' => 'boolean',

            // دسته‌بندی و ویژگی‌ها
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'attributes' => 'nullable|array',

            // تصاویر
            'images' => 'nullable|array',
            'images.*' => 'file|image|max:5120',
            'deleted_images' => 'nullable|array',
            'deleted_images.*' => 'exists:product_images,id',

            // ویدیو
            'video' => 'nullable|file|max:204800|mimes:mp4,avi,mkv,mov',
            'delete_video' => 'nullable|boolean',

            // فایل‌های موجود
            'updated_files' => 'nullable|array',
            'updated_files.*.id' => 'required|exists:product_files,id',
            'updated_files.*.title' => 'nullable|string|max:255',
            'updated_files.*.path' => 'nullable|string|max:500',
            'updated_files.*.is_free' => 'nullable|boolean',
            'updated_files.*.sort_order' => 'nullable|integer|min:0',

            // فایل‌های جدید
            'new_files' => 'nullable|array',
            'new_files.*.title' => 'nullable|string|max:255',
            'new_files.*.path' => 'required|string|max:500',
            'new_files.*.is_free' => 'nullable|boolean',
            'new_files.*.sort_order' => 'nullable|integer|min:0',

            // فایل‌های حذف شده
            'deleted_files' => 'nullable|array',
            'deleted_files.*' => 'exists:product_files,id',

            // ========== اعتبارسنجی فرزندان ==========
            'children' => 'nullable|array',
            'children.*.child_type' => 'required|in:online,in_person,recorded',
            'children.*.child_price' => 'nullable|integer|min:0',
            'children.*.child_discount_price' => 'nullable|integer|min:0',
            'children.*.meeting_link' => 'nullable|string|max:500',
            'children.*.location' => 'nullable|string|max:500',
            'children.*.max_attendees' => 'nullable|integer|min:0',
            'children.*.stock' => 'nullable|integer|min:0',
            'children.*.start_date' => 'nullable|date',
            'children.*.end_date' => 'nullable|date',
            'children.*.is_variation_active' => 'boolean',
            'children.*.sort_order' => 'nullable|integer|min:0',
            'children.*.sku' => 'nullable|string|max:100',

            // بروزرسانی فرزندان موجود
            'updated_children' => 'nullable|array',
            'updated_children.*.id' => 'required|exists:products,id',
            'updated_children.*.child_type' => 'required|in:online,in_person,recorded',
            'updated_children.*.child_price' => 'nullable|integer|min:0',
            'updated_children.*.child_discount_price' => 'nullable|integer|min:0',
            'updated_children.*.meeting_link' => 'nullable|string|max:500',
            'updated_children.*.location' => 'nullable|string|max:500',
            'updated_children.*.max_attendees' => 'nullable|integer|min:0',
            'updated_children.*.stock' => 'nullable|integer|min:0',
            'updated_children.*.start_date' => 'nullable|date',
            'updated_children.*.end_date' => 'nullable|date',
            'updated_children.*.is_variation_active' => 'boolean',
            'updated_children.*.sort_order' => 'nullable|integer|min:0',

            // حذف فرزندان
            'deleted_children' => 'nullable|array',
            'deleted_children.*' => 'exists:products,id',
        ]);

        DB::beginTransaction();

        try {
            // محاسبه قیمت نهایی
            $finalPrice = $this->calculateFinalPrice(
                $validated['price'] ?? 0,
                $validated['discount_value'] ?? null,
                $validated['discount_type'] ?? null
            );

            // بروزرسانی محصول اصلی
            $product->update([
                'product_type_id' => $validated['product_type_id'],
                'title' => $validated['title'],
                'slug' => Str::slug($validated['title']),
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'],
                'product_kind' => $validated['product_kind'],
                'price' => $validated['price'] ?? 0,
                'final_price' => $finalPrice,
                'discount_value' => $validated['discount_value'] ?? null,
                'discount_type' => $validated['discount_type'] ?? null,
                'is_free' => $validated['is_free'] ?? false,
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
            ]);

            // ========== مدیریت فرزندان ==========
            if ($validated['product_kind'] === 'parent') {
                // ۱. حذف فرزندان
                if (!empty($validated['deleted_children'])) {
                    $childrenToDelete = Product::whereIn('id', $validated['deleted_children'])
                        ->where('parent_id', $product->id)
                        ->get();

                    foreach ($childrenToDelete as $child) {
                        // حذف فایل‌های مرتبط
                        foreach ($child->files as $file) {
                            Storage::disk('public')->delete($file->path);
                            $file->delete();
                        }
                        $child->delete();
                    }
                }

                // ۲. بروزرسانی فرزندان موجود
                if (!empty($validated['updated_children'])) {
                    foreach ($validated['updated_children'] as $childData) {
                        $child = Product::where('id', $childData['id'])
                            ->where('parent_id', $product->id)
                            ->first();

                        if ($child) {
                            $child->update([
                                'child_type' => $childData['child_type'],
                                'child_price' => $childData['child_price'] ?? null,
                                'child_discount_price' => $childData['child_discount_price'] ?? null,
                                'meeting_link' => $childData['meeting_link'] ?? null,
                                'location' => $childData['location'] ?? null,
                                'max_attendees' => $childData['max_attendees'] ?? null,
                                'stock' => $childData['stock'] ?? null,
                                'start_date' => $childData['start_date'] ?? null,
                                'end_date' => $childData['end_date'] ?? null,
                                'is_variation_active' => $childData['is_variation_active'] ?? true,
                                'sort_order' => $childData['sort_order'] ?? 0,
                                'sku' => $childData['sku'] ?? null,
                                'status' => $product->status, // همگام با والد
                            ]);
                        }
                    }
                }

                // ۳. ایجاد فرزندان جدید
                if (!empty($validated['children'])) {
                    foreach ($validated['children'] as $childData) {
                        $this->createChildProduct($product->id, $childData, $product->status);
                    }
                }
            }

            // ========== مدیریت تصاویر ==========
            $this->handleImages($request, $product, $validated['deleted_images'] ?? []);

            // ========== مدیریت ویدیو ==========
            $this->handleVideo($request, $product, $validated['delete_video'] ?? false);

            // ========== مدیریت فایل‌ها ==========
            $this->handleFilesUpdate(
                $product,
                $validated['updated_files'] ?? [],
                $validated['new_files'] ?? [],
                $validated['deleted_files'] ?? []
            );

            // ========== بروزرسانی دسته‌بندی‌ها ==========
            if (isset($validated['categories'])) {
                $product->categories()->sync($validated['categories']);
            }

            // ========== بروزرسانی ویژگی‌ها ==========
            $this->handleAttributesUpdate($validated['attributes'] ?? [], $product);

            DB::commit();

            return response()->json([
                'data' => $product->load([
                    'productType',
                    'categories',
                    'images',
                    'attributeValues',
                    'files',
                    'children'
                ]),
                'message' => 'محصول با موفقیت بروزرسانی شد'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'خطا در بروزرسانی محصول',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * حذف محصول
     */
    public function destroy(Product $product)
    {
        DB::beginTransaction();

        try {
            // حذف فرزندان (اگر والد باشد)
            if ($product->isParent()) {
                foreach ($product->children as $child) {
                    foreach ($child->files as $file) {
                        Storage::disk('public')->delete($file->path);
                    }
                    $child->delete();
                }
            }

            // حذف تصاویر
            foreach ($product->images as $image) {
                Storage::disk('public')->delete($image->path);
            }

            // حذف فایل‌ها
            foreach ($product->files as $file) {
                Storage::disk('public')->delete($file->path);
            }

            // حذف ویدیو
            if ($product->video) {
                Storage::disk('public')->delete($product->video);
            }

            $product->delete();

            DB::commit();

            return response()->json(['message' => 'محصول حذف شد']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'خطا در حذف محصول',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ========== متدهای کمکی ==========

    /**
     * پارس کردن ورودی JSON
     */
    private function parseJsonInput($input)
    {
        if (is_string($input)) {
            return json_decode($input, true) ?? [];
        }
        return $input ?? [];
    }

    /**
     * محاسبه قیمت نهایی
     */
    private function calculateFinalPrice($price, $discountValue, $discountType)
    {
        $finalPrice = $price ?? 0;
        if (!empty($discountValue) && $discountValue > 0) {
            if ($discountType === 'percent') {
                $finalPrice = $price - ($price * $discountValue / 100);
            } else {
                $finalPrice = $price - $discountValue;
            }
            $finalPrice = max(0, $finalPrice);
        }
        return $finalPrice;
    }

    /**
     * ایجاد محصول فرزند
     */
    private function createChildProduct($parentId, $childData, $parentStatus = 'published')
    {
        // اگر اسلاگ تکراری نباشد
        $slug = $childData['sku'] ?? Str::slug($childData['child_type'] . '-' . uniqid());

        return Product::create([
            'parent_id' => $parentId,
            'product_type_id' => null, // فرزند از والد می‌گیرد
            'product_kind' => 'child',
            'child_type' => $childData['child_type'],
            'title' => $childData['title'] ?? $this->getChildTypeLabel($childData['child_type']),
            'slug' => $slug,
            'description' => $childData['description'] ?? null,
            'status' => $parentStatus,
            'child_price' => $childData['child_price'] ?? null,
            'child_discount_price' => $childData['child_discount_price'] ?? null,
            'meeting_link' => $childData['meeting_link'] ?? null,
            'location' => $childData['location'] ?? null,
            'max_attendees' => $childData['max_attendees'] ?? null,
            'stock' => $childData['stock'] ?? null,
            'start_date' => $childData['start_date'] ?? null,
            'end_date' => $childData['end_date'] ?? null,
            'is_variation_active' => $childData['is_variation_active'] ?? true,
            'sort_order' => $childData['sort_order'] ?? 0,
            'sku' => $childData['sku'] ?? null,
            'is_free' => ($childData['child_price'] ?? 0) == 0,
            'price' => 0,
            'final_price' => 0,
        ]);
    }

    /**
     * دریافت برچسب فارسی نوع فرزند
     */
    private function getChildTypeLabel($type)
    {
        $labels = [
            'online' => 'نسخه آنلاین',
            'in_person' => 'نسخه حضوری',
            'recorded' => 'نسخه ضبط شده',
        ];
        return $labels[$type] ?? $type;
    }

    /**
     * مدیریت تصاویر
     */
    private function handleImages($request, $product, $deletedImageIds = [])
    {
        // حذف تصاویر
        if (!empty($deletedImageIds)) {
            $imagesToDelete = ProductImage::whereIn('id', $deletedImageIds)
                ->where('product_id', $product->id)
                ->get();

            foreach ($imagesToDelete as $image) {
                Storage::disk('public')->delete($image->path);
                $image->delete();
            }
        }

        // آپلود تصاویر جدید
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

        // مدیریت main_image
        $remainingImages = $product->images()->orderBy('sort_order')->get();

        if ($remainingImages->count() > 0) {
            $firstImage = $remainingImages->first();
            if ($product->main_image !== $firstImage->path) {
                $product->update(['main_image' => $firstImage->path]);
            }
        } else {
            $product->update(['main_image' => null]);
        }
    }

    /**
     * مدیریت ویدیو
     */
    private function handleVideo($request, $product, $deleteVideo = false)
    {
        if ($deleteVideo) {
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
    }

    /**
     * مدیریت فایل‌ها (ذخیره)
     */
    private function handleFiles($files, $product)
    {
        if (!empty($files)) {
            foreach ($files as $index => $fileData) {
                ProductFile::create([
                    'product_id' => $product->id,
                    'title' => $fileData['title'] ?? 'فایل بدون عنوان',
                    'description' => $fileData['description'] ?? null,
                    'path' => $fileData['path'],
                    'original_name' => basename($fileData['path']),
                    'extension' => pathinfo($fileData['path'], PATHINFO_EXTENSION),
                    'size' => 0,
                    'is_free' => $fileData['is_free'] ?? false,
                    'sort_order' => $fileData['sort_order'] ?? $index,
                ]);
            }
        }
    }

    /**
     * مدیریت فایل‌ها (بروزرسانی)
     */
    private function handleFilesUpdate($product, $updatedFiles, $newFiles, $deletedFileIds)
    {
        // حذف فایل‌ها
        if (!empty($deletedFileIds)) {
            $filesToDelete = ProductFile::whereIn('id', $deletedFileIds)
                ->where('product_id', $product->id)
                ->get();

            foreach ($filesToDelete as $file) {
                Storage::disk('public')->delete($file->path);
                $file->delete();
            }
        }

        // ویرایش فایل‌های موجود
        if (!empty($updatedFiles)) {
            foreach ($updatedFiles as $fileData) {
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

        // افزودن فایل‌های جدید
        if (!empty($newFiles)) {
            foreach ($newFiles as $fileData) {
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
    }

    /**
     * مدیریت ویژگی‌ها (ذخیره)
     */
    private function handleAttributes($attributes, $product)
    {
        if (!empty($attributes)) {
            foreach ($attributes as $attrId => $value) {
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

    /**
     * مدیریت ویژگی‌ها (بروزرسانی)
     */
    private function handleAttributesUpdate($attributes, $product)
    {
        if (isset($attributes)) {
            foreach ($attributes as $attrId => $value) {
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
    }

    // ========== متدهای دیگر (بدون تغییر) ==========

    public function frontProductType()
    {
        $types = ProductType::latest()->get();
        return response()->json(['data' => $types]);
    }

    public function getAttributes(ProductType $productType)
    {
        $attributes = ProductAttribute::where('product_type_id', $productType->id)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $attributes]);
    }

    public function toggleStatus(Product $product)
    {
        $statuses = ['draft', 'published', 'unpublished'];
        $currentIndex = array_search($product->status, $statuses);
        $nextIndex = ($currentIndex + 1) % count($statuses);

        $product->update(['status' => $statuses[$nextIndex]]);

        // اگر محصول والد است، وضعیت فرزندان را هم تغییر بده
        if ($product->isParent()) {
            $product->children()->update(['status' => $statuses[$nextIndex]]);
        }

        return response()->json([
            'data' => $product,
            'message' => 'وضعیت محصول تغییر کرد'
        ]);
    }
}
