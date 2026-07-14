<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductAttribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_type_id',
        'name',
        'slug',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function productType()
    {
        return $this->belongsTo(ProductType::class);
    }

    public function values()
    {
        return $this->hasMany(ProductAttributeValue::class);
    }
}