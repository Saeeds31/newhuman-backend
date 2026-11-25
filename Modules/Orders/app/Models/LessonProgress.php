<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

class LessonProgress extends Model
{
    protected $table = 'lesson_progress';

    protected $fillable = [
        'user_id',
        'product_id',
        'episode_id',
        'is_completed',
        'watched_seconds',
        'progress_percentage',
        'last_watched_at',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'watched_seconds' => 'integer',
        'progress_percentage' => 'integer',
        'last_watched_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}