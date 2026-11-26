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
use Modules\Products\Models\View;

class ProductController extends Controller
{
    /**
     * نمایش جزئیات محصول در فرانت (با پشتیبانی از id یا slug)
     */
    public function frontDetail($productId)
    {
        $product = Product::with([
            'productType',
            'images',
            'faqs',
            'categories',
            'attributeValues.attribute',
            'files'
        ])->where(function ($q) use ($productId) {
            $q->where('id', $productId)->orWhere('slug', $productId);
        })->firstOrFail();

        if ($product->isParent()) {
            $product->load([
                'activeChildren' => function ($q) {
                    $q->with(['images', 'files', 'attributeValues.attribute', 'productType'])
                        ->where('status', 'published');
                }
            ]);
        }

        if (auth()->check()) {
            $userId = auth()->id();
            $fileIds = $product->files->pluck('id')->toArray();

            if ($product->isParent() && $product->activeChildren) {
                foreach ($product->activeChildren as $child) {
                    $fileIds = array_merge($fileIds, $child->files->pluck('id')->toArray());
                }
            }

            $views = View::where('user_id', $userId)
                ->whereIn('product_file_id', $fileIds)
                ->get()
                ->keyBy('product_file_id');

            $setFileStatus = function ($files) use ($views) {
                foreach ($files as $file) {
                    $view = $views->get($file->id);
                    $file->is_watched = $view ? true : false;
                }
                return $files;
            };

            $product->files = $setFileStatus($product->files);

            if ($product->isParent() && $product->activeChildren) {
                foreach ($product->activeChildren as $child) {
                    $child->files = $setFileStatus($child->files);
                }
            }
        }

        $prerequisites = [];
        if (!empty($product->prerequisites)) {
            $prerequisites = array_values(array_filter(
                array_map('trim', explode('#', $product->prerequisites)),
                fn($item) => $item !== ''
            ));
        }

        $suitable_for = [];
        if (!empty($product->suitable_for)) {
            $suitable_for = array_values(array_filter(
                array_map('trim', explode('#', $product->suitable_for)),
                fn($item) => $item !== ''
            ));
        }

        $changes_after_course = [];
        if (!empty($product->changes_after_course)) {
            $changes_after_course = array_values(array_filter(
                array_map('trim', explode('#', $product->changes_after_course)),
                fn($item) => $item !== ''
            ));
        }

        $productFiles = $product->files;
        $groupedFiles = $productFiles->groupBy('chapter_title')->map(function ($files, $chapterTitle) {
            return [
                'chapter_title' => $chapterTitle ?: 'بدون سرفصل',
                'chapter_sort_order' => $files->first()->chapter_sort_order ?? 0,
                'files' => $files->sortBy('sort_order')->values(),
            ];
        })->sortBy('chapter_sort_order')->values();

        $product = array_merge(
            $product->toArray(),
            [
                'prerequisites' => $prerequisites,
                'suitable_for' => $suitable_for,
                'changes_after_course' => $changes_after_course,
                'grouped_files' => $groupedFiles,
            ]
        );

        return response()->json(['data' => $product]);
    }

