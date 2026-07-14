<?php

namespace Modules\File\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\Comments\Http\Requests\CommentStoreRequest;
use Modules\Comments\Models\Comment;
use Modules\File\Http\Requests\FileStoreRequest;
use Modules\File\Http\Requests\FileUpdateRequest;
use Modules\File\Models\File;
use Modules\File\Models\FileCategory;
use Modules\Notifications\Services\NotificationService;

class FileController extends Controller
{
    public function fileTypes()
    {
        $fileTypes = File::select('file_type')
            ->distinct()
            ->pluck('file_type');
        return response()->json([
            'success' => true,
            'message' => 'لیست   فایل ها ',
            'data'    => $fileTypes
        ]);
    }
    public function frontDetail($slug)
    {
        $file = File::with(['category'])->where('slug', $slug)->first();
        $comments = $file->parentComments()
            ->with(['user', 'replies.user'])
            ->orderBy('created_at', 'desc')
            ->get();
        // محاسبه حجم فایل
        if ($file->file && Storage::disk('public')->exists($file->file)) {
            $fileSizeInBytes = Storage::disk('public')->size($file->file);
            $file->file_size_mb = number_format($fileSizeInBytes / 1048576, 2);
        }
        return response()->json([
            'success' => true,
            'message' => 'جزئیات فایل  ',
            'data'    => $file,
            'comments'    => $comments,
        ]);
    }
    public function frontIndex(Request $request)
    {
        $query = File::query();
        $category = null;
        if ($category_id = $request->get('category_id')) {
            $category = FileCategory::where('slug', $category_id)->first();
            if ($category)
                $query->where('category_id', $category->id);
        }
        if ($file_type = $request->get('file_type')) {
            $query->where('file_type', $file_type);
        }
        $files = $query->with(['category'])->paginate(20);
        return response()->json([
            'success' => true,
            'message' => 'لیست   فایل ها ',
            'data'    => $files,
            'category'    => $category,
        ]);
    }
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $categories = File::paginate($perPage);
        return response()->json([
            'success' => true,
            'message' => 'لیست   فایل ها ',
            'data'    => $categories
        ]);
    }

    public function show($id)
    {
        $file = File::find($id);

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => '  فایل پیدا نشد',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'جزئیات   فایل',
            'data'    => $file
        ]);
    }
    public function storeComment(CommentStoreRequest $request, $slug)
    {
        $file = File::where('slug', $slug)->first();

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => 'فایل یافت نشد',
            ], 404);
        }

        // ایجاد کامنت
        $comment = Comment::create([
            'content' => $request->content,
            'user_id' => Auth::id(),
            'parent_id' => $request->parent_id ?? null, // برای پاسخ به کامنت
            'rating' => $request->rating ?? null,
            'status' => 'pending', // نیاز به تایید ادمین
            'ip' => $request->ip(),
            'commentable_id' => $file->id,
            'commentable_type' => File::class,
        ]);

        // بارگذاری اطلاعات کاربر
        $comment->load('user');

        return response()->json([
            'success' => true,
            'message' => 'کامنت با موفقیت ثبت شد و منتظر تایید است',
            'data' => $comment
        ], 201);
    }

    // Store a new technology
    public function store(FileStoreRequest $request, NotificationService $notifications)
    {
        $validated = $request->validated();
       
        if ($request->hasFile('file')) {
            $fileExtension = $validated['file']->getClientOriginalExtension();
            $format = strtolower($fileExtension);
            $validated['file_type'] = $format;
            $validated['file'] = $request->file('file')->store("files/" . $format, 'public');
        }

        $file = File::create($validated);
        $notifications->create(
            " ثبت   فایل ",
            "  فایل   {$file->title}در سیستم ثبت  شد",
            "notification_file",
            ['notification_file' => $file->id]
        );
        return response()->json([
            'success' => true,
            'message' => '  فایل با موفقیت ثبت شد',
            'data'    => $file
        ], 201);
    }

    // Update a technology
    public function update(FileUpdateRequest $request, $id, NotificationService $notifications)
    {
        $file = File::find($id);
        $data = $request->validated();
        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => '  فایل پیدا نشد',
            ], 404);
        }
       
        if ($request->hasFile('file')) {
            if ($file->file) {
                Storage::disk('public')->delete($file->file);
            }
            $fileExtension = $data['file']->getClientOriginalExtension();
            $format = strtolower($fileExtension);
            $data['file_type'] = $format;
            $data['file'] = $request->file('file')->store("files/" . $format, 'public');
        }
        $file->update($data);
        $notifications->create(
            " بروزرسانی   فایل ",
            "  فایل   {$file->title}در سیستم ویرایش  شد",
            "notification_file",
            ['notification_file' => $file->id]
        );
        return response()->json([
            'success' => true,
            'message' => '  فایل ویرایش شد',
            'data'    => $file
        ]);
    }

    // Delete a technology
    public function destroy($id, NotificationService $notifications)
    {
        $file = File::find($id);

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => '  فایل پیدا نشد',
            ], 404);
        }
        // if ($file->files()->exists()) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'این   فایل به فایلی متصل است و قابل حذف نیست.',
        //     ], 422);
        // }

        $notifications->create(
            " حذف   فایل ",
            "  فایل   {$file->title}از سیستم حذف  شد",
            "notification_file",
            ['notification_file' => $file->id]
        );
        $file->delete();
        return response()->json([
            'success' => true,
            'message' => '  فایل با موفقیت حذف شد',
        ]);
    }
}
