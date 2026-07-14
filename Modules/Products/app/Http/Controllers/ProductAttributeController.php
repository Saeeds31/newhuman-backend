<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Products\Models\ProductAttribute;
use Modules\Products\Models\ProductAttributeValue;

class ProductAttributeController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductAttribute::with('productType');

        if ($search = $request->get('search')) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%");
        }

        if ($typeId = $request->get('product_type_id')) {
            $query->where('product_type_id', $typeId);
        }

        $attributes = $query->orderBy('sort_order')->paginate(20);

        return response()->json(['data' => $attributes]);
    }

    // ایجاد ویژگی جدید
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_type_id' => 'required|exists:product_types,id',
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:product_attributes,slug',
            'is_required' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $attribute = ProductAttribute::create($validated);

        return response()->json(['data' => $attribute], 201);
    }

    // نمایش یک ویژگی
    public function show(ProductAttribute $productAttribute)
    {
        return response()->json(['data' => $productAttribute->load('productType')]);
    }

    // ویرایش ویژگی
    public function update(Request $request, ProductAttribute $productAttribute)
    {
        $validated = $request->validate([
            'product_type_id' => 'required|exists:product_types,id',
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:product_attributes,slug,' . $productAttribute->id,
            'is_required' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $productAttribute->update($validated);

        return response()->json(['data' => $productAttribute]);
    }

    // حذف ویژگی
    public function destroy(ProductAttribute $productAttribute)
    {
        $productAttribute->delete();
        return response()->json(['message' => 'حذف شد']);
    }

    // =============== مدیریت مقادیر ویژگی ===============

    // لیست مقادیر یک ویژگی
    public function values(ProductAttribute $productAttribute)
    {
        $values = ProductAttributeValue::where('product_attribute_id', $productAttribute->id)
            ->with('product')
            ->paginate(20);

        return response()->json(['data' => $values]);
    }

    // ذخیره مقدار جدید
    public function storeValue(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:files,id',
            'product_attribute_id' => 'required|exists:product_attributes,id',
            'value' => 'nullable|string|max:500',
        ]);

        $value = ProductAttributeValue::create($validated);

        return response()->json(['data' => $value], 201);
    }

    // ویرایش مقدار
    public function updateValue(Request $request, ProductAttributeValue $productAttributeValue)
    {
        $validated = $request->validate([
            'value' => 'nullable|string|max:500',
        ]);

        $productAttributeValue->update($validated);

        return response()->json(['data' => $productAttributeValue]);
    }

    // حذف مقدار
    public function destroyValue(ProductAttributeValue $productAttributeValue)
    {
        $productAttributeValue->delete();
        return response()->json(['message' => 'حذف شد']);
    }
}
