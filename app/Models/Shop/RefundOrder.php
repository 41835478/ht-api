<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Model;
use Prettus\Repository\Contracts\Transformable;
use Prettus\Repository\Traits\TransformableTrait;

/**
 * Class RefundOrder.
 */
class RefundOrder extends Model implements Transformable
{
    use TransformableTrait;

    /**
     * @var string
     */
    protected $table = 'shop_refund_orders';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [];

    /**
     * @var array
     */
    protected $hidden = [
        'order_goods_id',
    ];

    /**
     * 所属商品
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function goods()
    {
        return $this->belongsTo('App\Models\Shop\OrderGoods', 'order_goods_id')->withDefault(null);
    }

    /**
     * 所属订单.
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order()
    {
        return $this->belongsTo('App\Models\Shop\Order', 'order_id')->withDefault(null);
    }

    /**
     * 所属用户.
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo('App\Models\User\User')->withDefault(null);
    }

    /**
     * @return array
     */
    public function transform()
    {
        return [
            'id' => $this->id,
            'refundno' => $this->refundno,
            'price' => $this->price,
            'apply_price' => $this->apply_price,
            'reason' => $this->reason,
            'image' => $this->image,
            'content' => $this->content,
            'refund_address' => json_decode($this->refund_address) ?? '',
            'reply' => $this->reply,
            'type' => $this->type,
            'refund_type' => $this->refund_type,
            'send_time' => $this->send_time,
            'operate_time' => $this->operate_time,
            'return_ime' => $this->return_ime,
            'finish_time' => $this->finish_time,
        ];
    }
}
