<?php

namespace Modules\Discourse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Discourse\Models\DiscourseCategory;
use Illuminate\Support\Str;
class DiscourseCategoryController extends Controller
{

    public function index()
    {
        return response()->json(
            DiscourseCategory::latest()->paginate(15)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:discourse_categories,slug'],
            'meta_title' => ['nullable', 'string'],
            'meta_description' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['title']);

        $category = DiscourseCategory::create($data);

        return response()->json($category, 201);
    }

    public function show(DiscourseCategory $discourseCategory)
    {
        return response()->json($discourseCategory);
    }

    public function update(Request $request, DiscourseCategory $discourseCategory)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:discourse_categories,slug,' . $discourseCategory->id],
            'meta_title' => ['nullable', 'string'],
            'meta_description' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['title']);

        $discourseCategory->update($data);

        return response()->json($discourseCategory);
    }

    public function destroy(DiscourseCategory $discourseCategory)
    {
        $discourseCategory->delete();

        return response()->json([
            'message' => 'Category deleted successfully.'
        ]);
    }
}
