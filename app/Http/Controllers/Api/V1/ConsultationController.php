<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConsultationBooking;
use App\Models\ConsultationSetting;
use App\Models\ConsultationWeeklySchedule;
use App\Services\ConsultationAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsultationController extends Controller
{
    /**
     * دریافت تقویم شمسی، اسلات‌های آزاد/پر، زودترین زمان خالی و مبلغ بیعانه (به تومان)
     */
    public function availability(Request $request, ConsultationAvailabilityService $service)
    {
        $type = in_array($request->query('type'), ['online', 'offline'])
            ? $request->query('type')
            : 'online';

        return response()->json([
            'status' => true,
            'data' => $service->getAvailability($type),
        ]);
    }

    /**
     * ثبت درخواست رزرو جلسه توسط کاربر و قفل موقت اسلات تا زمان پرداخت بیعانه
     */
    public function book(Request $request)
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'session_type' => 'required|in:online,offline',
            'topic' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'booking_date_gregorian' => 'required|date',
            'booking_date_jalali' => 'required|string|max:12',
            'booking_time' => 'required|string|max:5',
            'payment_gateway' => 'required|in:wallet,melat,shahr',
        ]);

        $nowTehran = Carbon::now('Asia/Tehran');
        $setting = ConsultationSetting::first();
        $deposit = (int) ($setting->deposit_amount ?? 500000);
        $lockTtl = (int) ($setting->lock_ttl_minutes ?? 10);

        return DB::transaction(function () use ($validated, $nowTehran, $deposit, $lockTtl, $request) {
            $exists = ConsultationBooking::where('booking_date_gregorian', $validated['booking_date_gregorian'])
                ->where('booking_time', $validated['booking_time'])
                ->where(function ($q) use ($nowTehran) {
                    $q->whereIn('status', ['confirmed', 'manual_booked', 'blocked'])
                        ->orWhere(function ($sub) use ($nowTehran) {
                            $sub->where('status', 'pending_payment')
                                ->where('locked_until', '>', $nowTehran);
                        });
                })
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => false,
                    'message' => 'این بازه زمانی دقایقی پیش توسط کاربر دیگری انتخاب شد. لطفاً زمان دیگری را انتخاب کنید.',
                ], 409);
            }

            $booking = ConsultationBooking::create([
                'tracking_code' => 'NM-' . strtoupper(Str::random(6)),
                'user_id' => optional($request->user())->id,
                'full_name' => $validated['full_name'],
                'phone' => $validated['phone'],
                'session_type' => $validated['session_type'],
                'topic' => $validated['topic'],
                'description' => $validated['description'] ?? null,
                'booking_date_gregorian' => $validated['booking_date_gregorian'],
                'booking_date_jalali' => $validated['booking_date_jalali'],
                'booking_time' => $validated['booking_time'],
                'deposit_amount' => $deposit,
                'status' => 'pending_payment',
                'source' => 'website',
                'payment_gateway' => $validated['payment_gateway'],
                'locked_until' => $nowTehran->copy()->addMinutes($lockTtl),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'نوبت شما موقتاً ثبت شد. در حال انتقال به درگاه پرداخت بیعانه...',
                'data' => [
                    'booking' => $booking,
                    'deposit_amount' => $deposit,
                    'payment_url' => null,
                ],
            ]);
        });
    }

    // ==================== متدهای پنل ادمین ====================

    public function adminSettings()
    {
        return response()->json([
            'status' => true,
            'data' => [
                'settings' => ConsultationSetting::first(),
                'schedules' => ConsultationWeeklySchedule::orderBy('day_of_week')->get(),
            ],
        ]);
    }

    public function adminUpdateSettings(Request $request)
    {
        $data = $request->validate([
            'deposit_amount' => 'required|integer|min:0',
            'slot_duration_minutes' => 'required|integer|min:15|max:180',
            'advance_days' => 'required|integer|min:1|max:60',
            'schedules' => 'required|array',
        ]);

        $setting = ConsultationSetting::firstOrCreate([]);
        $setting->update([
            'deposit_amount' => $data['deposit_amount'],
            'slot_duration_minutes' => $data['slot_duration_minutes'],
            'advance_days' => $data['advance_days'],
        ]);

        ConsultationWeeklySchedule::truncate();
        foreach ($data['schedules'] as $sch) {
            ConsultationWeeklySchedule::create([
                'day_of_week' => $sch['day_of_week'],
                'shift' => $sch['shift'],
                'start_time' => $sch['start_time'],
                'end_time' => $sch['end_time'],
                'session_type' => $sch['session_type'] ?? 'both',
                'is_active' => (bool) ($sch['is_active'] ?? true),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'تنظیمات بیعانه و برنامه هفتگی ذخیره شد.',
        ]);
    }

    public function adminBookings(Request $request)
    {
        $query = ConsultationBooking::query()
            ->orderByDesc('booking_date_gregorian')
            ->orderBy('booking_time');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('date_jalali')) {
            $query->where('booking_date_jalali', $request->query('date_jalali'));
        }
        if ($request->filled('search')) {
            $s = $request->query('search');
            $query->where(function ($q) use ($s) {
                $q->where('full_name', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%")
                    ->orWhere('tracking_code', 'like', "%{$s}%")
                    ->orWhere('topic', 'like', "%{$s}%");
            });
        }

        return response()->json([
            'status' => true,
            'data' => $query->paginate(20),
        ]);
    }

    public function adminManualSlot(Request $request)
    {
        $data = $request->validate([
            'booking_date_gregorian' => 'required|date',
            'booking_date_jalali' => 'required|string|max:12',
            'booking_time' => 'required|string|max:5',
            'session_type' => 'required|in:online,offline',
            'status' => 'required|in:manual_booked,blocked',
            'full_name' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:20',
            'topic' => 'nullable|string|max:255',
            'deposit_amount' => 'nullable|integer|min:0',
        ]);

        $booking = ConsultationBooking::create([
            'tracking_code' => 'ADM-' . strtoupper(Str::random(6)),
            'full_name' => $data['full_name'] ?: ($data['status'] === 'blocked' ? 'مسدودشده توسط ادمین' : 'رزرو حضوری/تلفنی'),
            'phone' => $data['phone'] ?: '-',
            'session_type' => $data['session_type'],
            'topic' => $data['topic'] ?: 'هماهنگی مستقیم',
            'booking_date_gregorian' => $data['booking_date_gregorian'],
            'booking_date_jalali' => $data['booking_date_jalali'],
            'booking_time' => $data['booking_time'],
            'deposit_amount' => (int) ($data['deposit_amount'] ?? 0),
            'status' => $data['status'],
            'source' => 'admin',
            'payment_gateway' => 'admin_manual',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'وضعیت اسلات در تقویم ثبت شد.',
            'data' => $booking,
        ]);
    }

    public function adminUpdateBookingStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => 'required|in:confirmed,manual_booked,blocked,cancelled',
        ]);

        $booking = ConsultationBooking::findOrFail($id);
        $booking->update(['status' => $data['status']]);

        return response()->json([
            'status' => true,
            'message' => 'وضعیت نوبت به‌روزرسانی شد.',
            'data' => $booking,
        ]);
    }
}