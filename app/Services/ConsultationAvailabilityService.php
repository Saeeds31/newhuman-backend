<?php

namespace App\Services;

use App\Models\ConsultationBooking;
use App\Models\ConsultationSetting;
use App\Models\ConsultationWeeklySchedule;
use Carbon\Carbon;

class ConsultationAvailabilityService
{
    private const PERSIAN_DAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];

    private const PERSIAN_MONTHS = [
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    ];

    public function getAvailability(string $sessionType = 'online'): array
    {
        $tz = 'Asia/Tehran';
        $nowTehran = Carbon::now($tz);

        $setting = ConsultationSetting::first() ?? new ConsultationSetting([
            'deposit_amount' => 500000,
            'slot_duration_minutes' => 30,
            'advance_days' => 14,
            'lock_ttl_minutes' => 10,
        ]);

        $schedules = ConsultationWeeklySchedule::where('is_active', true)
            ->whereIn('session_type', [$sessionType, 'both'])
            ->get()
            ->groupBy('day_of_week');

        $startDate = $nowTehran->copy()->startOfDay();
        $endDate = $startDate->copy()->addDays($setting->advance_days);

        // رزروهای قطعی، نوبت‌های دستی/مسدود ادمین و قفل‌های پرداخت منقضی‌نشده
        $occupied = ConsultationBooking::whereBetween('booking_date_gregorian', [
            $startDate->toDateString(),
            $endDate->toDateString(),
        ])
            ->where(function ($q) use ($nowTehran) {
                $q->whereIn('status', ['confirmed', 'manual_booked', 'blocked'])
                    ->orWhere(function ($sub) use ($nowTehran) {
                        $sub->where('status', 'pending_payment')
                            ->where('locked_until', '>', $nowTehran);
                    });
            })
            ->get()
            ->groupBy('booking_date_gregorian');

        $days = [];
        $earliestSlot = null;

        for ($i = 0; $i < $setting->advance_days; $i++) {
            $cursor = $startDate->copy()->addDays($i);
            // تبدیل روز هفته میلادی (0=یکشنبه..6=شنبه) به هفته ایران (0=شنبه..6=جمعه)
            $iranDayOfWeek = ($cursor->dayOfWeek + 1) % 7;
            $daySchedules = $schedules->get($iranDayOfWeek);

            if (!$daySchedules || $daySchedules->isEmpty()) {
                continue;
            }

            $gregorianStr = $cursor->toDateString();
            [$jy, $jm, $jd] = $this->gregorianToJalali($cursor->year, $cursor->month, $cursor->day);
            $jalaliStr = sprintf('%04d-%02d-%02d', $jy, $jm, $jd);
            $dayName = self::PERSIAN_DAYS[$iranDayOfWeek];
            $dateLabel = $jd . ' ' . self::PERSIAN_MONTHS[$jm];

            $takenTimes = ($occupied->get($gregorianStr) ?? collect())
                ->pluck('booking_time')
                ->flip()
                ->toArray();

            $morningSlots = [];
            $afternoonSlots = [];

            foreach ($daySchedules as $sch) {
                $slots = $this->generateTimeSlots(
                    $cursor,
                    $sch->start_time,
                    $sch->end_time,
                    (int) $setting->slot_duration_minutes,
                    $nowTehran,
                    $takenTimes
                );

                if ($sch->shift === 'morning') {
                    $morningSlots = array_merge($morningSlots, $slots);
                } else {
                    $afternoonSlots = array_merge($afternoonSlots, $slots);
                }
            }

            if (empty($morningSlots) && empty($afternoonSlots)) {
                continue;
            }

            $hasAvailable = collect($morningSlots)->merge($afternoonSlots)->contains('status', 'available');
            if (!$hasAvailable) {
                continue;
            }

            if ($earliestSlot === null) {
                $firstFree = collect($morningSlots)->merge($afternoonSlots)->firstWhere('status', 'available');
                if ($firstFree) {
                    $earliestSlot = [
                        'date_gregorian' => $gregorianStr,
                        'date_jalali' => $jalaliStr,
                        'day_name' => $dayName,
                        'date_label' => $dateLabel,
                        'full_label' => "{$dayName} {$dateLabel} - ساعت {$firstFree['time']}",
                        'time' => $firstFree['time'],
                        'shift' => $firstFree['shift'],
                    ];
                }
            }

            $days[] = [
                'date_gregorian' => $gregorianStr,
                'date_jalali' => $jalaliStr,
                'day_name' => $dayName,
                'date_label' => $dateLabel,
                'morning' => $morningSlots,
                'afternoon' => $afternoonSlots,
            ];
        }

        return [
            'timezone' => $tz,
            'deposit_amount' => (int) $setting->deposit_amount,
            'slot_duration_minutes' => (int) $setting->slot_duration_minutes,
            'earliest_slot' => $earliestSlot,
            'days' => $days,
        ];
    }

    private function generateTimeSlots(
        Carbon $day,
        string $startTime,
        string $endTime,
        int $duration,
        Carbon $nowTehran,
        array $takenTimes
    ): array {
        [$sh, $sm] = array_map('intval', explode(':', $startTime));
        [$eh, $em] = array_map('intval', explode(':', $endTime));

        $slotStart = $day->copy()->setTime($sh, $sm, 0);
        $slotEndLimit = $day->copy()->setTime($eh, $em, 0);
        $shift = $sh < 13 ? 'morning' : 'afternoon';
        $list = [];

        while ($slotStart->copy()->addMinutes($duration)->lte($slotEndLimit)) {
            $timeStr = $slotStart->format('H:i');
            $isPast = $slotStart->lte($nowTehran->copy()->addMinutes(15));
            $isFull = isset($takenTimes[$timeStr]);

            if (!$isPast) {
                $list[] = [
                    'time' => $timeStr,
                    'shift' => $shift,
                    'status' => $isFull ? 'full' : 'available',
                ];
            }

            $slotStart->addMinutes($duration);
        }

        return $list;
    }

    public function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + ((int) (($gy2 + 3) / 4)) - ((int) (($gy2 + 99) / 100)) + ((int) (($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * ((int) ($days / 12053)));
        $days %= 12053;
        $jy += 4 * ((int) ($days / 1461));
        $days %= 1461;
        if ($days > 365) {
            $jy += (int) (($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        $jm = ($days < 186) ? 1 + (int) ($days / 31) : 7 + (int) (($days - 186) / 30);
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));

        return [$jy, $jm, $jd];
    }
}