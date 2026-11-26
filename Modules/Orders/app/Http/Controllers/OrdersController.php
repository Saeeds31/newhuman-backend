<?php

namespace Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Coupons\Services\CouponService;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Http\Requests\OrderStoreRequest;
use Modules\Orders\Http\Requests\OrderUpdateRequest;
use Modules\Orders\Models\Order;
use Modules\Orders\Services\PaymentService;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use Modules\Wallet\Models\Wallet;

class OrdersController extends Controller
{
    /**
     * لیست سفارش‌ها
     */
    public function index(Request $request)
    {
        $query = Order::with(['user', 'product.productType'])->orderByDesc('id');

        if ($search = $request->get('q')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        $orders = $query->paginate(20);

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
            'user_id'          => 'required|exists:users,id',
            'product_id'       => 'required|exists:products,id',
            'quantity'         => 'nullable|integer|min:1',
            'subtotal'         => 'required|numeric|min:0',
            'discount_amount'  => 'nullable|numeric|min:0',
            'total'            => 'required|numeric|min:0',
            'payment_method'   => 'nullable|string|max:50',
            'payment_status'   => 'nullable|in:pending,paid,failed',
            'status'           => 'nullable|in:pending,processing,completed,cancelled',
            'receiver_name'    => 'nullable|string|max:150',
            'receiver_mobile'  => 'nullable|string|max:20',
            'postal_code'      => 'nullable|string|max:20',
            'shipping_address' => 'nullable|string|max:1000',
        ]);

        $data['quantity'] = max(1, (int) ($data['quantity'] ?? 1));

        $order = Order::create($data);
        $notifications->create(
            "ثبت سفارش",
            " یک سفارش در سیستم ثبت شد",
            "notification_order",
            ['order' => $order->id]
        );

