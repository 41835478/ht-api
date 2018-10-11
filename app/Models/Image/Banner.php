<?php

namespace App\Models\Image;

use Illuminate\Database\Eloquent\Model;
use Prettus\Repository\Contracts\Transformable;
use Prettus\Repository\Traits\TransformableTrait;

/**
 * Class Banner.
 */
class Banner extends Model implements Transformable
{
    use TransformableTrait;

    /**
     * @var string
     */
    protected $table = "banners";

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'image',
        'description',
        'url',
        'sort',
        'tag',
        'status',
    ];

    protected $hidden = [
        'user_id',
    ];

}
