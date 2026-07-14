<?php

namespace Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Addresses\Models\Address;
use Modules\Cart\Models\Cart;
use Modules\Coupons\Models\Coupon;
use Modules\Coupons\Services\CouponService;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Http\Requests\OrderStoreRequest;
use Modules\Orders\Http\Requests\OrderUpdateRequest;
use Modules\Orders\Models\Order;
use Modules\Orders\Services\PaymentService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Shipping\Models\Shipping;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Services\ShippingService;
use Modules\Users\Models\User;
use Modules\Wallet\Models\Wallet;

class OrdersController extends Controller
{


    /**
     * لیست سفارش‌ها
     */
    public function index(Request $request)
    {
        $orders = Order::with(['user', 'product'])->paginate(20);
        // اگر کوئری جستجو اومد روی نام کاربر یا شماره موبایل اعمال کن
        if ($search = $request->get('q')) {
            $orders->whereHas('user', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'message' => "لیست سفارشات",
            'data' => $orders,
            'success' => true
        ]);
    }

    /**
     * ایجاد سفارش جدید
     */
    public function store(OrderStoreRequest $request, NotificationService $notifications)
    {
        $data = $request->validate([
            'user_id'            => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
            'subtotal'           => 'required|numeric|min:0',
            'discount_amount'    => 'nullable|numeric|min:0',
            'total'              => 'required|numeric|min:0',
            'payment_method'     => 'nullable|string|max:50',
            'payment_status'     => 'nullable|in:pending,paid,failed',
            'status'             => 'nullable|in:pending,processing,completed,cancelled',
        ]);
        $order = Order::create($data);
        $notifications->create(
            "ثبت سفارش",
            " یک سفارش در سیستم ثبت  شد",
            "notification_order",
            ['order' => $order->id]
        );
        return response()->json($order->load(['user']), 201);
    }

    /**
     * نمایش جزئیات سفارش
     */
    public function show(Order $order)
    {
        return response()->json(
            [
                'message' => 'جزئیات سفارش',
                'success' => true,
                'data' => $order->load(['user'])
            ]
        );
    }