    /**
     * لیست محصولات برای فرانت
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

        if ($request->filled('product_type_slug')) {
            $slug = $request->product_type_slug;
            $query->whereHas('productType', function ($q) use ($slug) {
                $q->where('slug', $slug);
            });
        }

        if ($request->filled('category_ids')) {
            $categoryIds = explode(',', $request->category_ids);
            $query->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('categories.id', $categoryIds);
            });
        }

        if ($request->filled('child_type')) {
            $query->where('child_type', $request->child_type);
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        }

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

        $sortBy = $request->get('sort_by', 'id');
        $sortOrder = $request->get('sort_order', 'desc');

        $allowedSorts = ['id', 'title', 'price', 'child_price', 'created_at', 'sold_count', 'sort_order'];
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

        if ($request->filled('product_type_slug')) {
            $slug = $request->product_type_slug;
            $query->whereHas('productType', function ($q) use ($slug) {
                $q->where('slug', $slug);
            });
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
        $bookMeta = $this->parseJsonInput($request->input('book_meta', null));

        $request->merge([
            'categories' => $categories,
            'attributes' => $attributes,
            'product_files' => $productFiles,
            'book_meta' => $bookMeta,
        ]);

        $validated = $request->validate([
            'product_type_id' => 'required|exists:product_types,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:draft,published,unpublished',
            'product_kind' => 'required|in:simple,parent,child',
            'parent_id' => 'nullable|exists:products,id',
            'child_type' => 'nullable|in:online,in_person,recorded',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',

            'price' => 'nullable|integer|min:0',
            'discount_value' => 'nullable|integer|min:0',
            'discount_type' => 'nullable|in:percent,fixed',
            'is_free' => 'boolean',

            'child_price' => 'nullable|integer|min:0',
            'child_discount_price' => 'nullable|integer|min:0',

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

            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'attributes' => 'nullable|array',

            'images' => 'nullable|array',
            'images.*' => 'file|image|max:5120',
            'video' => 'nullable|file|max:204800|mimes:mp4,avi,mkv,mov',

            'product_files' => 'nullable|array',
            'product_files.*.title' => 'nullable|string|max:255',
            'product_files.*.description' => 'nullable|string',
            'product_files.*.chapter_title' => 'nullable|string|max:255',
            'product_files.*.chapter_sort_order' => 'nullable|integer|min:0',
            'product_files.*.path' => 'required|string|min:10',
            'product_files.*.is_free' => 'nullable|boolean',
            'product_files.*.sort_order' => 'nullable|integer|min:0',

            'faqs' => 'nullable|array',
            'faqs.*.question' => 'required|string|max:500',
            'faqs.*.answer' => 'required|string',
            'faqs.*.sort_order' => 'nullable|integer|min:0',

            'slug' => 'required|string',
            'video_title' => 'nullable|string',
            'video_summary' => 'nullable|string',
            'prerequisite_title' => 'nullable|string',
            'prerequisite_summary' => 'nullable|string',
            'prerequisites' => 'nullable|string',
            'suitable_for' => 'nullable|string',
            'changes_after_course_title' => 'nullable|string',
            'changes_after_course_summary' => 'nullable|string',
            'changes_after_course' => 'nullable|string',
            'course_files_summary' => 'nullable|string',
            'course_philosophy' => 'nullable|string',
            'book_meta' => 'nullable|array',
        ]);

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
            $finalPrice = $this->calculateFinalPrice(
                $validated['price'] ?? 0,
                $validated['discount_value'] ?? null,
                $validated['discount_type'] ?? null
            );

            $product = Product::create([
                'product_type_id' => $validated['product_type_id'],
                'title' => $validated['title'],
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
                'slug' => Str::slug($validated['slug'] . '-' . uniqid()),
                'video_title' => $validated['video_title'] ?? null,
                'video_summary' => $validated['video_summary'] ?? null,
                'prerequisite_title' => $validated['prerequisite_title'] ?? null,
                'prerequisite_summary' => $validated['prerequisite_summary'] ?? null,
                'prerequisites' => $validated['prerequisites'] ?? null,
                'suitable_for' => $validated['suitable_for'] ?? null,
                'changes_after_course_title' => $validated['changes_after_course_title'] ?? null,
                'changes_after_course_summary' => $validated['changes_after_course_summary'] ?? null,
                'changes_after_course' => $validated['changes_after_course'] ?? null,
                'course_files_summary' => $validated['course_files_summary'] ?? null,
                'course_philosophy' => $validated['course_philosophy'] ?? null,
                'book_meta' => $validated['book_meta'] ?? null,
            ]);

            if ($product->isChild() && $product->parent) {
                $product->update(['status' => $product->parent->status]);
            }

            $this->handleImages($request, $product);
            $this->handleVideo($request, $product);
            $this->handleFiles($validated['product_files'] ?? [], $product);

            if (!empty($validated['categories'])) {
                $product->categories()->attach($validated['categories']);
            }

            $this->handleAttributes($validated['attributes'] ?? [], $product);

            if (!empty($validated['faqs'])) {
                $faqSyncData = [];

                foreach ($validated['faqs'] as $index => $faqData) {
                    $faq = \Modules\Faq\Models\Faq::create([
                        'question' => $faqData['question'],
                        'answer' => $faqData['answer'],
                        'is_active' => true,
                        'sort_order' => $faqData['sort_order'] ?? $index,
                    ]);

                    $faqSyncData[$faq->id] = [
                        'sort_order' => $faqData['sort_order'] ?? $index,
                    ];
                }

                $product->faqs()->sync($faqSyncData);
            }

            DB::commit();

            return response()->json([
                'data' => $product->load([
                    'productType',
                    'categories',
                    'images',
                    'attributeValues',
                    'files',
                    'parent',
                    'children',
                    'faqs',
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
            'faqs',
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
        $faqs = $this->parseJsonInput($request->input('faqs', []));
        $bookMeta = $request->has('book_meta')
            ? $this->parseJsonInput($request->input('book_meta', null))
            : $product->book_meta;

        $request->merge([
            'categories' => $categories,
            'attributes' => $attributes,
            'deleted_images' => $deletedImages,
            'deleted_files' => $deletedFiles,
            'updated_files' => $updatedFiles,
            'new_files' => $newFiles,
            'faqs' => $faqs,
            'book_meta' => $bookMeta,
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

            'price' => 'nullable|integer|min:0',
            'discount_value' => 'nullable|integer|min:0',
            'discount_type' => 'nullable|in:percent,fixed',
            'is_free' => 'boolean',

            'child_price' => 'nullable|integer|min:0',
            'child_discount_price' => 'nullable|integer|min:0',

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
            'updated_files.*.chapter_title' => 'nullable|string|max:255',
            'updated_files.*.chapter_sort_order' => 'nullable|integer|min:0',
            'new_files.*.chapter_title' => 'nullable|string|max:255',
            'new_files.*.chapter_sort_order' => 'nullable|integer|min:0',
            'new_files' => 'nullable|array',
            'new_files.*.title' => 'nullable|string|max:255',
            'new_files.*.path' => 'required|string|max:500',
            'new_files.*.is_free' => 'nullable|boolean',
            'new_files.*.sort_order' => 'nullable|integer|min:0',
            'deleted_files' => 'nullable|array',
            'deleted_files.*' => 'exists:product_files,id',

            'faqs' => 'nullable|array',
            'faqs.*.id' => 'nullable|integer|exists:faqs,id',
            'faqs.*.question' => 'required|string|max:500',
            'faqs.*.answer' => 'required|string',
            'faqs.*.sort_order' => 'nullable|integer|min:0',

            'slug' => 'required|string',
            'video_title' => 'nullable|string',
            'video_summary' => 'nullable|string',
            'prerequisite_title' => 'nullable|string',
            'prerequisite_summary' => 'nullable|string',
            'prerequisites' => 'nullable|string',
            'suitable_for' => 'nullable|string',
            'changes_after_course_title' => 'nullable|string',
            'changes_after_course_summary' => 'nullable|string',
            'changes_after_course' => 'nullable|string',
            'course_files_summary' => 'nullable|string',
            'course_philosophy' => 'nullable|string',
            'book_meta' => 'nullable|array',
        ]);

        DB::beginTransaction();

        try {
            $finalPrice = $this->calculateFinalPrice(
                $validated['price'] ?? 0,
                $validated['discount_value'] ?? null,
                $validated['discount_type'] ?? null
            );

            $product->update([
                'product_type_id' => $validated['product_type_id'],
                'title' => $validated['title'],
                'slug' => Str::slug($validated['slug'] . '-' . $product->id),
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
                'video_title' => $validated['video_title'] ?? null,
                'video_summary' => $validated['video_summary'] ?? null,
                'prerequisite_title' => $validated['prerequisite_title'] ?? null,
                'prerequisite_summary' => $validated['prerequisite_summary'] ?? null,
                'prerequisites' => $validated['prerequisites'] ?? null,
                'suitable_for' => $validated['suitable_for'] ?? null,
                'changes_after_course_title' => $validated['changes_after_course_title'] ?? null,
                'changes_after_course_summary' => $validated['changes_after_course_summary'] ?? null,
                'changes_after_course' => $validated['changes_after_course'] ?? null,
                'course_files_summary' => $validated['course_files_summary'] ?? null,
                'course_philosophy' => $validated['course_philosophy'] ?? null,
                'book_meta' => $validated['book_meta'] ?? null,
            ]);

            if ($product->isParent()) {
                $product->children()->update(['status' => $product->status]);
            }

            $this->handleImages($request, $product, $validated['deleted_images'] ?? []);
            $this->handleVideo($request, $product, $validated['delete_video'] ?? false);

            $this->handleFilesUpdate(
                $product,
                $validated['updated_files'] ?? [],
                $validated['new_files'] ?? [],
                $validated['deleted_files'] ?? []
            );

            if (isset($validated['categories'])) {
                $product->categories()->sync($validated['categories']);
            }

            $this->handleAttributesUpdate($validated['attributes'] ?? [], $product);
            $this->handleFaqsUpdate($product, $validated['faqs'] ?? []);

            DB::commit();

            return response()->json([
                'data' => $product->load([
                    'productType',
                    'categories',
                    'images',
                    'attributeValues',
                    'files',
                    'parent',
                    'children',
                    'faqs',
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

    protected function handleFaqsUpdate(Product $product, array $faqs)
    {
        $syncData = [];

        foreach ($faqs as $index => $faqData) {
            $faqId = $faqData['id'] ?? null;

            if ($faqId) {
                $faq = \Modules\Faq\Models\Faq::find($faqId);
                if ($faq) {
                    $faq->update([
                        'question' => $faqData['question'],
                        'answer' => $faqData['answer'],
                    ]);
                } else {
                    $faq = \Modules\Faq\Models\Faq::create([
                        'question' => $faqData['question'],
                        'answer' => $faqData['answer'],
                        'is_active' => true,
                    ]);
                }
            } else {
                $faq = \Modules\Faq\Models\Faq::create([
                    'question' => $faqData['question'],
                    'answer' => $faqData['answer'],
                    'is_active' => true,
                ]);
            }

            $syncData[$faq->id] = [
                'sort_order' => $faqData['sort_order'] ?? $index,
            ];
        }

        $product->faqs()->sync($syncData);
    }

    public function destroy(Product $product)
    {
        DB::beginTransaction();

        try {
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

            foreach ($product->files as $file) {
                Storage::disk('public')->delete($file->path);
            }

            foreach ($product->images as $image) {
                Storage::disk('public')->delete($image->path);
            }

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

    public function toggleStatus(Product $product)
    {
        $statuses = ['draft', 'published', 'unpublished'];
        $currentIndex = array_search($product->status, $statuses);
        $nextIndex = ($currentIndex + 1) % count($statuses);

        $product->update(['status' => $statuses[$nextIndex]]);

        if ($product->isParent()) {
            $product->children()->update(['status' => $statuses[$nextIndex]]);
        }

        return response()->json([
            'data' => $product,
            'message' => 'وضعیت محصول تغییر کرد'
        ]);
    }

    public function getAttributes(ProductType $productType)
    {
        $attributes = ProductAttribute::where('product_type_id', $productType->id)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $attributes]);
    }

    public function frontProductType()
    {
        $types = ProductType::where('is_active', true)
            ->latest()
            ->get();

        return response()->json(['data' => $types]);
    }

    // ========== متدهای کمکی ==========

    private function parseJsonInput($input)
    {
        if (is_string($input)) {
            return json_decode($input, true) ?? [];
        }
        return $input ?? [];
    }

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

    private function handleImages($request, $product, $deletedImageIds = [])
    {
        if (!empty($deletedImageIds)) {
            $imagesToDelete = ProductImage::whereIn('id', $deletedImageIds)
                ->where('product_id', $product->id)
                ->get();

            foreach ($imagesToDelete as $image) {
                Storage::disk('public')->delete($image->path);
                $image->delete();
            }
        }

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

    private function handleFiles($files, $product)
    {
        if (!empty($files)) {
            foreach ($files as $index => $fileData) {
                ProductFile::create([
                    'product_id' => $product->id,
                    'title' => $fileData['title'] ?? 'فایل بدون عنوان',
                    'description' => $fileData['description'] ?? null,
                    'chapter_title'       => $fileData['chapter_title'] ?? null,
                    'chapter_sort_order'  => $fileData['chapter_sort_order'] ?? 0,
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

    private function handleFilesUpdate($product, $updatedFiles, $newFiles, $deletedFileIds)
    {
        if (!empty($deletedFileIds)) {
            $filesToDelete = ProductFile::whereIn('id', $deletedFileIds)
                ->where('product_id', $product->id)
                ->get();

            foreach ($filesToDelete as $file) {
                Storage::disk('public')->delete($file->path);
                $file->delete();
            }
        }

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
                        'chapter_title'      => $fileData['chapter_title'] ?? $existingFile->chapter_title,
                        'chapter_sort_order' => $fileData['chapter_sort_order'] ?? $existingFile->chapter_sort_order,
                    ]);
                }
            }
        }

        if (!empty($newFiles)) {
            foreach ($newFiles as $fileData) {
                ProductFile::create([
                    'product_id' => $product->id,
                    'title' => $fileData['title'] ?? 'فایل بدون عنوان',
                    'description' => $fileData['description'] ?? null,
                    'chapter_title'       => $fileData['chapter_title'] ?? null,
                    'chapter_sort_order'  => $fileData['chapter_sort_order'] ?? 0,
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

    public function getParentProducts(Request $request)
    {
        $query = Product::with(['productType', 'faqs', 'categories', 'images'])
            ->where('product_kind', 'parent')
            ->where('status', 'published');

        if ($search = $request->get('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        if ($request->filled('product_type_id')) {
            $query->where('product_type_id', $request->product_type_id);
        }

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

    public function getChildrenByParentId($parentId)
    {
        $parent = Product::where('id', $parentId)
            ->where('product_kind', 'parent')
            ->first();

        if (!$parent) {
            return response()->json([
                'message' => 'محصول والد مورد نظر یافت نشد'
            ], 404);
        }

        $children = Product::with([
            'productType',
            'images',
            'faqs',
            'files',
            'attributeValues'
        ])
            ->where('parent_id', $parentId)
            ->where('product_kind', 'child')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $children->each(function ($child) {
            $child->type_label = $child->child_type_label;
            $child->final_child_price = $child->getPriceForChild();
            $child->available_stock = $child->available_stock;
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