<?php

namespace Modules\Discourse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Discourse\Database\Factories\DiscourseFactory;

class Discourse extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'title',
        'discourse_with',
        'video',
        'main_image',
        'short_description',
        'description',
        'discourse_category_id'
    ];
    protected $table = "discourses";
    public function category()
    {
        return $this->belongsTo(DiscourseCategory::class);
    }
}