    /**
     * بروزرسانی سفارش
     */
    public function update(OrderUpdateRequest $request, Order $order, NotificationService $notifications)
    {
        $data = $request->validate([
            'user_id'            => 'sometimes|exists:users,id',
            'product_id' => 'sometimes|exists:products,id',
            'subtotal'           => 'sometimes|numeric|min:0',
            'discount_amount'    => 'nullable|numeric|min:0',
            'total'              => 'sometimes|numeric|min:0',
            'payment_method'     => 'nullable|string|max:50',
            'payment_status'     => 'nullable|in:pending,paid,failed',
            'status'             => 'nullable|in:pending,processing,completed,cancelled',
        ]);
        $order->update($data);
        $notifications->create(
            "ویرایش سفارش",
            " یک سفارش در سیستم ویرایش  شد",
            "notification_order",
            ['order' => $order->id]
        );
        return response()->json($order->load(['user']));
    }
    public function storeInAdmin(Request $request, NotificationService $notifications)
    {
        $data = $request->validate([
            'user_id'            => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
            'subtotal'           => 'required|numeric|min:0',
            'discount_amount'    => 'nullable|numeric|min:0',
            'total'              => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($data, $notifications) {
            $user = User::with(['wallet'])->findOrFail($data['user_id']);
            // 1. چک موجودی کیف پول
            if (empty($user->wallet)) {
                Wallet::create([
                    'user_id' => $user->id,
                    'balance' => 0,
                ]);
                $user->load('wallet');
            }
            if ($user->wallet->balance < $data['total']) {
                return response()->json(['message' => 'موجودی کیف پول کافی نیست'], 422);
            }
            // 3. ایجاد سفارش
            $order = Order::create([
                'user_id'            => $data['user_id'],
                'product_id' => $data['product_id'],
                'subtotal'           => $data['subtotal'],
                'discount_amount'    => $data['discount_amount'] ?? 0,
                'total'              => $data['total'],
                'payment_method'     => "wallet",
                'payment_status'     => "paid",
                'status'             => "processing",
            ]);
            // 5. کم کردن موجودی کیف پول
            $user->wallet()->update([
                'balance' => $user->wallet->balance - $data['total'],
            ]);
            $user->wallet->transactions()->create([
                'type' => 'debit',
                'amount' => $data['total'],
                'description' => "پرداخت برای سفارش #{$order->id}",
            ]);
            $notifications->create(
                "ثبت سفارش",
                "یک سفارش در پنل ادمین ثبت شد",
                "notification_order",
                ['order' => $order->id]
            );
            return response()->json($order->load(['user']), 201);
        });
    }
    public function changeStatus(Request $request, Order $order, NotificationService $notifications)
    {
        $data = $request->validate([
            'status'         => 'required|in:pending,processing,shipped,completed,canceled,returned,reserved',
        ]);

        // بررسی تغییر وضعیت به مواردی که نیاز به عملیات خاص دارن
        if (isset($data['status'])) {
            // مثال: اگر سفارش لغو شد،و از قبل پرداختی داشت موجودی کیف پول یا محصولات برگشت داده شود
            if ($order->status == 'processing' && $data['status'] === 'canceled') {
                // برگشت مبلغ به کیف پول
                if ($order->payment_status === 'paid') {
                    $order->user->wallet()->increment('balance', $order->total);
                    $order->user->wallet->transactions()->create([
                        'type' => 'credit',
                        'amount' => $order->total,
                        'description' => "Refund for canceled order #{$order->id}",
                    ]);
                }
            }
        }

        // بروزرسانی وضعیت و وضعیت پرداخت
        if (isset($data['status'])) {
            $order->status = $data['status'];
        }


        $order->save();
        $notifications->create(
            "تغییر وضعیت",
            " یک سفارش در سیستم تغییر وضعیت پیدا کرد",
            "notification_order",
            ['order' => $order->id]
        );
        $smsService = new SmsService();
        $smsService->sendToKavenegar('change-order-status', $order->user->mobile, $order->id, ['token20' => $order->user->getDisplayName($order->address->receiver_name), 'token2' => $order->status_label]);

        return response()->json([
            'message' => 'وضعیت سفارش با موفقیت تغییر کرد',
            'order'   => $order->load(['user'])
        ]);
    }
    public function todaysOrders()
    {
        $today = Carbon::today();
        $orders = Order::with(['user'])
            ->whereDate('created_at', $today)->where('status', "processing")
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'تعداد سفارشات امروز',
            'data'    => $orders
        ]);
    }
    public function checkout(Request $request, NotificationService $notifications)
    {
        $user = $request->user();

        $dataValidated = $request->validate([
            'product_id'   => 'required|exists:products,id',
            'payment_method'       => 'required|in:wallet,online,hybrid',
            'coupon_code'          => 'nullable|string',
            'gateway' => 'required_if:payment_method,online,hybrid|nullable|in:zarinpal,payir,saman', // اضافه شد
        ]);
        $product = Product::findOrFail($dataValidated['product_id']);
        $subtotal = $product->final_price;
        $discountAmount = 0;
        $coupon = null;
        if ($request->filled('coupon_code')) {
            $couponResult = (new CouponService)->validateAndCalculate($request->coupon_code, $subtotal, $user->id);
            if (!$couponResult['success']) {
                return response()->json(['message' => $couponResult['message']], 422);
            }
            $discountAmount = $couponResult['discount'];
            $coupon = $couponResult['coupon'];
        }

        $total = $subtotal - $discountAmount;


        $walletBalance = $user->wallet?->balance ?? 0;
        $fromWallet = 0;
        $toPayOnline = $total;

        if ($request->payment_method === 'wallet') {
            if ($walletBalance >= $total) {
                $fromWallet = $total;
                $toPayOnline = 0;
            } else {
                return response()->json([
                    'message' => "موجودی کیف پول کافی نیست. موجودی: {$walletBalance} تومان",
                    'wallet_balance' => $walletBalance
                ], 422);
            }
        } elseif ($request->payment_method === 'hybrid') {
            if ($walletBalance > 0) {
                $fromWallet = min($walletBalance, $total);
                $toPayOnline = $total - $fromWallet;
            }
        }

        $finalStatus = OrderStatus::PROCESSING->value;



        // 11. تراکنش اصلی
        return DB::transaction(function () use (
            $notifications,
            $user,
            $subtotal,
            $product,
            $discountAmount,
            $total,
            $fromWallet,
            $toPayOnline,
            $request,
            $coupon,
            $finalStatus,
            $walletBalance
        ) {
            // ایجاد سفارش
            $order = Order::create([
                'user_id'            => $user->id,
                'subtotal'           => $subtotal,
                'product_id'           => $product->id,
                'discount_amount'    => $discountAmount,
                'total'              => $total,
                'payment_method'     => $request->payment_method,
                'payment_status'     => $toPayOnline > 0 ? 'pending' : 'paid',
                'status'             => $finalStatus,
                'wallet_payment'     => $fromWallet,
                'online_payment'     => $toPayOnline,
            ]);


            // اعمال کوپن
            if ($coupon) {
                (new CouponService)->applyCoupon($coupon, $user->id);
                $order->coupon_id = $coupon->id;
                $order->save();
            }

            // پرداخت از کیف پول (هم برای رزرو و هم عادی)
            if ($fromWallet > 0) {
                $user->wallet->update(['balance' => $walletBalance - $fromWallet]);
                $user->wallet->transactions()->create([
                    'type'        => 'debit',
                    'amount'      => $fromWallet,
                    'description' => "پرداخت برای سفارش #{$order->id}",
                    'order_id'    => $order->id,
                ]);
            }


            // پرداخت آنلاین
            if ($toPayOnline > 0) {
                $gateway = $request->get('gateway', config('payment.default', 'zarinpal'));

                $transaction = GatewayTransaction::create([
                    'order_id' => $order->id,
                    'user_id'  => $user->id,
                    'amount'   => $toPayOnline,
                    'gateway' => $gateway,
                    'status'   => 'pending',
                ]);
                // درخواست به درگاه پرداخت
                $paymentService = new PaymentService();
                $paymentResult = $paymentService->requestPayment($order, $gateway, [
                    'transaction_id' => $transaction->id,
                    'callback_url' => route('gateway.callback.show', $transaction->id)
                ]);

                if (!$paymentResult['success']) {
                    // اگر درگاه خطا داد، سفارش رو حذف کن
                    DB::rollBack();
                    return response()->json([
                        'message' => 'خطا در اتصال به درگاه پرداخت',
                        'error' => $paymentResult['message'] ?? 'Unknown error'
                    ], 500);
                }
                $notifications->create(
                    "سفارش در انتظار پرداخت",
                    "مبلغ {$toPayOnline} تومان باقی مانده است",
                    "notification_order",
                    ['order' => $order->id]
                );

                return response()->json([
                    'order'        => $order->load('items'),
                    'payment_info' => [
                        'from_wallet'    => $fromWallet,
                        'to_pay_online'  => $toPayOnline,
                        'transaction_id' => $transaction->id,
                        'gateway' => $gateway,
                        'gateway_url' => $paymentResult['payment_url'], // لینک واقعی درگاه
                    ],
                ], 201);
            }

            // سفارش بدون نیاز به پرداخت آنلاین
            $message = "سفارش با موفقیت ثبت و پرداخت شد";

            $notifications->create(
                "سفارش تکمیل شد",
                $message,
                "notification_order",
                ['order' => $order->id]
            );
            $smsService = new SmsService();
            $smsService->sendToKavenegar('customer-order', $user->mobile, $order->id, ['token20' => $user->getDisplayName()]);
            $smsService->sendToAdmins('customer-order-admin', $order->id);
            return response()->json([
                'order'   => $order->load('items'),
                'message' => $message,
            ], 201);
        });
    }
    public function checkoutSummary(Request $request)
    {
        $user = $request->user();
        $product = Product::findOrFail($request->input('product_id'));
        $couponDiscount = 0;
        $coupon = null;
        $subtotal = $product->final_price;
        if ($request->filled('coupon_code')) {
            $couponResult = (new CouponService)->validateAndCalculate($request->coupon_code, $subtotal, $user->id);

            if (!$couponResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $couponResult['message']
                ], 422);
            }

            $couponDiscount = $couponResult['discount'];
            $coupon = $couponResult['coupon'];
        }

        $payable = max(0, $subtotal - $couponDiscount);

        return response()->json([
            'success' => true,
            'summary' => [
                'subtotal'          => (int) $subtotal,
                'coupon_discount'   => (int) $couponDiscount,
                'payable_amount'    => (int) $payable,
            ],
            'coupon' => $coupon?->code ?? null,
        ]);
    }
    public function userDashboardOrders(Request $request)
    {
        $user = $request->user();

        $query = Order::with(['product'])
            ->where('user_id', $user->id);

        // فیلتر وضعیت سفارش
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // فیلتر وضعیت پرداخت
        if ($paymentStatus = $request->get('payment_status')) {
            $query->where('payment_status', $paymentStatus);
        }

        // فیلتر تاریخ از
        if ($fromDate = $request->get('from_date')) {
            $query->whereDate('created_at', '>=', $fromDate);
        }

        // فیلتر تاریخ تا
        if ($toDate = $request->get('to_date')) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        // مرتب‌سازی اختیاری
        $query->orderBy('created_at', 'desc');

        // Pagination یا همه
        $orders = $query->paginate(15);

        return response()->json([
            'orders' => $orders,
        ]);
    }
    public function userDashboardOrderDetail(Request $request, $orderId)
    {
        $user = $request->user();

        // پیدا کردن سفارش با تمام روابط
        $order = Order::with([
            'product',
            'user',
        ])->where('id', $orderId)
            ->where('user_id', $user->id) // فقط سفارش‌های خودش
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'سفارش پیدا نشد یا دسترسی ندارید.'
            ], 404);
        }

        return response()->json([
            'order' => $order,
        ]);
    }
}
