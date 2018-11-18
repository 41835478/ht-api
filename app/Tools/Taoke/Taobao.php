<?php

namespace App\Tools\Taoke;

use Illuminate\Support\Carbon;
use Ixudra\Curl\Facades\Curl;
use App\Models\Taoke\Favourite;
use Illuminate\Support\Facades\DB;
use Orzcc\TopClient\Facades\TopClient;
use TopClient\request\TbkTpwdCreateRequest;
use TopClient\request\TbkItemInfoGetRequest;

class Taobao implements TBKInterface
{
    use TBKCommon;

    /**
     * 获取优惠券地址
     * @param array $array
     * @return mixed
     * @throws \Exception
     */
    public function getCouponUrl(array $array = [])
    {
        $pids = $this->getPids();
        if (! isset($pids->taobao)) {
            throw new \Exception('请先设置系统pid');
        }
        $userid = $this->getUserId();

        $setting = setting($userid); // 应该是根据user或者user_id

        $taobao = $setting->taobao;
        if (! isset($taobao['sid'])) {
            throw new \Exception('请先授权淘宝联盟');
        }

        //  Implement getCouponUrl() method.
        $params = [
            'appkey'    => config('coupon.taobao.HMTK_APP_KEY'),
            'appsecret' => config('coupon.taobao.HMTK_APP_SECRET'),
            'sid'       => $taobao['sid'],  //user_id  设置表每个代理商和总管理员可以设置，代理商只可以修改 三个平台授权信息的字段
            'pid'       => $pids->taobao,
            'num_iid'   => $array['item_id'],
        ];
        $resp = Curl::to('https://www.heimataoke.com/api-zhuanlian')
            ->withData($params)
            ->get();
        $resp = json_decode($resp);
        if (isset($resp->error)) {
            throw new \Exception($resp->error);
        }
        if (isset($resp->error_response)) {
            throw new \Exception($resp->error_response->sub_msg);
        }

        return $resp;
    }

    /**
     * 获取详情.
     * @param array $params
     * @return array|mixed
     * @throws \Exception
     */
    public function getDetail(array $params = [])
    {
        $itemID = $params['itemid'] ?? request('itemid');
        if (! is_numeric($itemID)) {
            throw  new \InvalidArgumentException('商品id类型错误');
        }

        //通过转链接口获取券额
        $topclient = TopClient::connection();
        $req = new TbkItemInfoGetRequest();
        $req->setFields('title,small_images,pict_url,zk_final_price,user_type,volume');
        $req->setNumIids($itemID);
        $resp = $topclient->execute($req);
        if (! isset($resp->results->n_tbk_item)) {
            throw new \Exception("淘宝客接口调用失败：{$itemID}");
        }

        $data = $resp->results->n_tbk_item[0];
        $data->coupon = $this->getCouponUrl(['item_id' => $itemID]);

        $kouling = $this->taokouling([
            'coupon_click_url' => $data->coupon->coupon_click_url,
            'pict_url'         => $data->pict_url,
            'title'            => $data->title,
        ]);

        $data->kouling = $kouling;
        // 从本地优惠券中获取获取商品介绍 introduce 字段，如果本地没有 该字段为空
        $coupon = db('tbk_coupons')->where([
            'item_id' => $itemID,
            'type' => 1,
        ])->first();
        if ($coupon) {
            $data->introduce = $coupon->introduce;
        }
        $data->introduce = null;
        //判断优惠卷是否被收藏
        $user = getUser();
        $favourites = Favourite::query()->where([
            'user_id' => $user->id,
            'item_id' => $itemID,
            'type'    => 1,
        ])->first();
        if ($favourites) {
            $is_favourites = 1; //已收藏
        } else {
            $is_favourites = 2; //未收藏
        }
        $data->is_favourites = $is_favourites;
        //获取图文详情
        $images = $this->getDesc($itemID);

        //重组字段
        $coupon_price = isset($data->coupon->coupon_info) ? $this->getCouponPrice($data->coupon->coupon_info) : 0;
        $arr = [];
        $arr['title'] = $data->title; //标题
        $arr['item_id'] = $data->num_iid; //商品id
        $arr['user_type'] = $data->user_type; //京东  拼多多 null  1淘宝 2天猫
        $arr['volume'] = $data->volume; //销量
        $arr['price'] = floatval($data->zk_final_price); //原价
        $arr['final_price'] = floatval($data->zk_final_price - $coupon_price); //最终价
        $arr['coupon_price'] = floatval($coupon_price); //优惠价
        $arr['commossion_rate'] = $data->coupon->max_commission_rate; //佣金比例

        $arr['coupon_start_time'] = isset($data->coupon->coupon_start_time) ? Carbon::createFromTimestamp(strtotime ($data->coupon->coupon_start_time))->toDateString ()  : Carbon::now ()->toDateString (); //优惠卷开始时间
        $arr['coupon_end_time'] = isset($data->coupon->coupon_end_time) ? Carbon::createFromTimestamp (strtotime($data->coupon->coupon_end_time))->toDateString () : Carbon::now ()->addDay (3)->toDateString (); //优惠卷结束时间
        $arr['coupon_remain_count'] = isset($data->coupon->coupon_remain_count) ? $data->coupon->coupon_remain_count : null; //已使用优惠卷数量
        $arr['coupon_total_count'] = isset($data->coupon->coupon_remain_count) ? $data->coupon->coupon_total_count : null; //优惠卷总数
        $arr['pic_url'] = $data->pict_url; //商品主图
        $arr['small_images'] = $data->small_images->string; //商品图
        $arr['images'] = $images; //商品详情图
        $arr['kouling'] = $data->kouling; //淘口令
        $arr['introduce'] = $data->introduce; //描述
        $arr['is_favourites'] = $data->is_favourites; //是否收藏
        $arr['coupon_link'] = ['url' => $data->coupon->coupon_click_url]; //领劵地址
        return $arr;
    }

