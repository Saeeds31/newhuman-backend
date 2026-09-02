<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'title',
        'description',
        'path',
        'original_name',
        'extension',
        'size',
        'is_free',
        'sort_order',
    ];

    protected $casts = [
        'is_free' => 'boolean',
        'size' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function view()
    {
        return $this->hasOne(View::class);
    }
}
