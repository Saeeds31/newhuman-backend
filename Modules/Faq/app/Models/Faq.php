<?php
// Modules/Faq/Models/Faq.php

namespace Modules\Faq\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Products\Models\Product;

class Faq extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'question',
        'answer',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // ========== روابط ==========

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'faq_product')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    // ========== Scopes ==========

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }


    // ========== Helpers ==========

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
