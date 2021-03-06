<?php

namespace App\Repositories\Taoke;

use App\Models\Taoke\DianTag;
use App\Criteria\RequestCriteria;
use App\Validators\Taoke\DianTagValidator;
use Prettus\Repository\Eloquent\BaseRepository;
use App\Repositories\Interfaces\Taoke\DianTagRepository;

/**
 * Class DianRepositoryEloquent.
 */
class DianTagRepositoryEloquent extends BaseRepository implements DianTagRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name'=>'like',
    ];

    /**
     * Specify Model class name.
     *
     * @return string
     */
    public function model()
    {
        return DianTag::class;
    }

    /**
     * @return null|string
     */
    public function validator()
    {
        return DianTagValidator::class;
    }

    /**
     * @throws \Prettus\Repository\Exceptions\RepositoryException
     */
    public function boot()
    {
        $this->pushCriteria(app(RequestCriteria::class));
    }
}
