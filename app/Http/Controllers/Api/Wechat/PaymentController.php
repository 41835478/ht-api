<?php

namespace App\Http\Controllers\Api\Wechat;

use Carbon\Carbon;
use App\Events\Upgrade;
use App\Models\Taoke\Pid;
use App\Models\User\User;
use App\Models\User\Payment;
use App\Events\SendNotification;
use Overtrue\LaravelWeChat\Facade;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Repositories\Interfaces\User\PaymentRepository;

/**
 * Class PaymentController.
 */
class PaymentController extends Controller
{
    /**
     * @var PaymentRepository
     */
    protected $repository;

    /**
     * PaymentController constructor.
     * @param PaymentRepository $repository
     */
    public function __construct(PaymentRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * 微信H5支付.
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function h5()
    {
        try {
            $prepayId = request('prepayId');
            if (! $prepayId) {
                throw  new \InvalidArgumentException('支付失败，缺少prepayId');
            }

            $app = Facade::payment();
            $config = $app->jssdk->sdkConfig($prepayId);

            return response(1001, '支付参数获取成功', $config);
        } catch (\Exception $e) {
            return response(4001, $e->getMessage());
        }
    }

    /**
     * 支付.
     * @return \Illuminate\Http\JsonResponse
     */
    public function app()
    {
        //APP支付  https://www.easywechat.com/docs/master/payment/order

        try {
            $user = getUser();
            $group = $user->group;
            //淘宝推广位是否存在
            $pid = Pid::query()->whereNull('agent_id')->where('user_id', $group->user_id)->whereNotNull('taobao')->first();
            if (! $pid) {
                throw new \InvalidArgumentException('升级失败，pid不够');
            }

            $app = Facade::payment();

            $payType = request('pay_type');
            $title = '微信支付';
            $totaFee = 0;
            if ($payType == 'level') {
                $totaFee = $this->getLevelPrice();
            }

            if ($totaFee == 0) {
                throw  new \Exception('付款金额不能为0');
            }

            if ($user->wx_openid1 == null) {
                throw  new \Exception('请先授权app微信登录');
            }
            $number = date('YmdHis').rand(10000, 99999);
            $result = $app->order->unify([
                'body' => $title,
                'out_trade_no' => $number,
                'total_fee' => $totaFee,
                'notify_url' => env('APP_URL').'/api/wechat/payment/notify', // 支付结果通知网址，如果不设置则会使用配置里的默认地址
                'trade_type' => 'APP', // 请对应换成你的支付方式对应的值类型
                'openid' => $user->wx_openid1,
            ]);

            if ($result['return_code'] == 'SUCCESS' && $result['result_code'] == 'SUCCESS') {
                $other = [
                    'level_id' => request('level_id'), //TODO 存入原有等级id
                    'type' => request('type'),
                ];
                $insert = [
                    'user_id' => $user->id,
                    'out_trade_no' => $number,
                    'type' => 1,
                    'status' => 2,
                    'other' => json_encode($other),
                ];

                $this->repository->create($insert);

                $result = $app->jssdk->appConfig($result['prepay_id']); //第二次签名
                return json(1001, '支付信息请求成功', $result);
            } else {
                throw new \InvalidArgumentException('微信支付签名失败');
            }
        } catch (\Exception $e) {
            return json(5001, $e->getMessage());
        }
    }

    /**
     * 回调获取支付后的金额.
     * @throws \Exception
     */
    public function notify()
    {
        $app = Facade::payment();
        $response = $app->handlePaidNotify(function ($message, $fail) {
            Log::info(json_encode($message));

            $payment = Payment::query()->where('out_trade_no', $message['out_trade_no'])->first();

            if (! $payment || $payment->status == 1) { // 如果订单不存在 或者 订单已经支付过了
                return true;      // 告诉微信，我已经处理完了，订单没找到，别再通知我了 //TODO  需要考虑退款情况
            }
            if ($message['return_code'] === 'SUCCESS') { // return_code 表示通信状态，不代表支付状态
                // 用户是否支付成功
                if ($message['result_code'] === 'SUCCESS') {
                    $payment->update([
                        'transaction_id' => $message['transaction_id'],
                        'price' => $message['total_fee'] / 100,
                        'status' => 1,
                        'payment_at' => Carbon::now()->toDateTimeString(),
                    ]);
                    $other = json_decode($payment->other);
                    $level_id = $other->level_id;
                    $type = $other->type; //1月2季3年4永久

                    if (! in_array($type, [1, 2, 3, 4])) {
                        throw new \InvalidArgumentException('传参错误');
                    } else {
                        $column = 'price'.$type;
                    }

                    $level = db('user_levels')->find($level_id);
                    if (! $level) {
                        throw new \InvalidArgumentException('等级不存在');
                    }

                    if ($level->$column == $message['total_fee'] / 100) {
                        if ($type == 1) {
                            $min = '一月'.$level->name;
                            $time = now()->addDays(30)->toDateTimeString(); //月
                        } elseif ($type == 2) {
                            $min = '一季'.$level->name;
                            $time = now()->addMonths(3)->toDateTimeString(); //季
                        } elseif ($type == 3) {
                            $min = '一年'.$level->name;
                            $time = now()->addYears(1)->toDateTimeString(); //年
                        } else {
                            $min = '永久'.$level->name;
                            $time = null; //永久
                        }

                        $user = User::query()->find($payment->user_id);

                        event(new Upgrade($user, $level));
                        $user->update([
                            'level_id' => $level->id,
                            'expired_time' => $time,
                        ]);
                        $user['title'] = '购买升级';
                        $user['message'] = '恭喜你成功升级为'.$min.',到期时间为'.$time != null ? $time : '永久';
                        event(new SendNotification($user->toArray()));
                    } else {
                        throw new \InvalidArgumentException('升级失败');
                    }
                    // 用户支付失败
                } elseif ($message['result_code'] === 'FAIL') {
                    $payment->update([
                        'transaction_id' => $message['transaction_id'],
                        'price' => $message['total_fee'] / 100,
                        'status' => 2,
                    ]);
                }
            } else {
                return $fail('通信失败，请稍后再通知我');
            }

            return true;
        });

        return $response;
    }

    /**
     * 获取升级金额.
     * @return \Illuminate\Database\Query\Builder|mixed
     * @throws \Exception
     */
    protected function getLevelPrice()
    {
        $user = getUser();
        $level_id = request('level_id');
        $type = request('type'); //1月2季3年4永久

        if (! in_array($type, [1, 2, 3, 4])) {
            throw new \InvalidArgumentException('传参错误');
        } else {
            $column = 'price'.$type;
        }

        $level = db('user_levels')->find($level_id);
        if (! $level) {
            throw new \InvalidArgumentException('等级不存在');
        }
        $level_user = db('user_levels')->find($user->level_id);

        if ($level_user->level > $level->level) {
            throw new \InvalidArgumentException('当前等级大于所要升级的等级');
        }

        return $level->$column * 100;
    }
}
