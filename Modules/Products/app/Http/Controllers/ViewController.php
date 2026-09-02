<?php

namespace Modules\Products\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\Products\Models\View;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductFile;
use Illuminate\Support\Facades\Auth;
use Modules\Orders\Models\Order;

class ViewController extends Controller
{
    /**
     * ثبت یا ویرایش مشاهده (اگر بود ویرایش کن، اگر نبود ثبت کن)
     */
    public function storeOrUpdate(Request $request)
    {
        // اعتبارسنجی
        $validator = Validator::make($request->all(), [
            'product_file_id' => 'required|exists:product_files,id',
            'video_time' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $userId = Auth::id();

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'لطفاً وارد سیستم شوید'
                ], 401);
            }

            // پیدا کردن یا ایجاد مشاهده
            $view = View::where('user_id', $userId)
                ->where('product_file_id', $request->product_file_id)
                ->first();

            if ($view) {
                // ویرایش
                $view->update([
                    'video_time' => $request->video_time ?? $view->video_time,
                ]);
                $message = 'مشاهده با موفقیت بروزرسانی شد';
                $statusCode = 200;
            } else {
                // ثبت جدید
                $view = View::create([
                    'user_id' => $userId,
                    'product_file_id' => $request->product_file_id,
                    'video_time' => $request->video_time,
                ]);
                $message = 'مشاهده با موفقیت ثبت شد';
                $statusCode = 201;
            }

            return response()->json([
                'success' => true,
                'data' => $view,
                'message' => $message
            ], $statusCode);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * دریافت میزان مشاهده شده فایل‌های یک محصول
     */
    public function getProductProgress($productId)
    {
        try {
            // بررسی وجود محصول
            $product = Product::findOrFail($productId);

            // دریافت همه فایل‌های محصول
            $files = ProductFile::where('product_id', $productId)->get();
            $totalFiles = $files->count();

            if ($totalFiles === 0) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'product_id' => $productId,
                        'product_title' => $product->title,
                        'total_files' => 0,
                        'watched_files' => 0,
                        'progress_percentage' => 0,
                        'files' => []
                    ],
                    'message' => 'این محصول فایلی ندارد'
                ]);
            }

            // دریافت مشاهده‌های کاربر فعلی برای این فایل‌ها
            $userId = Auth::id();
            $fileIds = $files->pluck('id')->toArray();

            $watchedFileIds = View::where('user_id', $userId)
                ->whereIn('product_file_id', $fileIds)
                ->pluck('product_file_id')
                ->toArray();

            $watchedFiles = count($watchedFileIds);
            $progressPercentage = round(($watchedFiles / $totalFiles) * 100, 2);

            // آماده کردن لیست فایل‌ها با وضعیت مشاهده
            $filesWithStatus = $files->map(function ($file) use ($watchedFileIds) {
                return [
                    'id' => $file->id,
                    'title' => $file->title,
                    'is_watched' => in_array($file->id, $watchedFileIds),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'product_id' => $productId,
                    'product_title' => $product->title,
                    'total_files' => $totalFiles,
                    'watched_files' => $watchedFiles,
                    'progress_percentage' => $progressPercentage,
                    'files' => $filesWithStatus
                ],
                'message' => 'پیشرفت مشاهده محصول با موفقیت دریافت شد'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا: ' . $e->getMessage()
            ], 404);
        }
    }
    public function getPurchasedProductsProgress()
    {
        try {
            $userId = Auth::id();

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'لطفاً وارد سیستم شوید'
                ], 401);
            }

            // دریافت تمام سفارش‌های پرداخت شده کاربر
            $orders = Order::where('user_id', $userId)
                ->whereIn('payment_status', ['paid', 'completed'])
                ->with('product')
                ->get();

            if ($orders->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_purchased_products' => 0,
                        'products' => [],
                        'overall_progress' => 0
                    ],
                    'message' => 'شما هیچ محصولی خریداری نکرده‌اید'
                ]);
            }

            // استخراج product_id های منحصر به فرد
            $productIds = $orders->pluck('product_id')->unique()->toArray();

            // دریافت تمام محصولات با فایل‌هایشان
            $products = Product::with(['files'])
                ->whereIn('id', $productIds)
                ->get();

            $userId = Auth::id();
            $result = [];
            $totalProgress = 0;
            $productCount = 0;

            foreach ($products as $product) {
                $files = $product->files;
                $totalFiles = $files->count();

                if ($totalFiles === 0) {
                    $result[] = [
                        'product_id' => $product->id,
                        'product_title' => $product->title,
                        'total_files' => 0,
                        'watched_files' => 0,
                        'progress_percentage' => 0,
                        'status' => 'no_file'
                    ];
                    continue;
                }

                $fileIds = $files->pluck('id')->toArray();

                // تعداد فایل‌های مشاهده شده
                $watchedCount = View::where('user_id', $userId)
                    ->whereIn('product_file_id', $fileIds)
                    ->count();

                $progress = round(($watchedCount / $totalFiles) * 100, 2);

                $result[] = [
                    'product_id' => $product->id,
                    'product_title' => $product->title,
                    'total_files' => $totalFiles,
                    'watched_files' => $watchedCount,
                    'progress_percentage' => $progress,
                    'status' => $this->getProgressStatus($progress),
                ];

                $totalProgress += $progress;
                $productCount++;
            }

            // محاسبه میانگین کلی پیشرفت
            $overallProgress = $productCount > 0 ? round($totalProgress / $productCount, 2) : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'total_purchased_products' => $productCount,
                    'overall_progress' => $overallProgress,
                    'products' => $result,
                ],
                'message' => 'پیشرفت مشاهده محصولات خریداری شده با موفقیت دریافت شد'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا: ' . $e->getMessage()
            ], 500);
        }
    }
}
