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
     * نمایش جزئیات محصول در فرانت
     * اگر محصول والد باشد، فرزندانش را نمایش می‌دهد
     */
    public function frontDetail($productId)
    {
        $product = Product::with([
            'productType',
            'images',
            'categories',
            'attributeValues',
            'files'
        ])->findOrFail($productId);

        // اگر محصول والد است، فرزندان فعال را بارگذاری کن
        if ($product->isParent()) {
            $product->load([
                'activeChildren' => function ($q) {
                    $q->with(['images', 'files', 'attributeValues', 'productType'])
                        ->where('status', 'published');
                }
            ]);
        }

        return response()->json(['data' => $product]);
    }

    /**
     * لیست محصولات برای فرانت
     * فقط محصولات ساده و فرزندان را نمایش می‌دهد
     * والدها را نمایش نمی‌دهد
     */
    public function frontIndex(Request $request)
    {
        $query = Product::with(['productType', 'categories', 'images'])
            ->whereIn('product_kind', ['simple', 'child'])
            ->where('status', 'published')
            ->where('show_in_front', true);

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
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

        // فیلتر بر اساس نوع فرزند
        if ($request->filled('child_type')) {
            $query->where('child_type', $request->child_type);
        }

        // فیلتر بر اساس parent_id (برای نمایش فرزندان یک والد خاص)
        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        }

        // فیلتر قیمت
        if ($request->filled('min_price')) {
            $query->where(function ($q) use ($request) {
                $q->where('child_price', '>=', $request->min_price)
                    ->orWhere('price', '>=', $request->min_price);
            });
        }

        if ($request->filled('max_price')) {
            $query->where(function ($q) use ($request) {
                $q->where('child_price', '<=', $request->max_price)
                    ->orWhere('price', '<=', $request->max_price);
            });
        }

        // مرتب‌سازی
        $sortBy = $request->get('sort_by', 'id');
        $sortOrder = $request->get('sort_order', 'desc');

        $allowedSorts = ['id', 'title', 'price', 'child_price', 'created_at', 'sold_count'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('id', 'desc');
        }

        $products = $query->paginate($request->get('per_page', 20));

        return response()->json(['data' => $products]);
    }

    /**
     * لیست محصولات برای پنل ادمین
     * همه محصولات را نمایش می‌دهد
     */
    public function index(Request $request)
    {
        $query = Product::with(['productType', 'categories', 'images', 'parent']);

        if ($search = $request->get('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        if ($request->filled('product_type_id')) {
            $query->where('product_type_id', $request->product_type_id);
        }

        if ($request->filled('product_kind')) {
            $query->where('product_kind', $request->product_kind);
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $products = $query->orderBy('id', 'desc')->paginate(20);

        return response()->json(['data' => $products]);
    }

    /**
     * دریافت لیست فرزندان یک محصول والد
     */
    public function getChildren(Product $product)
    {
        if (!$product->isParent()) {
            return response()->json([
                'message' => 'این محصول والد نیست'
            ], 400);
        }

        $children = $product->children()
            ->with(['images', 'files', 'attributeValues'])
            ->get();

        return response()->json(['data' => $children]);
    }

    /**
     * ذخیره محصول جدید
     */
    public function store(Request $request)
    {
        $categories = $this->parseJsonInput($request->input('categories', []));
        $attributes = $this->parseJsonInput($request->input('attributes', []));
        $productFiles = $this->parseJsonInput($request->input('product_files', []));

        $request->merge([
            'categories' => $categories,
            'attributes' => $attributes,
            'product_files' => $productFiles
        ]);

        $validated = $request->validate([
            // اطلاعات اصلی
            'product_type_id' => 'required|exists:product_types,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:draft,published,unpublished',
            'product_kind' => 'required|in:simple,parent,child',
            'parent_id' => 'nullable|exists:products,id',
            'child_type' => 'nullable|in:online,in_person,recorded',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',

            // قیمت برای محصولات ساده
            'price' => 'nullable|integer|min:0',
            'discount_value' => 'nullable|integer|min:0',
            'discount_type' => 'nullable|in:percent,fixed',
            'is_free' => 'boolean',

            // قیمت برای فرزندان
            'child_price' => 'nullable|integer|min:0',
            'child_discount_price' => 'nullable|integer|min:0',

            // فیلدهای اختصاصی فرزندان
            'child_description' => 'nullable|string',
            'child_meta_title' => 'nullable|string|max:255',
            'child_meta_description' => 'nullable|string|max:500',
            'child_thumbnail' => 'nullable|string|max:500',
            'is_child_free' => 'boolean',
            'child_discount_value' => 'nullable|integer|min:0',
            'child_discount_type' => 'nullable|in:percent,fixed',
            'meeting_link' => 'nullable|string|max:500',
            'location' => 'nullable|string|max:500',
            'max_attendees' => 'nullable|integer|min:0',
            'stock' => 'nullable|integer|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'registration_deadline' => 'nullable|date',
            'is_variation_active' => 'boolean',
            'show_in_front' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
            'display_order' => 'nullable|integer|min:0',
            'sku' => 'nullable|string|max:100|unique:products,sku',
            'child_coupon_code' => 'nullable|string|max:50',

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
        ]);

        // اعتبارسنجی شرطی برای فرزندان
        if ($validated['product_kind'] === 'child') {
            if (empty($validated['parent_id'])) {
                return response()->json([
                    'errors' => ['parent_id' => ['برای محصولات فرزند، والد الزامی است']]
                ], 422);
            }

            if (empty($validated['child_type'])) {
                return response()->json([
                    'errors' => ['child_type' => ['برای محصولات فرزند، نوع الزامی است']]
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            // محاسبه قیمت نهایی برای محصول ساده
            $finalPrice = $this->calculateFinalPrice(
                $validated['price'] ?? 0,
                $validated['discount_value'] ?? null,
                $validated['discount_type'] ?? null
            );

            // ایجاد محصول
            $product = Product::create([
                'product_type_id' => $validated['product_type_id'],
                'title' => $validated['title'],
                'slug' => Str::slug($validated['title'] . '-' . uniqid()),
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'],
                'product_kind' => $validated['product_kind'],
                'parent_id' => $validated['parent_id'] ?? null,
                'child_type' => $validated['child_type'] ?? null,
                'price' => $validated['price'] ?? 0,
                'final_price' => $finalPrice,
                'discount_value' => $validated['discount_value'] ?? null,
                'discount_type' => $validated['discount_type'] ?? null,
                'is_free' => $validated['is_free'] ?? false,
                'child_price' => $validated['child_price'] ?? null,
                'child_discount_price' => $validated['child_discount_price'] ?? null,
                'child_description' => $validated['child_description'] ?? null,
                'child_meta_title' => $validated['child_meta_title'] ?? null,
                'child_meta_description' => $validated['child_meta_description'] ?? null,
                'child_thumbnail' => $validated['child_thumbnail'] ?? null,
                'is_child_free' => $validated['is_child_free'] ?? false,
                'child_discount_value' => $validated['child_discount_value'] ?? null,
                'child_discount_type' => $validated['child_discount_type'] ?? null,
                'meeting_link' => $validated['meeting_link'] ?? null,
                'location' => $validated['location'] ?? null,
                'max_attendees' => $validated['max_attendees'] ?? null,
                'stock' => $validated['stock'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'registration_deadline' => $validated['registration_deadline'] ?? null,
                'is_variation_active' => $validated['is_variation_active'] ?? true,
                'show_in_front' => $validated['show_in_front'] ?? true,
                'sort_order' => $validated['sort_order'] ?? 0,
                'display_order' => $validated['display_order'] ?? 0,
                'sku' => $validated['sku'] ?? null,
                'child_coupon_code' => $validated['child_coupon_code'] ?? null,
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
            ]);

            // اگر محصول فرزند است، وضعیت رو با والد همگام کن
            if ($product->isChild() && $product->parent) {
                $product->update(['status' => $product->parent->status]);
            }

            // مدیریت تصاویر
            $this->handleImages($request, $product);

            // مدیریت ویدیو
            $this->handleVideo($request, $product);

            // مدیریت فایل‌ها
            $this->handleFiles($validated['product_files'] ?? [], $product);

            // دسته‌بندی‌ها
            if (!empty($validated['categories'])) {
                $product->categories()->attach($validated['categories']);
            }

            // ویژگی‌ها
            $this->handleAttributes($validated['attributes'] ?? [], $product);

            DB::commit();

            return response()->json([
                'data' => $product->load([
                    'productType',
                    'categories',
                    'images',
                    'attributeValues',
                    'files',
                    'parent',
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
            'parent',
            'children' => function ($q) {
                $q->orderBy('sort_order');
            },
            'children.images',
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

        $request->merge([
            'categories' => $categories,
            'attributes' => $attributes,
            'deleted_images' => $deletedImages,
            'deleted_files' => $deletedFiles,
            'updated_files' => $updatedFiles,
            'new_files' => $newFiles
        ]);

        if ($request->has('delete_video')) {
            $deleteVideo = filter_var($request->delete_video, FILTER_VALIDATE_BOOLEAN);
            $request->merge(['delete_video' => $deleteVideo]);
        }

        $validated = $request->validate([
            'product_type_id' => 'required|exists:product_types,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:draft,published,unpublished',
            'product_kind' => 'required|in:simple,parent,child',
            'child_type' => 'nullable|in:online,in_person,recorded',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',

            // قیمت
            'price' => 'nullable|integer|min:0',
            'discount_value' => 'nullable|integer|min:0',
            'discount_type' => 'nullable|in:percent,fixed',
            'is_free' => 'boolean',

            // قیمت فرزندان
            'child_price' => 'nullable|integer|min:0',
            'child_discount_price' => 'nullable|integer|min:0',

            // فیلدهای اختصاصی فرزندان
            'child_description' => 'nullable|string',
            'child_meta_title' => 'nullable|string|max:255',
            'child_meta_description' => 'nullable|string|max:500',
            'child_thumbnail' => 'nullable|string|max:500',
            'is_child_free' => 'boolean',
            'child_discount_value' => 'nullable|integer|min:0',
            'child_discount_type' => 'nullable|in:percent,fixed',
            'meeting_link' => 'nullable|string|max:500',
            'location' => 'nullable|string|max:500',
            'max_attendees' => 'nullable|integer|min:0',
            'stock' => 'nullable|integer|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'registration_deadline' => 'nullable|date',
            'is_variation_active' => 'boolean',
            'show_in_front' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
            'display_order' => 'nullable|integer|min:0',
            'sku' => 'nullable|string|max:100|unique:products,sku,' . $product->id,
            'child_coupon_code' => 'nullable|string|max:50',

            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'attributes' => 'nullable|array',
            'images' => 'nullable|array',
            'images.*' => 'file|image|max:5120',
            'deleted_images' => 'nullable|array',
            'deleted_images.*' => 'exists:product_images,id',
            'video' => 'nullable|file|max:204800|mimes:mp4,avi,mkv,mov',
            'delete_video' => 'nullable|boolean',
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
            'deleted_files' => 'nullable|array',
            'deleted_files.*' => 'exists:product_files,id',
        ]);

        DB::beginTransaction();

        try {
            // محاسبه قیمت نهایی
            $finalPrice = $this->calculateFinalPrice(
                $validated['price'] ?? 0,
                $validated['discount_value'] ?? null,
                $validated['discount_type'] ?? null
            );

            $product->update([
                'product_type_id' => $validated['product_type_id'],
                'title' => $validated['title'],
                'slug' => Str::slug($validated['title'] . '-' . $product->id),
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'],
                'product_kind' => $validated['product_kind'],
                'child_type' => $validated['child_type'] ?? null,
                'price' => $validated['price'] ?? 0,
                'final_price' => $finalPrice,
                'discount_value' => $validated['discount_value'] ?? null,
                'discount_type' => $validated['discount_type'] ?? null,
                'is_free' => $validated['is_free'] ?? false,
                'child_price' => $validated['child_price'] ?? null,
                'child_discount_price' => $validated['child_discount_price'] ?? null,
                'child_description' => $validated['child_description'] ?? null,
                'child_meta_title' => $validated['child_meta_title'] ?? null,
                'child_meta_description' => $validated['child_meta_description'] ?? null,
                'child_thumbnail' => $validated['child_thumbnail'] ?? null,
                'is_child_free' => $validated['is_child_free'] ?? false,
                'child_discount_value' => $validated['child_discount_value'] ?? null,
                'child_discount_type' => $validated['child_discount_type'] ?? null,
                'meeting_link' => $validated['meeting_link'] ?? null,
                'location' => $validated['location'] ?? null,
                'max_attendees' => $validated['max_attendees'] ?? null,
                'stock' => $validated['stock'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'registration_deadline' => $validated['registration_deadline'] ?? null,
                'is_variation_active' => $validated['is_variation_active'] ?? true,
                'show_in_front' => $validated['show_in_front'] ?? true,
                'sort_order' => $validated['sort_order'] ?? 0,
                'display_order' => $validated['display_order'] ?? 0,
                'sku' => $validated['sku'] ?? null,
                'child_coupon_code' => $validated['child_coupon_code'] ?? null,
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
            ]);

            // همگام‌سازی وضعیت فرزندان با والد
            if ($product->isParent()) {
                $product->children()->update(['status' => $product->status]);
            }

            // مدیریت تصاویر
            $this->handleImages($request, $product, $validated['deleted_images'] ?? []);

            // مدیریت ویدیو
            $this->handleVideo($request, $product, $validated['delete_video'] ?? false);

            // مدیریت فایل‌ها
            $this->handleFilesUpdate(
                $product,
                $validated['updated_files'] ?? [],
                $validated['new_files'] ?? [],
                $validated['deleted_files'] ?? []
            );

            // بروزرسانی دسته‌بندی‌ها
            if (isset($validated['categories'])) {
                $product->categories()->sync($validated['categories']);
            }

            // بروزرسانی ویژگی‌ها
            $this->handleAttributesUpdate($validated['attributes'] ?? [], $product);

            DB::commit();

            return response()->json([
                'data' => $product->load([
                    'productType',
                    'categories',
                    'images',
                    'attributeValues',
                    'files',
                    'parent',
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
            // اگر محصول والد است، فرزندانش را هم حذف کن
            if ($product->isParent()) {
                foreach ($product->children as $child) {
                    foreach ($child->files as $file) {
                        Storage::disk('public')->delete($file->path);
                    }
                    foreach ($child->images as $image) {
                        Storage::disk('public')->delete($image->path);
                    }
                    if ($child->video) {
                        Storage::disk('public')->delete($child->video);
                    }
                    $child->delete();
                }
            }

            // حذف فایل‌ها
            foreach ($product->files as $file) {
                Storage::disk('public')->delete($file->path);
            }

            // حذف تصاویر
            foreach ($product->images as $image) {
                Storage::disk('public')->delete($image->path);
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

    /**
     * تغییر وضعیت محصول
     */
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

    /**
     * دریافت ویژگی‌های یک نوع محصول
     */
    public function getAttributes(ProductType $productType)
    {
        $attributes = ProductAttribute::where('product_type_id', $productType->id)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $attributes]);
    }

    /**
     * دریافت انواع محصولات (برای فرانت)
     */
    public function frontProductType()
    {
        $types = ProductType::where('is_active', true)
            ->latest()
            ->get();

        return response()->json(['data' => $types]);
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
    /**
     * دریافت همه محصولات والد (محصولاتی که فرزند دارند)
     */
    public function getParentProducts(Request $request)
    {
        $query = Product::with(['productType', 'categories', 'images'])
            ->where('product_kind', 'parent')
            ->where('status', 'published');

        // فیلتر بر اساس جستجو
        if ($search = $request->get('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        // فیلتر بر اساس نوع محصول
        if ($request->filled('product_type_id')) {
            $query->where('product_type_id', $request->product_type_id);
        }

        // مرتب‌سازی
        $sortBy = $request->get('sort_by', 'id');
        $sortOrder = $request->get('sort_order', 'desc');
        $allowedSorts = ['id', 'title', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $parents = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => $parents,
            'message' => 'محصولات والد با موفقیت دریافت شدند'
        ]);
    }

    /**
     * دریافت فرزندان یک محصول والد با آیدی
     */
    public function getChildrenByParentId($parentId)
    {
        // بررسی وجود محصول والد
        $parent = Product::where('id', $parentId)
            ->where('product_kind', 'parent')
            ->first();

        if (!$parent) {
            return response()->json([
                'message' => 'محصول والد مورد نظر یافت نشد'
            ], 404);
        }

        // دریافت فرزندان
        $children = Product::with([
            'productType',
            'images',
            'files',
            'attributeValues'
        ])
            ->where('parent_id', $parentId)
            ->where('product_kind', 'child')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // اضافه کردن اطلاعات تکمیلی به هر فرزند
        $children->each(function ($child) {
            // برچسب نوع
            $child->type_label = $child->child_type_label;

            // قیمت نهایی فرزند
            $child->final_child_price = $child->getPriceForChild();

            // موجودی باقی‌مانده
            $child->available_stock = $child->available_stock;

            // وضعیت موجودی
            $child->stock_status = $child->isOutOfStock() ? 'اتمام موجودی' : 'موجود';
        });

        return response()->json([
            'data' => [
                'parent' => $parent,
                'children' => $children,
                'total' => $children->count()
            ],
            'message' => 'فرزندان محصول با موفقیت دریافت شدند'
        ]);
    }
}