    /**
     * @param $id
     * @return null
     */
    protected function getDesc($id)
    {
        $rest = Curl::to('http://h5api.m.taobao.com/h5/mtop.taobao.detail.getdesc/6.0/?data={"id":"'.$id.'"}')
            ->asJsonResponse()
            ->get();
        if (isset($rest->data->pcDescContent)) {
            return $rest->data->pcDescContent;
        }
    }

    /**
     * 获取优惠卷金额.
     * @param $couponInfo
     * @return float
     */
    protected function getCouponPrice($couponInfo)
    {
        $start = strpos($couponInfo, '减');
        $len = strlen($couponInfo);
        $str = substr($couponInfo, $start, $len - $start);
        $str1 = str_replace('减', '', $str);
        $str2 = str_replace('元', '', $str1);

        return floatval($str2);
    }

    /**
     * 搜索.
     * @param array $array
     * @return array|mixed
     * @throws \Exception
     */
    public function search(array $array = [])
    {
        //可根据商品ID搜索
        $page = request('page') ?? 1;
        $tb_p = request('tb_p', 1);
        $limit = request('limit') ?? 20;
        $q = $array['q'] ?? request('q');
        $sort = $array['sort'] ?? request('sort');

        $params = [
            'apikey' => config('coupon.taobao.HDK_APIKEY'),
            'keyword' => $q,
            'back' => $limit,
            'min_id' => $page,
            'tb_p' => $tb_p,
        ];
        if ($sort != 7 || $sort != 8) {
            $params['sort'] = $sort;
        }
        $response = Curl::to('http://v2.api.haodanku.com/supersearch')
            ->withData($params)
            ->get();

        $response = json_decode($response);

        //接口信息获取失败
        if ($response->code != 1) {
            throw new \Exception('淘客接口请求失败');
        }
        //当前页面地址
        $uri = \Request::url();

        //页码信息
        $totalPage = intval(floor(count($response->data) / $limit) + 1);

        //重组字段
        $data = [];
        foreach ($response->data as $list) {
            $temp = [
                'title'           => $list->itemtitle,
                'pic_url'         => $list->itempic,
                'item_id'         => $list->itemid,
                'price'           => round($list->itemprice),
                'final_price'     => round($list->itemendprice),
                'coupon_price'    => round($list->couponmoney),
                'commission_rate' => round($list->tkrates),
                'type'            => 1,
                'volume'          => round($list->itemsale),
            ];
            array_push($data, $temp);
        }
        foreach ($data as $key => $row) {
            $coupon_price[$key] = $row['coupon_price'];
        }
        if ($sort == 7) {
            array_multisort($coupon_price, SORT_DESC, $data);
        } elseif ($sort == 8) {
            array_multisort($coupon_price, SORT_ASC, $data);
        }

        return [
            'data' => $data,
//            'links' => [
//                'next' => $uri."?type=1&q=$q&sort=$sort&page=$response->min_id&tb_p=$response->tb_p",
//            ],
            //分页信息只要这四个参数就够了
            'meta' => [
                'current_page' => (int) $page,
                'last_page' => $totalPage,
                'per_page' => $limit,
                'total'     => count($response->data),
                'tb_p'      => $response->tb_p,
            ],
        ];
    }

