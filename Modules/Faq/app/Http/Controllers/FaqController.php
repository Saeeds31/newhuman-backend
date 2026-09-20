<?php
// Modules/Faq/Http/Controllers/FaqController.php

namespace Modules\Faq\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Faq\Models\Faq;

class FaqController extends Controller
{
    /**
     * لیست همه سوالات (برای ادمین)
     */
    public function index(Request $request)
    {
        $query = Faq::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('question', 'like', "%{$search}%")
                  ->orWhere('answer', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // فیلتر: فقط سوالاتی که به یک محصول خاص متصل نیستن (برای انتخاب سریع‌تر)
        if ($request->filled('product_id')) {
            $productId = $request->product_id;
            $query->where(function ($q) use ($productId) {
                // یا اصلاً به هیچ محصولی متصل نیستن
                $q->whereDoesntHave('products')
                  // یا به این محصول متصل هستن
                  ->orWhereHas('products', function ($sub) use ($productId) {
                      $sub->where('products.id', $productId);
                  });
            });
        }

        $faqs = $query->ordered()->paginate($request->get('per_page', 20));

        return response()->json($faqs);
    }

    /**
     * ایجاد سوال جدید
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'question' => 'required|string|max:500',
            'answer' => 'required|string',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $faq = Faq::create($validated);

        return response()->json([
            'message' => 'سوال با موفقیت ایجاد شد.',
            'data' => $faq,
        ], 201);
    }

    /**
     * نمایش یک سوال
     */
    public function show(Faq $faq)
    {
        $faq->load('products:id,title');

        return response()->json($faq);
    }

    /**
     * بروزرسانی
     */
    public function update(Request $request, Faq $faq)
    {
        $validated = $request->validate([
            'question' => 'sometimes|required|string|max:500',
            'answer' => 'sometimes|required|string',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $faq->update($validated);

        return response()->json([
            'message' => 'سوال با موفقیت بروزرسانی شد.',
            'data' => $faq->fresh(),
        ]);
    }

    /**
     * حذف
     */
    public function destroy(Faq $faq)
    {
        $faq->delete();

        return response()->json(['message' => 'سوال با موفقیت حذف شد.']);
    }

    /**
     * سوالات مربوط به یک محصول (برای فرم ویرایش)
     * فقط سوالاتی که به این محصول attach شدن
     */
    public function productFaqs($productId)
    {
        $faqs = Faq::whereHas('products', function ($q) use ($productId) {
            $q->where('products.id', $productId);
        })
            ->with(['products' => function ($q) use ($productId) {
                $q->where('products.id', $productId);
            }])
            ->ordered()
            ->get();

        // مرتب‌سازی بر اساس sort_order در pivot
        $faqs = $faqs->sortBy(function ($faq) {
            return $faq->products->first()?->pivot->sort_order ?? 0;
        })->values();

        return response()->json([
            'data' => $faqs,
        ]);
    }

    /**
     * ذخیره سوالات یک محصول (sync - برای استفاده در create و edit)
     * این متد بعد از ساخت/ویرایش محصول صدا زده می‌شه
     */
    public function syncProductFaqs(Request $request, $productId)
    {
        $validated = $request->validate([
            'faq_ids' => 'array',
            'faq_ids.*' => 'exists:faqs,id',
            'sort_orders' => 'array', // ['faq_id' => sort_order]
        ]);

        $syncData = [];
        foreach ($validated['faq_ids'] ?? [] as $index => $faqId) {
            $syncData[$faqId] = [
                'sort_order' => $validated['sort_orders'][$faqId] ?? $index,
            ];
        }

        // چون Product توی ماژول دیگه‌ایه، از مدل Product استفاده می‌کنیم
        $product = \Modules\Products\Models\Product::findOrFail($productId);
        $product->faqs()->sync($syncData);

        return response()->json([
            'message' => 'سوالات محصول با موفقیت ذخیره شد.',
            'data' => $product->load('faqs'),
        ]);
    }

    /**
     * همه سوالات فعال (برای انتخاب در فرم)
     * همه سوالات رو برمی‌گردونه، چه attach شده چه نشده
     */
    public function allActive()
    {
        $faqs = Faq::active()->ordered()->get(['id', 'question', 'answer', 'is_active']);

        return response()->json([
            'data' => $faqs,
        ]);
    }
}