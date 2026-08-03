<?php

namespace Modules\Discourse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Discourse\Database\Factories\DiscourseCategoryFactory;

class DiscourseCategory extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'title',
        'slug',
        'meta_title',
        'meta_description',
        'description'
    ];
    protected $table = "discourse_categories";

    // protected static function newFactory(): DiscourseCategoryFactory
    // {
    //     // return DiscourseCategoryFactory::new();
    // }
}
