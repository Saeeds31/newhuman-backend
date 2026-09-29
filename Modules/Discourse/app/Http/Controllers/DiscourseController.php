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
            Discourse::with(['category', 'guest'])
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
            'subjects' => ['nullable', 'string'],
            'guest_id' => ['nullable', 'exists:guests,id'],
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

        return response()->json($discourse->load(['category', 'guest']), 201);
    }

    public function show(Discourse $discourse)
    {
        return response()->json(
            $discourse->load(['category', 'guest'])
        );
    }

    public function update(Request $request, Discourse $discourse)
    {
        $data = $request->validate([
            'title' => ['required', 'string'],
            'discourse_with' => ['required', 'string'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:discourses,slug,' . $discourse->id],
            'video' => ['required', 'string'],
            'main_image' => ['nullable', 'file', 'max:1024'],
            'subjects' => ['nullable', 'string'],
            'guest_id' => ['nullable', 'exists:guests,id'],
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

        return response()->json($discourse->load(['category', 'guest']));
    }

    public function destroy(Discourse $discourse)
    {
        // Delete main image if exists
        if ($discourse->main_image) {
            Storage::disk('public')->delete($discourse->main_image);
        }

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

        $discourses = Discourse::with('guest')
            ->where('discourse_category_id', $category->id)
            ->latest()
            ->get();

        return response()->json([
            'category' => $category,
            'data' => $discourses,
        ]);
    }

    public function getFrontDetailDiscourse(?string $slug = null)
    {
        $discourse = Discourse::with(['category', 'guest'])
            ->where('slug', $slug)
            ->first();

        if (! $discourse) {
            return response()->json([
                'message' => 'هیچ گفتومانی یافت نشد.'
            ], 404);
        }
        $subjects = [];
        if (!empty($discourse->subjects)) {
            $subjects = array_values(array_filter(
                array_map('trim', explode('#', $discourse->subjects)),
                fn($item) => $item !== ''
            ));
        }
        return response()->json([
            'discourse' => array_merge(
                $discourse->toArray(),
                ['subjects' => $subjects]
            ),
        ]);
    }
}
