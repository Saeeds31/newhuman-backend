<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationBooking extends Model
{
    protected $table = 'consultation_bookings';

    protected $fillable = [
        'tracking_code',
        'user_id',
        'full_name',
        'phone',
        'session_type',
        'topic',
        'description',
        'booking_date_gregorian',
        'booking_date_jalali',
        'booking_time',
        'deposit_amount',
        'status',
        'source',
        'payment_gateway',
        'payment_ref_id',
        'locked_until',
    ];

    protected $casts = [
        'locked_until' => 'datetime',
    ];
}