<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productTypeId = $this->route('product_type')?->id;
        $attributeId = $this->route('attribute')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('product_attributes', 'slug')
                    ->where('product_type_id', $productTypeId)
                    ->ignore($attributeId),
            ],
            'is_required' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}