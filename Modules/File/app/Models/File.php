<?php

namespace Modules\File\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Comments\Models\Comment;

// use Modules\File\Database\Factories\FileFactory;

class File extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'title',
        'file',
        'file_type',
        'category_id'
    ];
    public function category()
    {
        return $this->belongsTo(FileCategory::class);
    }
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
    public function approvedComments()
    {
        return $this->morphMany(Comment::class, 'commentable')
            ->where('status', 'approved');
    }
    public function parentComments()
    {
        return $this->morphMany(Comment::class, 'commentable')
            ->whereNull('parent_id')
            ->where('status', 'approved');
    }
}