        return response()->json($order->load(['user', 'product']), 201);
    }

    /**
     * نمایش جزئیات سفارش
     */
    public function show(Order $order)
    {
        return response()->json([
            'message' => 'جزئیات سفارش',
            'success' => true,
            'data' => $order->load(['user', 'product.productType'])
        ]);
    }

    /**
     * بروزرسانی سفارش
     */
    public function update(OrderUpdateRequest $request, Order $order, NotificationService $notifications)
    {
        $data = $request->validate([
            'user_id'          => 'sometimes|exists:users,id',
            'product_id'       => 'sometimes|exists:products,id',
            'quantity'         => 'sometimes|integer|min:1',
            'subtotal'         => 'sometimes|numeric|min:0',
            'discount_amount'  => 'nullable|numeric|min:0',
            'total'            => 'sometimes|numeric|min:0',
            'payment_method'   => 'nullable|string|max:50',
            'payment_status'   => 'nullable|in:pending,paid,failed',
            'status'           => 'nullable|in:pending,processing,shipped,completed,cancelled,canceled',
            'receiver_name'    => 'nullable|string|max:150',
            'receiver_mobile'  => 'nullable|string|max:20',
            'postal_code'      => 'nullable|string|max:20',
            'shipping_address' => 'nullable|string|max:1000',
        ]);

        $order->update($data);
        $notifications->create(
            "ویرایش سفارش",
            " یک سفارش در سیستم ویرایش شد",
            "notification_order",
            ['order' => $order->id]
        );

        return response()->json($order->load(['user', 'product']));
    }

    public function storeInAdmin(Request $request, NotificationService $notifications)
    {
        $data = $request->validate([
            'user_id'          => 'required|exists:users,id',
            'product_id'       => 'required|exists:products,id',
            'quantity'         => 'nullable|integer|min:1',
            'subtotal'         => 'required|numeric|min:0',
            'discount_amount'  => 'nullable|numeric|min:0',
            'total'            => 'required|numeric|min:0',
            'receiver_name'    => 'nullable|string|max:150',
            'receiver_mobile'  => 'nullable|string|max:20',
            'postal_code'      => 'nullable|string|max:20',
            'shipping_address' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($data, $notifications) {
            $user = User::with(['wallet'])->findOrFail($data['user_id']);

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

            $order = Order::create([
                'user_id'          => $data['user_id'],
                'product_id'       => $data['product_id'],
                'quantity'         => max(1, (int) ($data['quantity'] ?? 1)),
                'subtotal'         => $data['subtotal'],
                'discount_amount'  => $data['discount_amount'] ?? 0,
                'total'            => $data['total'],
                'payment_method'   => "wallet",
                'payment_status'   => "paid",
                'status'           => "processing",
                'receiver_name'    => $data['receiver_name'] ?? $user->full_name,
                'receiver_mobile'  => $data['receiver_mobile'] ?? $user->mobile,
                'postal_code'      => $data['postal_code'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
            ]);

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

            return response()->json($order->load(['user', 'product']), 201);
        });
    }

    public function changeStatus(Request $request, Order $order, NotificationService $notifications)
    {
        $data = $request->validate([
            'status' => 'required|in:pending,processing,shipped,completed,canceled,returned,reserved',
        ]);

        if (isset($data['status'])) {
            if ($order->status == 'processing' && $data['status'] === 'canceled') {
                if ($order->payment_status === 'paid') {
                    $order->user->wallet()->increment('balance', $order->total);
                    $order->user->wallet->transactions()->create([
                        'type' => 'credit',
                        'amount' => $order->total,
                        'description' => "Refund for canceled order #{$order->id}",
                    ]);
                }
            }
            $order->status = $data['status'];
        }

        $order->save();

        $notifications->create(
            "تغییر وضعیت",
            " یک سفارش در سیستم تغییر وضعیت پیدا کرد",
            "notification_order",
            ['order' => $order->id]
        );

        try {
            $receiverName = $order->receiver_name ?: $order->user?->full_name;
            $smsService = new SmsService();
            $smsService->sendToKavenegar('change-order-status', $order->user->mobile, $order->id, [
                'token20' => $order->user->getDisplayName($receiverName),
                'token2' => $order->status_label
            ]);
        } catch (\Throwable $e) {
            // جلوگیری از خطا در صورت اختلال پیامک
        }

        return response()->json([
            'message' => 'وضعیت سفارش با موفقیت تغییر کرد',
            'order'   => $order->load(['user', 'product'])
        ]);
    }

    public function todaysOrders()
    {
        $today = Carbon::today();
        $orders = Order::with(['user', 'product'])
            ->whereDate('created_at', $today)
            ->where('status', "processing")
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
            'product_id'       => 'required|exists:products,id',
            'quantity'         => 'nullable|integer|min:1|max:100',
            'payment_method'   => 'required|in:wallet,online,hybrid',
            'coupon_code'      => 'nullable|string',
            'gateway'          => 'required_if:payment_method,online,hybrid|nullable|in:zarinpal,payir,saman',
            'receiver_name'    => 'nullable|string|max:150',
            'receiver_mobile'  => 'nullable|string|max:20',
            'postal_code'      => 'nullable|string|max:20',
            'shipping_address' => 'nullable|string|max:1000',
        ]);

        $product = Product::with('productType')->findOrFail($dataValidated['product_id']);
        $quantity = max(1, (int) ($dataValidated['quantity'] ?? 1));

        // اگر محصول کتاب چاپی است، نشانی پستی الزامی است
        if (($product->productType?->slug === 'printed-book') && empty(trim((string) ($dataValidated['shipping_address'] ?? '')))) {
            return response()->json([
                'message' => 'برای سفارش کتاب چاپی، وارد کردن نشانی پستی الزامی است.',
            ], 422);
        }

        $subtotal = $product->final_price * $quantity;
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

        $total = max(0, $subtotal - $discountAmount);

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

        return DB::transaction(function () use (
            $notifications,
            $user,
            $subtotal,
            $product,
            $quantity,
            $discountAmount,
            $total,
            $fromWallet,
            $toPayOnline,
            $request,
            $dataValidated,
            $coupon,
            $finalStatus,
            $walletBalance
        ) {
            $order = Order::create([
                'user_id'          => $user->id,
                'subtotal'         => $subtotal,
                'product_id'       => $product->id,
                'quantity'         => $quantity,
                'discount_amount'  => $discountAmount,
                'total'            => $total,
                'payment_method'   => $request->payment_method,
                'payment_status'   => $toPayOnline > 0 ? 'pending' : 'paid',
                'status'           => $finalStatus,
                'wallet_payment'   => $fromWallet,
                'online_payment'   => $toPayOnline,
                'receiver_name'    => $dataValidated['receiver_name'] ?? $user->full_name,
                'receiver_mobile'  => $dataValidated['receiver_mobile'] ?? $user->mobile,
                'postal_code'      => $dataValidated['postal_code'] ?? null,
                'shipping_address' => $dataValidated['shipping_address'] ?? null,
            ]);

            if ($coupon) {
                (new CouponService)->applyCoupon($coupon, $user->id);
                $order->coupon_id = $coupon->id;
                $order->save();
            }

            if ($fromWallet > 0 && $user->wallet) {
                $user->wallet->update(['balance' => $walletBalance - $fromWallet]);
                $user->wallet->transactions()->create([
                    'type'        => 'debit',
                    'amount'      => $fromWallet,
                    'description' => "پرداخت برای سفارش #{$order->id}",
                    'order_id'    => $order->id,
                ]);
            }

            if ($toPayOnline > 0) {
                $gateway = $request->get('gateway', config('payment.default', 'zarinpal'));

                $transaction = GatewayTransaction::create([
                    'order_id' => $order->id,
                    'user_id'  => $user->id,
                    'amount'   => $toPayOnline,
                    'gateway'  => $gateway,
                    'status'   => 'pending',
                ]);

                $paymentService = new PaymentService();
                $paymentResult = $paymentService->requestPayment($order, $gateway, [
                    'transaction_id' => $transaction->id,
                    'callback_url'   => route('gateway.callback.show', $transaction->id)
                ]);

                if (!$paymentResult['success']) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'خطا در اتصال به درگاه پرداخت',
                        'error'   => $paymentResult['message'] ?? 'Unknown error'
                    ], 500);
                }

                $notifications->create(
                    "سفارش در انتظار پرداخت",
                    "مبلغ {$toPayOnline} تومان باقی مانده است",
                    "notification_order",
                    ['order' => $order->id]
                );

                return response()->json([
                    'order'        => $order->load(['product']),
                    'payment_info' => [
                        'from_wallet'    => $fromWallet,
                        'to_pay_online'  => $toPayOnline,
                        'transaction_id' => $transaction->id,
                        'gateway'        => $gateway,
                        'gateway_url'    => $paymentResult['payment_url'],
                    ],
                ], 201);
            }

            $message = "سفارش با موفقیت ثبت و پرداخت شد";

            $notifications->create(
                "سفارش تکمیل شد",
                $message,
                "notification_order",
                ['order' => $order->id]
            );

            try {
                $smsService = new SmsService();
                $smsService->sendToKavenegar('customer-order', $user->mobile, $order->id, ['token20' => $user->getDisplayName()]);
                $smsService->sendToAdmins('customer-order-admin', $order->id);
            } catch (\Throwable $e) {
                // جلوگیری از لغو تراکنش در صورت اختلال موقت پنل پیامک
            }

            return response()->json([
                'order'   => $order->load(['product']),
                'message' => $message,
            ], 201);
        });
    }

    public function checkoutSummary(Request $request)
    {
        $user = $request->user();
        $product = Product::findOrFail($request->input('product_id'));
        $quantity = max(1, (int) $request->input('quantity', 1));
        $couponDiscount = 0;
        $coupon = null;
        $subtotal = $product->final_price * $quantity;

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
                'subtotal'        => (int) $subtotal,
                'quantity'        => (int) $quantity,
                'coupon_discount' => (int) $couponDiscount,
                'payable_amount'  => (int) $payable,
            ],
            'coupon' => $coupon?->code ?? null,
        ]);
    }

    public function userDashboardOrders(Request $request)
    {
        $user = $request->user();

        $query = Order::with(['product'])
            ->where('user_id', $user->id);

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($paymentStatus = $request->get('payment_status')) {
            $query->where('payment_status', $paymentStatus);
        }

        if ($fromDate = $request->get('from_date')) {
            $query->whereDate('created_at', '>=', $fromDate);
        }

        if ($toDate = $request->get('to_date')) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        $query->orderBy('created_at', 'desc');
        $orders = $query->paginate(15);

        return response()->json([
            'orders' => $orders,
        ]);
    }

    public function userDashboardOrderDetail(Request $request, $orderId)
    {
        $user = $request->user();

        $order = Order::with([
            'product',
            'user',
        ])->where('id', $orderId)
            ->where('user_id', $user->id)
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