<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Users\Models\User;
use Carbon\Carbon;
use Modules\Products\Models\Product;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subtotal',
        'discount_amount',
        'total',
        'product_id',
        'quantity',
        'payment_method',
        'payment_status',
        'status',
        'wallet_payment',
        'online_payment',
        'coupon_id',
        'receiver_name',
        'receiver_mobile',
        'postal_code',
        'shipping_address',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function getStatusLabelAttribute()
    {
        $statuses = [
            'pending' => 'در انتظار پرداخت',
            'processing' => 'در حال پردازش',
            'paid' => 'پرداخت شده',
            'shipped' => 'ارسال شده',
            'delivered' => 'تحویل داده شده',
            'cancelled' => 'لغو شده',
            'canceled' => 'لغو شده',
            'completed' => 'کامل شده',
            'returned' => 'مرجوع شده',
            'failed' => 'ناموفق',
        ];

        return $statuses[$this->status] ?? $this->status;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public static function dashboardReport()
    {
        return [
            'total_orders'   => self::count(),
            'total_sales'    => self::sum('total'),
            'total_discount' => self::sum('discount_amount'),
            'today_orders'   => self::whereDate('created_at', Carbon::today())->count(),
            'month_orders'   => self::whereMonth('created_at', Carbon::now()->month)->count(),
        ];
    }
}