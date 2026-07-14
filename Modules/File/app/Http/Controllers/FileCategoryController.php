<?php

namespace Modules\File\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\File\Http\Requests\FileCategoryStoreRequest;
use Modules\File\Http\Requests\FileCategoryUpdateRequest;
use Modules\File\Models\FileCategory;
use Modules\Notifications\Services\NotificationService;

class FileCategoryController extends Controller
{
    public function frontIndex(){
        $categories=FileCategory::orderBy('id')->get();
        return response()->json([
            'success' => true,
            'message' => 'لیست  دسته بندی فایل ها ',
            'data'    => $categories
        ]);
    }
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $categories = FileCategory::paginate($perPage);
        return response()->json([
            'success' => true,
            'message' => 'لیست  دسته بندی فایل ها ',
            'data'    => $categories
        ]);
    }

    public function show($id)
    {
        $category = FileCategory::find($id);

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => ' دسته بندی فایل پیدا نشد',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'جزئیات  دسته بندی فایل',
            'data'    => $category
        ]);
    }

    // Store a new technology
    public function store(FileCategoryStoreRequest $request, NotificationService $notifications)
    {
        $validated = $request->validated();
        $category = FileCategory::create($validated);
        $notifications->create(
            " ثبت  دسته بندی فایل ",
            " دسته بندی فایل   {$category->title}در سیستم ثبت  شد",
            "notification_file",
            ['notification_file' => $category->id]
        );
        return response()->json([
            'success' => true,
            'message' => ' دسته بندی فایل با موفقیت ثبت شد',
            'data'    => $category
        ], 201);
    }

    // Update a technology
    public function update(FileCategoryUpdateRequest $request, $id, NotificationService $notifications)
    {
        $category = FileCategory::find($id);
        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => ' دسته بندی فایل پیدا نشد',
            ], 404);
        }
        $data = $request->validated();

        $category->update($data);
        $notifications->create(
            " بروزرسانی  دسته بندی فایل ",
            " دسته بندی فایل   {$category->title}در سیستم ویرایش  شد",
            "notification_file",
            ['notification_file' => $category->id]
        );
        return response()->json([
            'success' => true,
            'message' => ' دسته بندی فایل ویرایش شد',
            'data'    => $category
        ]);
    }

    // Delete a technology
    public function destroy($id, NotificationService $notifications)
    {
        $category = FileCategory::find($id);

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => ' دسته بندی فایل پیدا نشد',
            ], 404);
        }
        if ($category->files()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'این  دسته بندی فایل به فایلی متصل است و قابل حذف نیست.',
            ], 422);
        }

        $notifications->create(
            " حذف  دسته بندی فایل ",
            " دسته بندی فایل   {$category->title}از سیستم حذف  شد",
            "notification_file",
            ['notification_file' => $category->id]
        );
        $category->delete();
        return response()->json([
            'success' => true,
            'message' => ' دسته بندی فایل با موفقیت حذف شد',
        ]);
    }
}
