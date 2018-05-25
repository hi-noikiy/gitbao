<?php

namespace app\index\controller;

use app\index\model\Users;
use app\index\model\UsersRobot;
use app\index\model\UsersRobotProcess;
use app\index\model\UsersStock;
use app\index\model\VipConfig;

class User extends Common
{
    public function index()
    {
        $stockObject = new UsersStock();
        $res = $stockObject->where(['user_id' => $this->LoginUser['id'], 'sync_date' => date('Y-m-d'), 'stock_type' => 1])
            ->select();
        if (!isset($res[0])) {
            $stockRes = $this->_getStock();
            $this->_syncLocalTable($stockRes);
            $res = $stockObject->where(['user_id' => $this->LoginUser['id'], 'sync_date' => date('Y-m-d'), 'stock_type' => 1])
                ->select();
        }
        
        $vipObject = new VipConfig();
        $vipConfig = $vipObject->where('id', "=", $this->LoginUser['vip_level'])->find();
        $this->assign('vip_config', $vipConfig);
        $this->assign('free', $res);
        $this->assign('okex', $this->_getCurrentOkex());
        return $this->fetch("index");
    }
    
    private function _syncLocalTable(array $res)
    {
        $loginUser = session("LoginUser");
        $d = date("Y-m-d");
        $stockObject = new UsersStock();
        $insertDb = [];
        foreach ($res['free'] as $k => $v) {
            $insertDb[] = [
                'user_id' => $loginUser['id'],
                'stock_type' => 1,
                'coin_name' => $k,
                'coin_number' => $v,
                'sync_date' => $d,
            ];
        }
        foreach ($res['freezed'] as $k => $v) {
            $insertDb[] = [
                'user_id' => $loginUser['id'],
                'stock_type' => 2,
                'coin_name' => $k,
                'coin_number' => $v,
                'sync_date' => $d,
            ];
        }
        if (!empty($insertDb)) {
            $stockObject->insertAll($insertDb);
        }
    }
    
    public function set_page()
    {
        return $this->fetch("set_page");
    }
    
    public function edit_pwd()
    {
        return $this->fetch("edit_password");
    }
    
    
    private function _getStock()
    {
        $url = 'https://www.okex.com/api/v1/userinfo.do?';
        $user = session('LoginUser');
        $res = $this->getHttpPostRes(null, $url . $this->sign(['api_key' => $this->decrypt($user['api_key'])]
                , $this->decrypt($user['api_secret'])));
        $res = json_decode($res, true);
        if ($res['result']) {
            $fundsFree = $res['info']['funds']['free'];
            $fundsFreezed = $res['info']['funds']['freezed'];
            return ['free' => $fundsFree, 'freezed' => $fundsFreezed];
        } else {
            return ['free' => [], 'freezed' => []];
        }
    }
    
    private function _getCurrentOkex()
    {
        $arr = ['ltc_usdt', 'btc_usdt', 'eos_usdt'];
        foreach ($arr as $v) {
            $url = "https://www.okex.com/api/v1/ticker.do?symbol=" . $v;
            $tmpResult = $this->getHttpRes($url);
            $tmpResult = json_decode($tmpResult, true);
            $tmpResult['date'] = date('Y-m-d H:i:s', $tmpResult['date']);
            $result[$v] = $tmpResult;
        }
        return $result;
    }
    
    public function edit_pwd_action()
    {
        //预留判断验证码
        $old_passwd = md5(input("post.rpass"));
        if ($this->LoginUser['pwd'] != $old_passwd) {
            $this->__json(101, "原始密码错误");
        }
        $temp = input("post.pass");
        if (mb_strlen($temp) < 6 || mb_strlen($temp) > 16) {
            $this->__json(101, "新密码长度错误");
        }
        $new_passwd = md5($temp);
        $newr_passwd = md5(input("post.repass"));
        if ($new_passwd != $newr_passwd) {
            $this->__json(101, "新密码与旧密码不匹配");
        }
        $u = new Users();
        $rs = $u->save(['pwd' => $new_passwd], 'id=' . $this->LoginUser['id']);
        if ($rs) {
            \session(null);
            $this->__json(0, "修改成功");
        } else {
            $this->__json(101, "修改失败");
        }
    }
    
    public function edit_key_action()
    {
        //预留判断验证码
        $key = (input("post.api_key"));
        if (empty($key)) {
            $this->__json(101, "API_KEY不能为空");
        }
        $secret = (input("post.api_secret"));
        if (empty($key)) {
            $this->__json(101, "API_SECRET不能为空");
        }
        $u = new Users();
        $rs = $u->save(['api_key' => $this->encrypt($key), 'api_secret' => $this->encrypt($secret)],
            'id=' . $this->LoginUser['id']);
        if ($rs) {
            //停止所有机器人
            
            //杀死机器人进程数据 先查出进行编号
            $rpObject = new UsersRobotProcess();
            //获取用户所有机器人
            $obj = new UsersRobot();
            $rs = $obj->where(['state' => 1, 'user_id' => $this->LoginUser['id']])->select();
            foreach ($rs as $k => $v) {
                //更新机器人
                $res = $obj->save(['state' => 2], ['id' => $v['id'], 'user_id' => $this->LoginUser['id'], 'state' => 1]);
                $res = $rpObject->where(['robot_id' => $v['id'], 'user_id' => $this->LoginUser['id'], 'config_id' => $v['config_id'], 'robot_state' => 1])->find();
                $killPaht = config("kill_swoole_process");
                exec("php $killPaht -p " . $res['robot_process_id'], $info);
            }
            \session(null);
            $this->__json(0, "修改成功");
        } else {
            $this->__json(101, "修改失败");
        }
    }
}
