<?php

namespace Modules\Products\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\Products\Models\Certificate;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Modules\Orders\Models\Order;

class CertificateController extends Controller
{
    /**
     * نمایش لیست گواهینامه‌ها با فیلترهای مختلف
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
            'product_id' => 'nullable|exists:products,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $query = Certificate::with(['user', 'product']);

        // فیلتر بر اساس کاربر
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // فیلتر بر اساس محصول
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // مرتب‌سازی بر اساس جدیدترین
        $query->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 15);
        $certificates = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $certificates,
            'message' => 'لیست گواهینامه‌ها با موفقیت بارگذاری شد'
        ]);
    }

    /**
     * نمایش گواهینامه‌های یک کاربر خاص
     */
    public function getUserCertificates(Request $request)
    {
        try {
            // دریافت کاربر لاگین شده
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'لطفاً وارد سیستم شوید'
                ], 401);
            }

            $certificates = Certificate::with(['product'])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user->only(['id', 'name', 'email']),
                    'certificates' => $certificates,
                    'total' => $certificates->count()
                ],
                'message' => 'گواهینامه‌های کاربر با موفقیت بارگذاری شد'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت گواهینامه‌ها'
            ], 500);
        }
    }

    /**
     * نمایش گواهینامه‌های یک محصول خاص
     */
    public function getProductCertificates(Request $request, $productId)
    {
        try {
            $product = Product::findOrFail($productId);

            $certificates = Certificate::with(['user'])
                ->where('product_id', $productId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'product' => $product->only(['id', 'title']),
                    'certificates' => $certificates,
                    'total' => $certificates->count()
                ],
                'message' => 'گواهینامه‌های محصول با موفقیت بارگذاری شد'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'محصول مورد نظر یافت نشد'
            ], 404);
        }
    }

    /**
     * ثبت گواهینامه جدید
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
            'image' => 'required|file|max:1024',
            'file' => 'required|file|max:2048',
            'number' => 'required|string|max:255|unique:certificates,number',
            'date_acquisition' => 'nullable|date',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // ✅ بررسی اینکه آیا کاربر این محصول رو خریداری کرده و پرداخت شده
        $hasPurchased = Order::where('user_id', $request->user_id)
            ->where('product_id', $request->product_id)
            ->whereIn('payment_status', ['paid', 'completed'])
            ->exists();

        if (!$hasPurchased) {
            return response()->json([
                'success' => false,
                'message' => 'این کاربر این محصول را خریداری نکرده یا پرداخت آن تکمیل نشده است'
            ], 403); // 403 Forbidden
        }

        // بررسی اینکه آیا قبلاً برای این کاربر و این محصول گواهینامه ثبت شده
        $existingCertificate = Certificate::where('user_id', $request->user_id)
            ->where('product_id', $request->product_id)
            ->first();

        if ($existingCertificate) {
            return response()->json([
                'success' => false,
                'message' => 'این کاربر قبلاً برای این محصول گواهینامه دریافت کرده است',
                'data' => $existingCertificate
            ], 409); // 409 Conflict
        }

        try {
            $data = [];
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('certificates', 'public');
                $data['image'] = $path;
            }
            if ($request->hasFile('file')) {
                $path = $request->file('file')->store('certificates', 'public');
                $data['file'] = $path;
            }

            $certificate = Certificate::create([
                'user_id' => $request->user_id,
                'product_id' => $request->product_id,
                'image' => $data['image'],
                'file' => $data['file'],
                'number' => $request->number,
                'date_acquisition' => $request->date_acquisition ?? now(),
                'description' => $request->description,
            ]);

            // بارگذاری روابط برای پاسخ
            $certificate->load(['user', 'product']);

            return response()->json([
                'success' => true,
                'data' => $certificate,
                'message' => 'گواهینامه با موفقیت ثبت شد'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در ثبت گواهینامه: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * نمایش یک گواهینامه خاص
     */
    public function show($id)
    {
        try {
            $certificate = Certificate::with(['user', 'product'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $certificate,
                'message' => 'گواهینامه با موفقیت بارگذاری شد'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'گواهینامه مورد نظر یافت نشد'
            ], 404);
        }
    }

    /**
     * ویرایش گواهینامه
     */
    public function update(Request $request, $id)
    {
        try {
            $certificate = Certificate::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'image' => 'nullable|file|max:1024',
                'file' => 'nullable|file|max:2048',
                'number' => 'nullable|string|max:255|unique:certificates,number,' . $id,
                'date_acquisition' => 'nullable|date',
                'description' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // ✅ اگر user_id یا product_id تغییر کرده، بررسی کن
            if ($request->has('user_id') || $request->has('product_id')) {
                $userId = $request->user_id ?? $certificate->user_id;
                $productId = $request->product_id ?? $certificate->product_id;

                // بررسی خرید کاربر
                $hasPurchased = Order::where('user_id', $userId)
                    ->where('product_id', $productId)
                    ->whereIn('payment_status', ['paid', 'completed'])
                    ->exists();

                if (!$hasPurchased) {
                    return response()->json([
                        'success' => false,
                        'message' => 'این کاربر این محصول را خریداری نکرده یا پرداخت آن تکمیل نشده است'
                    ], 403);
                }

                // بررسی گواهینامه تکراری (به جز خودش)
                $existingCertificate = Certificate::where('user_id', $userId)
                    ->where('product_id', $productId)
                    ->where('id', '!=', $id)
                    ->first();

                if ($existingCertificate) {
                    return response()->json([
                        'success' => false,
                        'message' => 'این کاربر قبلاً برای این محصول گواهینامه دریافت کرده است'
                    ], 409);
                }
            }

            $data = [];
            if ($request->hasFile('image')) {
                // حذف تصویر قبلی
                if ($certificate->image) {
                    Storage::disk('public')->delete($certificate->image);
                }
                $path = $request->file('image')->store('certificates', 'public');
                $data['image'] = $path;
            }

            if ($request->hasFile('file')) {
                // حذف فایل قبلی
                if ($certificate->file) {
                    Storage::disk('public')->delete($certificate->file);
                }
                $path = $request->file('file')->store('certificates', 'public');
                $data['file'] = $path;
            }

            // به‌روزرسانی
            $certificate->update([
                'user_id' => $request->user_id ?? $certificate->user_id,
                'product_id' => $request->product_id ?? $certificate->product_id,
                'image' => $data['image'] ?? $certificate->image,
                'file' => $data['file'] ?? $certificate->file,
                'number' => $request->number ?? $certificate->number,
                'date_acquisition' => $request->date_acquisition ?? $certificate->date_acquisition,
                'description' => $request->description ?? $certificate->description,
            ]);

            // بارگذاری مجدد با روابط
            $certificate->fresh();
            $certificate->load(['user', 'product']);

            return response()->json([
                'success' => true,
                'data' => $certificate,
                'message' => 'گواهینامه با موفقیت ویرایش شد'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'گواهینامه مورد نظر یافت نشد'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در ویرایش گواهینامه: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * حذف گواهینامه
     */
    public function destroy($id)
    {
        try {
            $certificate = Certificate::findOrFail($id);

            // ذخیره اطلاعات برای پاسخ
            $certificateData = $certificate->only(['id', 'number', 'user_id', 'product_id']);
            if ($certificate->image) {
                Storage::disk('public')->delete($certificate->image);
            }
            if ($certificate->file) {
                Storage::disk('public')->delete($certificate->file);
            }
            $certificate->delete();

            return response()->json([
                'success' => true,
                'data' => $certificateData,
                'message' => 'گواهینامه با موفقیت حذف شد'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'گواهینامه مورد نظر یافت نشد'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در حذف گواهینامه: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * بررسی وجود گواهینامه برای یک کاربر و محصول خاص
     */
    public function check(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $certificate = Certificate::where('user_id', $request->user_id)
            ->where('product_id', $request->product_id)
            ->with(['user', 'product'])
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'has_certificate' => $certificate ? true : false,
                'certificate' => $certificate
            ],
            'message' => $certificate ? 'گواهینامه وجود دارد' : 'گواهینامه وجود ندارد'
        ]);
    }

    /**
     * دریافت آمار گواهینامه‌ها
     */
    public function statistics(Request $request)
    {
        $totalCertificates = Certificate::count();
        $totalUsers = Certificate::distinct('user_id')->count('user_id');
        $totalProducts = Certificate::distinct('product_id')->count('product_id');

        // گواهینامه‌های امروز
        $todayCertificates = Certificate::whereDate('created_at', today())->count();

        // جدیدترین گواهینامه‌ها
        $latestCertificates = Certificate::with(['user', 'product'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_certificates' => $totalCertificates,
                'total_users' => $totalUsers,
                'total_products' => $totalProducts,
                'today_certificates' => $todayCertificates,
                'latest_certificates' => $latestCertificates,
            ],
            'message' => 'آمار گواهینامه‌ها با موفقیت بارگذاری شد'
        ]);
    }
}
