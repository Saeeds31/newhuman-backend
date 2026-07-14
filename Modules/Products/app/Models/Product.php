<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Categories\Models\Category;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_type_id',
        'title',
        'description',
        'main_image',
        'meta_title',
        'meta_description',
        'status',
        'price',
        'final_price',
        'discount_value',
        'discount_type',
        'is_free',
        'video',
    ];

    protected $casts = [
        'is_free' => 'boolean',
        'price' => 'integer',
        'final_price' => 'integer',
        'discount_value' => 'integer',
    ];

    // ---------------------------
    // روابط
    // ---------------------------

    public function productType()
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }

    public function files()
    {
        return $this->hasMany(ProductFile::class)->orderBy('sort_order');
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    public function attributeValues()
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    // ---------------------------
    // Scopes
    // ---------------------------

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    // ---------------------------
    // Helpers
    // ---------------------------

    /**
     * آیا کل محصول رایگان است؟
     */
    public function isFree(): bool
    {
        return $this->is_free || (int) $this->price === 0;
    }

    /**
     * آیا فایل مشخصی از این محصول، مستقل از خریداری شدن محصول، قابل دانلود رایگان است؟
     */
    public function isFileFree(ProductFile $file): bool
    {
        return $this->isFree() || $file->is_free;
    }
}
