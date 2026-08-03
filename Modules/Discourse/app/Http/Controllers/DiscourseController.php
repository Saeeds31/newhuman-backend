<?php

namespace Modules\Discourse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Discourse\Models\Discourse;
use Modules\Discourse\Models\DiscourseCategory;

class DiscourseController extends Controller
{

    public function index()
    {
        return response()->json(
            Discourse::with('category')
                ->latest()
                ->paginate(15)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string'],
            'slug' => ['required', 'string', 'max:255', 'unique:discourses,slug'],
            'discourse_with' => ['required', 'string'],
            'video' => ['required', 'string'],
            'main_image' => ['required', 'file', 'max:1024'],
            'short_description' => ['required', 'string'],
            'description' => ['required', 'string'],
            'discourse_category_id' => [
                'required',
                'exists:discourse_categories,id'
            ],
        ]);
        if ($request->hasFile('main_image')) {
            $path = $request->file('main_image')->store('discourse', 'public');
            $data['main_image'] = $path;
        }
        $discourse = Discourse::create($data);

        return response()->json($discourse, 201);
    }

    public function show(Discourse $discourse)
    {
        return response()->json(
            $discourse->load('category')
        );
    }

    public function update(Request $request, Discourse $discourse)
    {
        $data = $request->validate([
            'title' => ['required', 'string'],
            'discourse_with' => ['required', 'string'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:discourses,slug'],
            'video' => ['required', 'string'],
            'main_image' => ['nullable', 'file', 'max:1024'],
            'short_description' => ['required', 'string'],
            'description' => ['required', 'string'],
            'discourse_category_id' => [
                'required',
                'exists:discourse_categories,id'
            ],
        ]);
        if ($request->hasFile('main_image')) {
            // Delete old image if exists
            if ($discourse->main_image) {
                Storage::disk('public')->delete($discourse->main_image);
            }
            $path = $request->file('main_image')->store('discourse', 'public');
            $data['main_image'] = $path;
        }
        $discourse->update($data);

        return response()->json($discourse);
    }

    public function destroy(Discourse $discourse)
    {
        $discourse->delete();

        return response()->json([
            'message' => 'Discourse deleted successfully.'
        ]);
    }
    public function getFrontDiscourseCategory()
    {
        return response()->json([
            'data' => DiscourseCategory::select('id', 'title', 'slug')
                ->orderBy('title')
                ->get()
        ]);
    }
    public function getFrontDiscourses(?string $slug = null)
    {
        $category = $slug
            ? DiscourseCategory::where('slug', $slug)->first()
            : DiscourseCategory::first();

        if (! $category) {
            return response()->json([
                'message' => 'هیچ دسته‌بندی‌ای یافت نشد.'
            ], 404);
        }

        $discourses = Discourse::where('discourse_category_id', $category->id)
            ->latest()
            ->get();

        return response()->json([
            'category' => $category,
            'data' => $discourses,
        ]);
    }
    public function getFrontDetailDiscourse(?string $slug = null)
    {
        $discourse = Discourse::where('slug', $slug)->first();

        if (! $discourse) {
            return response()->json([
                'message' => 'هیچ گفتومانی یافت نشد.'
            ], 404);
        }

        return response()->json([
            'discourse' => $discourse,
        ]);
    }
}
