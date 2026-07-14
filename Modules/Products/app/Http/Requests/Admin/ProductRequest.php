<?php

namespace Modules\Products\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'product_type_id' => ['required', 'integer', Rule::exists('product_types', 'id')],

            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],

            'status' => ['required', Rule::in(['draft', 'published', 'unpublished'])],

            'price' => ['required', 'integer', 'min:0'],
            'discount_value' => ['nullable', 'integer', 'min:0'],
            'discount_type' => ['nullable', Rule::in(['percent', 'fixed'])],
            'is_free' => ['boolean'],

            'video' => ['nullable', 'string', 'max:255'],

            // تصویر اصلی شاخص محصول
            'main_image' => ['nullable', 'image', 'max:5120'], // حداکثر ۵ مگابایت

            // ---------------------------
            // گالری تصاویر (چندتایی)
            // ---------------------------
            'images' => ['nullable', 'array'],
            'images.*.file' => ['nullable', 'image', 'max:5120'],
            'images.*.id' => ['nullable', 'integer', Rule::exists('product_images', 'id')],
            'images.*.alt' => ['nullable', 'string', 'max:255'],
            'images.*.sort_order' => ['nullable', 'integer'],

            // ---------------------------
            // فایل‌های دانلودی (چندتایی)
            // ---------------------------
            'files' => ['nullable', 'array'],
            'files.*.file' => ['nullable', 'file', 'max:512000'], // حداکثر ۵۰۰ مگابایت
            'files.*.id' => ['nullable', 'integer', Rule::exists('product_files', 'id')],
            'files.*.title' => ['nullable', 'string', 'max:255'],
            'files.*.description' => ['nullable', 'string'],
            'files.*.is_free' => ['boolean'],
            'files.*.sort_order' => ['nullable', 'integer'],

            // ---------------------------
            // مقدار ویژگی‌های نوع‌محور (key: attribute_id, value: مقدار رشته‌ای)
            // ---------------------------
            'attributes' => ['nullable', 'array'],
            'attributes.*' => ['nullable', 'string', 'max:1000'],

            // دسته‌بندی‌ها
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', Rule::exists('categories', 'id')],
        ];
    }

    /**
     * اعتبارسنجی شرطی: اگر ویژگی الزامی است، مقدارش باید پر باشد.
     * این متد بعد از rules پایه صدا زده می‌شود (در کنترلر یا withValidator).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $productTypeId = $this->input('product_type_id');

            if (! $productTypeId) {
                return;
            }

            $requiredAttributes = \Modules\Products\Models\ProductAttribute::where('product_type_id', $productTypeId)
                ->where('is_required', true)
                ->get();

            foreach ($requiredAttributes as $attribute) {
                $value = $this->input("attributes.{$attribute->id}");

                if (blank($value)) {
                    $validator->errors()->add(
                        "attributes.{$attribute->id}",
                        "فیلد «{$attribute->name}» الزامی است."
                    );
                }
            }
        });
    }
}
