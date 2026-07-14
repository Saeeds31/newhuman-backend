<?php

namespace Modules\File\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FileStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules()
    {
        return [
            'title'            => ['required', 'string', 'max:255'],
            'file'             => ['required', 'file', 'max:51200'],
            'category_id' => ['required', 'integer', 'exists:file_category,id'],

        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize()
    {
        return true;
    }
}
