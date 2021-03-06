<?php

namespace App\Validators\Shop;

use Prettus\Validator\LaravelValidator;
use Prettus\Validator\Contracts\ValidatorInterface;

/**
 * Class GoodsTagValidator.
 */
class GoodsTagValidator extends LaravelValidator
{
    /**
     * Validation Rules.
     *
     * @var array
     */
    protected $rules = [
        ValidatorInterface::RULE_CREATE => [
            'name' => 'required',
            'color' => 'required',
            'sort' => 'integer',
            'status' => 'required|in:0,1',
        ],
        ValidatorInterface::RULE_UPDATE => [
            'name' => 'required',
            'color' => 'required',
            'sort' => 'integer',
            'status' => 'required|in:0,1',
        ],
    ];

    protected $messages = [
        'required' => '缺少参数或参数不能为空',
        'integer' => '排序必须是整数',
        'in' => '状态参数错误',
    ];
}
