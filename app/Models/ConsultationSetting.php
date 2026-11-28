<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationSetting extends Model
{
    protected $table = 'consultation_settings';

    protected $fillable = [
        'deposit_amount',
        'slot_duration_minutes',
        'advance_days',
        'lock_ttl_minutes',
    ];
}