    /**
     * 获取订单.
     * @param array $array
     * @return mixed
     * @throws \Exception
     */
    public function getOrders(array $array = [])
    {
        //  Implement getOrders() method.
        $type = data_get($array, 'type');

        $order_query_type = 'create_time';

        if ($type == 2) {
            $order_query_type = 'settle_time';
        }
        $params = [
            'appkey' => config('coupon.taobao.HMTK_APP_KEY'),
            'appsecret' => config('coupon.taobao.HMTK_APP_SECRET'),
            'sid' => data_get($array, 'sid', 1942),
            'start_time' => now()->subMinutes(9)->toDateTimeString(),
//            'start_time' => '2018-11-01 15:45:02',
            'span' => 600,
            'signurl' => 0,
            'page_no' => data_get($array, 'page', 1),
            'page_size' => 500,
            'order_query_type' => $order_query_type,
        ];

        $resp = Curl::to('https://www.heimataoke.com/api-qdOrder')
            ->withData($params)
            ->get();

        $resp = json_decode($resp);

        if (isset($resp->error)) {
            throw new \Exception($resp->error);
        }
        if (! isset($resp->n_tbk_order)) {
            throw  new \Exception('没有数据');
        }

        return $resp->n_tbk_order;
    }

    /**
     * 热搜.
     * @return mixed
     * @throws \Exception
     */
    public function hotSearch()
    {
        $params = [
            'apikey' => config('coupon.taobao.HDK_APIKEY'),
        ];

        $resp = Curl::to('http://v2.api.haodanku.com/hot_key')
            ->withData($params)
            ->get();
        $resp = json_decode($resp);

        if ($resp->code != 1) {
            throw new \Exception($resp->msg);
        }

        return $resp->data;
    }

    /**
     * 获取全网优惠卷.
     * @param array $array
     * @return array|mixed
     * @throws \Exception
     */
    public function spider(array $array = [])
    {
        $type = $array['type'] ?? 3;

        $min_id = $array['min_id'] ?? 1;

        if (! in_array($type, [1, 2, 3, 4, 5])) {
            throw new \InvalidArgumentException('type不合法');
        }
        $params = [
            'apikey' => config('coupon.taobao.HDK_APIKEY'),
            'nav' => $type,
            'cid' => 0,
            'back' => 100,
            'min_id' => $min_id,
        ];
        $resp = Curl::to('http://v2.api.haodanku.com/itemlist')
            ->withData($params)
            ->get();
        $resp = json_decode($resp);
        if ($resp->code != 1) {
            throw new \Exception($resp->msg);
        }

        return [
            'data' => $resp->data,
            'min_id' => $resp->min_id,
        ];
    }

    /**
     * 好货专场.
     * @param array $params
     * @return mixed
     * @throws \Exception
     */
    public function haohuo(array $params)
    {
        $min_id = $params['min_id'] ?? 1;
        $params = [
            'apikey' => config('coupon.taobao.HDK_APIKEY'),
            'min_id' => $min_id,
        ];
        $resp = Curl::to('http://v2.api.haodanku.com/subject_hot')
            ->withData($params)
            ->get();
        $resp = json_decode($resp);
        if ($resp->code != 1) {
            throw new \Exception($resp->msg);
        }

        return $resp;
    }

    /**
     * 精选单品
     * @param array $params
     * @return array
     * @throws \Exception
     */
    public function danpin(array $params)
    {
        $min_id = $params['min_id'] ?? 1;
        $params = [
            'apikey' => config('coupon.taobao.HDK_APIKEY'),
            'min_id' => $min_id,
        ];
        $resp = Curl::to('http://v2.api.haodanku.com/selected_item')
            ->withData($params)
            ->get();
        $resp = json_decode($resp);
        if ($resp->code != 1) {
            throw new \Exception($resp->msg);
        }

        return [
            'data' => $resp->data,
            'min_id' => $resp->min_id,
        ];
    }

    /**
     * 精选专题.
     * @return mixed
     * @throws \Exception
     */
    public function zhuanti()
    {
        $params = [
            'apikey' => config('coupon.taobao.HDK_APIKEY'),
        ];
        $resp = Curl::to('http://v2.api.haodanku.com/get_subject')
            ->withData($params)
            ->get();
        $res = json_decode($resp);
        if ($res->code != 1) {
            throw new \Exception($res->msg);
        }

        return $res;
    }

