<?php
// Modules/Products/Models/Product.php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Categories\Models\Category;
use Illuminate\Support\Collection;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_type_id',
        'parent_id',
        'product_kind', // simple, parent, child
        'child_type', // online, in_person, recorded
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
        // فیلدهای جدید
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

    // محصول والد (برای فرزندان)
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_id');
    }

    // فرزندان محصول (برای والد)
    public function children(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_id')
            ->where('product_kind', 'child')
            ->orderBy('sort_order');
    }

    // فرزندان فعال (برای والد)
    public function activeChildren(): HasMany
    {
        return $this->children()->where('is_variation_active', true);
    }

    // محصولات والد (همه‌ی محصولاتی که فرزند دارند)
    public function scopeParents($query)
    {
        return $query->where('product_kind', 'parent');
    }

    // محصولات ساده (بدون والد و بدون فرزند)
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

    /**
     * آیا این محصول والد است؟
     */
    public function isParent(): bool
    {
        return $this->product_kind === 'parent';
    }

    /**
     * آیا این محصول فرزند است؟
     */
    public function isChild(): bool
    {
        return $this->product_kind === 'child';
    }

    /**
     * آیا این محصول ساده است؟
     */
    public function isSimple(): bool
    {
        return $this->product_kind === 'simple';
    }

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

    /**
     * دریافت قیمت برای یک نوع خاص (فرزند)
     */
    public function getPriceForChild(Product $child): int
    {
        return $child->child_discount_price ?? $child->child_price ?? $child->final_price ?? 0;
    }

    /**
     * دریافت محدوده قیمت برای نمایش در کارت محصول
     */
    public function getPriceRangeAttribute(): string
    {
        if (!$this->isParent()) {
            return $this->isFree() ? 'رایگان' : number_format($this->final_price) . ' تومان';
        }

        $prices = $this->activeChildren->pluck('child_price')->filter()->toArray();

        if (empty($prices)) {
            return 'رایگان';
        }

        $min = min($prices);
        $max = max($prices);

        if ($min === $max) {
            return number_format($min) . ' تومان';
        }

        return 'از ' . number_format($min) . ' تا ' . number_format($max) . ' تومان';
    }

    /**
     * دریافت کمترین قیمت
     */
    public function getStartingPriceAttribute(): int
    {
        if (!$this->isParent()) {
            return $this->final_price ?? 0;
        }

        return $this->activeChildren->min('child_price') ?? 0;
    }

    /**
     * دریافت انواع قابل خرید (برای محصولات والد)
     */
    public function getAvailableChildTypesAttribute(): Collection
    {
        if (!$this->isParent()) {
            return collect();
        }

        return $this->activeChildren->map(function ($child) {
            $typeLabels = [
                'online' => 'آنلاین',
                'in_person' => 'حضوری',
                'recorded' => 'ضبط شده',
            ];

            $child->type_label = $typeLabels[$child->child_type] ?? $child->child_type;

            return $child;
        });
    }

    /**
     * آیا این محصول نوع خاصی دارد؟
     */
    public function hasChildType(string $type): bool
    {
        return $this->activeChildren->contains('child_type', $type);
    }

    /**
     * دریافت موجودی یا ظرفیت باقی‌مانده
     */
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

    /**
     * آیا موجودی/ظرفیت تمام شده؟
     */
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

    /**
     * برچسب وضعیت برای نمایش
     */
    public function getStockStatusAttribute(): string
    {
        if (!$this->isChild()) {
            return '';
        }

        if ($this->isOutOfStock()) {
            return 'تکمیل شده';
        }

        $available = $this->available_stock;

        if ($available === null) {
            return 'موجود';
        }

        if ($available <= 5) {
            return 'چند نفر باقی‌مانده';
        }

        return 'موجود';
    }

    /**
     * برچسب روی کارت (برای محصولات والد)
     */
    public function getCardBadgeAttribute(): string
    {
        if ($this->isFree()) {
            return 'رایگان';
        }

        if ($this->isParent()) {
            $hasFreeChild = $this->activeChildren->contains('child_price', 0);
            if ($hasFreeChild) {
                return 'دارای نسخه رایگان';
            }
        }

        return '';
    }

    /**
     * ساخت اسلاگ منحصر‌به‌فرد برای فرزندان
     */
    public function getUniqueSlug(): string
    {
        if (!$this->isChild()) {
            return $this->slug;
        }

        $parent = $this->parent;
        if (!$parent) {
            return $this->slug;
        }

        return $parent->slug . '-' . $this->child_type;
    }
}
