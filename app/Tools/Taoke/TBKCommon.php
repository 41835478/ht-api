<?php

namespace App\Tools\Taoke;

trait TBKCommon
{
    /**
     *  获取当前用户 的 pids.
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Query\Builder|mixed|null|object
     */
    public function getPids()
    {
        $user = getUser ();
        $setting = setting (1); //应该是根据user或者user_id

        // 获取系统默认pid

        $user_pid = db ('tbk_pids')->where ('user_id', $user->id)->first ();

        //自己
        if ($user_pid) {
            return $user_pid;
        }
        //邀请人
        if($user->inviter_id != null){
            $inviter_pid = db ('tbk_pids')->where ('user_id', $user->inviter_id)->first ();

            if ($inviter_pid) {
                return $inviter_pid;
            }
        }

        if($user->group_id != null){
            $group = db ('groups')->find ($user->group_id);
            $group_pid = db ('tbk_pids')->where ('user_id', $group->user_id)->first ();
            //小组
            if ($group_pid) {
                return $group_pid;
            }
            // 代理设置
            $agent_setting = db('tbk_settings')->where([
                'user_id' => $group->user_id
            ])->first();
            if ($agent_setting) {
                return $agent_setting->pid;
            }
        }

        return $this->arrayToObject($setting->pid);
    }

    /**
     * @param $e
     * @return object|void
     */
    private function arrayToObject($e)
    {

        if (gettype($e) != 'array') return;
        foreach ($e as $k => $v) {
            if (gettype($v) == 'array' || getType($v) == 'object')
                $e[$k] = (object)$this->arrayToObject($v);
        }
        return (object)$e;
    }

    /**
     * @param $price
     * @return float|int
     */
    public function getFinalCommission($price)
    {
        $id = getUserId();
        $commission = new Commission();
        return $commission->getCommissionByUser($id,$price,'commission_rate1');
    }

    /**
     * 设置信息
     * @return mixed
     */
    public function getSettings()
    {
        //读取代理设置
        $user = getUser ();
        $setting = setting (1); //应该是根据user或者user_id

        if($user->group_id){
            $group = db ('groups')->find ($user->group_id);
            if ($group) {
                $agent_setting = db('tbk_settings')->where([
                    'user_id' => $group->user_id
                ])->first();
                if ($agent_setting) {
                    return $agent_setting;
                }
            }
        }
        return $setting;
    }
}
