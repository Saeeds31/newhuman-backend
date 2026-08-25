<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'meta_title',
        'meta_description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
    public function mainProducts()
    {
        return $this->hasMany(Product::class)
            ->whereIn('product_kind', ['simple', 'parent']);
    }

    public function attributes()
    {
        return $this->hasMany(ProductAttribute::class);
    }
}
