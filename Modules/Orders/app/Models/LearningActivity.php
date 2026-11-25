<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

class LearningActivity extends Model
{
    protected $table = 'learning_activities';

    protected $fillable = [
        'user_id',
        'product_id',
        'episode_id',
        'action_type',
        'description',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
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