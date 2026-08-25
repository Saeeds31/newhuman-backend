<?php
// Modules/Products/Models/Product.php

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
        'parent_id',
        'product_kind', // simple, parent
        'child_type', // online, in_person, recorded (فقط برای فرزندان)
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
        // فیلدهای جدید برای فرزندان
        'child_price',
        'child_discount_price',
        'meeting_link',
        'location',
        'max_attendees',
        'sold_count',
        'sku',
        'stock',
        'start_date',
        'end_date',
        'is_variation_active',
        'sort_order',
    ];

    protected $casts = [
        'is_free' => 'boolean',
        'price' => 'integer',
        'final_price' => 'integer',
        'discount_value' => 'integer',
        'child_price' => 'integer',
        'child_discount_price' => 'integer',
        'sold_count' => 'integer',
        'stock' => 'integer',
        'max_attendees' => 'integer',
        'is_variation_active' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    // ========== روابط ==========

    // محصول والد
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_id');
    }

    // فرزندان محصول (فقط برای والد)
    public function children(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_id')
            ->where('product_kind', 'child')
            ->orderBy('sort_order');
    }

    // فرزندان فعال
    public function activeChildren(): HasMany
    {
        return $this->children()->where('is_variation_active', true);
    }

    // محصولات والد
    public function scopeParents($query)
    {
        return $query->where('product_kind', 'parent');
    }

    // محصولات ساده
    public function scopeSimple($query)
    {
        return $query->where('product_kind', 'simple');
    }

    // محصولات فرزند
    public function scopeChildren($query)
    {
        return $query->where('product_kind', 'child');
    }

    // ========== روابط موجود ==========

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProductFile::class)->orderBy('sort_order');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    // ========== Scopes ==========

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    // ========== Helpers ==========

    public function isParent(): bool
    {
        return $this->product_kind === 'parent';
    }

    public function isChild(): bool
    {
        return $this->product_kind === 'child';
    }

    public function isSimple(): bool
    {
        return $this->product_kind === 'simple';
    }

    public function isFree(): bool
    {
        return $this->is_free || (int) $this->price === 0;
    }

    public function isFileFree(ProductFile $file): bool
    {
        return $this->isFree() || $file->is_free;
    }

    // دریافت قیمت برای فرزند
    public function getPriceForChild(): int
    {
        return $this->child_discount_price ?? $this->child_price ?? 0;
    }

    // برچسب نوع فرزند
    public function getChildTypeLabelAttribute(): string
    {
        $labels = [
            'online' => 'آنلاین',
            'in_person' => 'حضوری',
            'recorded' => 'ضبط شده',
        ];
        return $labels[$this->child_type] ?? $this->child_type;
    }

    // موجودی باقی‌مانده
    public function getAvailableStockAttribute(): ?int
    {
        if (!$this->isChild()) {
            return null;
        }

        if ($this->child_type === 'in_person') {
            return $this->max_attendees - $this->sold_count;
        }

        return $this->stock;
    }

    // وضعیت موجودی
    public function isOutOfStock(): bool
    {
        if (!$this->isChild()) {
            return false;
        }

        if ($this->child_type === 'in_person') {
            return $this->sold_count >= $this->max_attendees;
        }

        return $this->stock <= 0;
    }
}