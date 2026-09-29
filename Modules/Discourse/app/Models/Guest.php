<?php

namespace Modules\Discourse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Discourse\Database\Factories\GuestFactory;

class Guest extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $table='guests';
    protected $fillable = [
        'full_name',
        'avatar',
        'position',
        'biography'
    ];

    // protected static function newFactory(): GuestFactory
    // {
    //     // return GuestFactory::new();
    // }
}