    /**
     * 专题的商品
     * @param array $params
     * @return mixed
     * @throws \Exception
     */
    public function zhuantiItem(array $params)
    {
        $params = [
            'apikey' => config('coupon.taobao.HDK_APIKEY'),
            'id'     => $params['id'],
        ];
        $resp = Curl::to('http://v2.api.haodanku.com/get_subject_item')
            ->withData($params)
            ->get();
        $res = json_decode($resp);
        if ($res->code != 1) {
            throw new \Exception($res->msg);
        }

        return $res;
    }

    /**
     * 快抢商品
     * @param array $params
     * @return array
     * @throws \Exception
     */
    public function kuaiQiang(array $params)
    {
        $type = $params['hour_type'] ?? 7;
        $min_id = $params['min_id'] ?? 1;
        $params = [
            'apikey' => config('coupon.taobao.HDK_APIKEY'),
            'hour_type' => $type,
            'min_id' => $min_id,
        ];
        $rest = Curl::to('http://v2.api.haodanku.com/fastbuy')
            ->withData($params)
            ->get();
        $rest = json_decode($rest);
//        if ($rest->code != 1) {
//            throw  new \Exception($rest->msg);
//        }

        return [
            'data' => $rest->data,
            'min_id' => $rest->min_id,
        ];
    }

    /**
     * 定时拉取.
     * @param array $params
     * @return mixed
     * @throws \Exception
     */
    public function timingItems(array $params)
    {
        //获取最近整点时间
        $timestamp = date('H'); //当前时间的整点
        $min_id = $params['min_id'] ?? 1;
        $params = [
            'apikey' => config('coupon.taobao.HDK_APIKEY'),
            'start' => $timestamp,
            'end' => $timestamp + 1,
            'min_id' => $min_id,
            'back' => 100, //请在1,2,10,20,50,100,120,200,500,1000中选择一个数值返回
        ];
        $results = Curl::to('http://v2.api.haodanku.com/timing_items')
            ->withData($params)
            ->get();
        $results = json_decode($results);
        if ($results->code != 1) {
            throw  new \Exception($results->msg);
        }

        return [
            'data' => $results->data,
            'min_id' => $results->min_id,
        ];
    }

    /**
     * 更新优惠券.
     * @param array $params
     * @return array
     * @throws \Exception
     */
    public function updateCoupon(array $params)
    {
        $sort = $params['sort'] ?? 1;
        $back = $params['back'] ?? 500;
        $min_id = $params['min_id'] ?? 1;
        if (! in_array($back, [1, 2, 10, 20, 50, 100, 120, 200, 500, 1000])) {
            throw new \Exception('每页条数不合法');
        }
        $params = [
            'apikey' => config('coupon.taobao.HDK_APIKEY'),
            'sort' => $sort,
            'back' => $back,
            'min_id' => $min_id,
        ];
        $rest = Curl::to('http://v2.api.haodanku.com/update_item')
            ->withData($params)
            ->get();
        $rest = json_decode($rest);
        if ($rest->code != 1) {
            throw new \Exception($rest->msg);
        }

        return [
            'data' => $rest->data,
            'min_id' => $rest->min_id,
        ];
    }

    /**
     * 失效商品
     * @param array $params
     * @return mixed
     * @throws \Exception
     */
    public function deleteCoupon(array $params)
    {
        $start = $params['start'];
        $end = $params['end'];
        $params = [
            'apikey' => config('coupon.taobao.HDK_APIKEY'),
            'start' => $start,
            'end' => $end,
        ];
        $resp = Curl::to('http://v2.api.haodanku.com/get_down_items')
            ->withData($params)
            ->get();
        $resp = json_decode($resp);

        if ($resp->code != 1) {
            throw new \Exception($resp->msg);
        }

        return $resp->data;
    }

    /**
     * 转换淘口令.
     * @param array $params
     * @return mixed
     * @throws \Exception
     */
    public function taokouling(array $params)
    {
        // 根据pid item 图片地址生成淘口令，如果我不是会员，则用我上级的pid，如果上级也不是超级会员，就用组长的pid
        $topclient = TopClient::connection();

        //获取淘口令
        $req = new TbkTpwdCreateRequest;

        $req->setUrl($params['coupon_click_url']);
        $req->setLogo($params['pict_url']);
        $req->setText($params['title']);
        $resp = $topclient->execute($req);
        if (! isset($resp->data->model)) {
            throw new \Exception('淘口令生成失败');
        }
        $taokouling = $resp->data->model;

        return $taokouling;
    }
}
