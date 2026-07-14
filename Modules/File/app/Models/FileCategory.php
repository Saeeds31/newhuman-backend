<?php

namespace Modules\File\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\File\Database\Factories\FileCategoryFactory;

class FileCategory extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'title',
    ];
    protected $table = 'file_category';
    public function files()
    {
        return $this->hasMany(File::class, 'category_id');
    }
}
