<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationWeeklySchedule extends Model
{
    protected $table = 'consultation_weekly_schedules';

    protected $fillable = [
        'day_of_week',
        'shift',
        'start_time',
        'end_time',
        'session_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}