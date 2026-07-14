<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Products\Models\ProductType;
use Illuminate\Support\Str;

class ProductTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductType::query();

        if ($search = $request->get('search')) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%");
        }

        $productTypes = $query->orderBy('sort_order')->paginate(20);

        return response()->json([
            'data' => $productTypes
        ]);
    }

    // ذخیره
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'slug' => 'nullable|string|max:255|unique:product_types,slug',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $productType = ProductType::create($validated);

        return response()->json([
            'data' => $productType
        ], 201);
    }

    // نمایش
    public function show(ProductType $productType)
    {
        return response()->json([
            'data' => $productType
        ]);
    }

    // ویرایش
    public function update(Request $request, ProductType $productType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'slug' => 'nullable|string|max:255|unique:product_types,slug,' . $productType->id,
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $productType->update($validated);

        return response()->json([
            'data' => $productType
        ]);
    }

    // حذف
    public function destroy(ProductType $productType)
    {
        $productType->delete();

        return response()->json([
            'message' => 'حذف شد'
        ]);
    }
}
