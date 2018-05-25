<?php

namespace app\index\controller;

use app\index\model\Order;
use app\index\model\OrderState;
use app\index\model\Policy;
use app\index\model\RobotConfig;
use app\index\model\UsersPolicy;
use app\index\model\UsersRobot;
use app\index\model\UsersRobotLog;
use app\index\model\UsersRobotProcess;
use app\index\model\UsersStock;
use app\index\model\VipConfig;

class Trade extends Common
{
    public function index()
    {
        $stockObject = new UsersStock();
        $res = $stockObject->where(['user_id' => $this->LoginUser['id'], 'sync_date' => date('Y-m-d'), 'stock_type' => 1])
            ->order('coin_name')->select();
        $this->assign('free', $res);
        $this->assign('week_kline', $this->WEEK_KLINE);
        $this->assign('coin_arr', $this->COIN_ARR);
        //读取公开高级策略
        $policyObject = new Policy();
        $this->assign('public_policy', $policyObject->field("policy_zh_name,id")->where(['state' => 1])->select());
        //读取用户购买的策略
        $userPolicyObject = new UsersPolicy();
        $this->assign('user_policy', $userPolicyObject->field('r_policy.id,policy_zh_name')->
        join(' r_policy', 'r_users_policy.policy_id=r_policy.id')->where(['r_users_policy.state' => 1])->select());
        
        
        $policyObject = new Policy();
        $policy_list = $policyObject->field("id,policy_zh_name,policy_description,policy_use_type,
        policy_week,policy_type,policy_service_description")->select();
        $this->assign('policy_list', $policy_list);
        return $this->fetch("index");
    }
    
    public function robot()
    {
        $lim = input('get.limit');
        $page = input('get.page');
        $page = ($page - 1) * $lim;
        $robotObject = new UsersRobot();
        $count = $robotObject->where(['user_id' => $this->LoginUser['id']])->where('state', '<>', 3)->count();
        $res = $robotObject->where(['user_id' => $this->LoginUser['id']])->where('state', '<>', 3)
            ->order('id desc')->limit($page, $lim)->select();
        if (empty($res)) {
            $this->__json(101, "未找到机器人数据");
        }
        $this->__json(0, "success", $res, $count);
    }
    
    public function add_robot()
    {
        $this->_checkToken();
        
        $cid = input('post.config_id');
        $rid = input('post.robot_id');
        //根据用户VIP等级获取VIP配置
        $vipObject = new VipConfig();
        $vipConfig = $vipObject->where('id', "=", $this->LoginUser['vip_level'])->find();
        if ($vipConfig['robot_num'] <= 0) {
            $this->__json(101, "非VIP不能添加机器人");
        }
        $ur = new UsersRobot();
        
        $configData = [
            'name' => input('post.robot_name'),
            'sleep_time' => input('post.sleep_time'),
            'config_type' => input('post.config_type'),
            'config_type_week' => input('post.config_type_week'),
            'symbol' => input('post.symbol'),
            'amount' => input('post.amount'),
            'price' => input('post.price'),
            'price_sell' => input('post.price_sell'),
            'profit_number' => input('post.profit_number'),
            'create_time' => date('Y-m-d H:i:s'),
            'user_id' => $this->LoginUser['id'],
        ];
        
        if ($configData['amount'] > $vipConfig['transaction_num']) {
            $this->__json(101, "超过使用USDT上线，请重新设置");
        }
        
        //添加机器人策略，并且产生策略ID，绑定用户
        $rc = new RobotConfig();
        if ($cid == "") {
            
            //统计当前用户有多少个机器人
            $countRes = $ur->where('state', '<>', '3')->where('user_id', $this->LoginUser['id'])->count();
            if ($countRes >= $vipConfig['robot_num']) {
                $this->__json(101, "机器人已经到达上线");
            }
            
            $rc_id = $rc->save($configData);
            if (!$rc_id) {
                $this->__json(101, "策略创建失败");
            }
            $rc_id = $rc->getLastInsID();
        } else {
            unset($configData['create_time']);
            $configData['update_time'] = date('Y-m-d H:i:s');
            $tmpRes = $rc->save($configData, ['id' => $cid, 'user_id' => $this->LoginUser['id']]);
            $rc_id = $cid;
            if (!$tmpRes) {
                $this->__json(101, "策略更新失败");
            }
        }
        //创建机器人，并绑定策略ID,绑定用户
        $robotData = [
            'robot_name' => input('post.robot_name'),
            'user_id' => $this->LoginUser['id'],
            'config_id' => $rc_id,
            'trade_use_num' => input('post.trade_use_num'),
            'state' => 2,
            'create_time' => date('Y-m-d H:i:s'),
            'business_type' => input('post.business_type'),
        ];
        
        if ($rid == "") {
            $ur_id = $ur->save($robotData);
            if (!$ur_id) {
                $this->__json(101, "机器人创建失败");
            }
        } else {
            unset($robotData['create_time']);
            $robotData['update_time'] = date('Y-m-d H:i:s');
            $tmpRes = $ur->save($robotData, ['id' => $rid, 'user_id' => $this->LoginUser['id']]);
            if (!$tmpRes) {
                $this->__json(101, "机器人更新失败");
            }
        }
        $this->__json(0, "操作成功");
    }
    
    public function order()
    {
        $rid = input('get.id');
        $lim = input('get.limit');
        $page = input('get.page');
        $page = ($page - 1) * $lim;
        $orderObject = new Order();
        $count = $orderObject->where(['user_id' => $this->LoginUser['id'], 'robot_id' => $rid])->count();
        $res = $orderObject->where(['user_id' => $this->LoginUser['id'], 'robot_id' => $rid])
            ->order('id desc')->limit($page, $lim)->select();
        if (empty($res)) {
            $this->__json(101, "未找到订单数据");
        }
        $this->__json(0, "success", $res, $count);
    }
    
    public function robot_log()
    {
        $rid = input('get.id');
        $lim = input('get.limit');
        $page = input('get.page');
        $page = ($page - 1) * $lim;
        $logObject = new UsersRobotLog();
        $count = $logObject->where(['user_id' => $this->LoginUser['id'], 'robot_id' => $rid])->count();
        $res = $logObject->where(['user_id' => $this->LoginUser['id'], 'robot_id' => $rid])
            ->order('id desc')->limit($page, $lim)->select();
        if (empty($res)) {
            $this->__json(101, "未找日志数据");
        }
        $this->__json(0, "success", $res, $count);
    }
    
    public function cancel_order()
    {
        $this->_checkToken();
        $oid = input('post.id');
        $orderObject = new Order();
        $orderStateObject = new OrderState();
        $orderRes = $orderObject->where(['id' => $oid, 'user_id' => $this->LoginUser['id']])->find();
        if (empty($orderRes)) {
            $this->__json(101, "未找到订单数据");
        }
        //查询订单状态
        //如果是在【0未成交、1部分成交】则取消订单
        $userKey = $this->decrypt($this->LoginUser['api_key']);
        $userSecret = $this->decrypt($this->LoginUser['api_secret']);
        $requestParams = [
            "api_key" => $userKey,
            "symbol" => $orderRes['symbol'],
            "order_id" => $orderRes['third_id'],
        ];
        $requestParamsNew = $this->sign($requestParams, $userSecret);
        $httpResponse = $this->getHttpPostRes($requestParamsNew, "https://www.okex.com/api/v1/order_info.do");
        $json = json_decode($httpResponse, true);
        if (isset($json['result']) && $json['result'] == true) {
            // $okexOrderDetail['status'] -1:已撤销  0:未成交  1:部分成交  2:完全成交 3:撤单处理中
            if (!isset($json['orders'][0])) {
                $this->__json(101, "查询第三方订单数据失败，订单为空");
            }
            $okexOrderDetail = $json['orders'][0];
            //更新订单一些相关信息
            $orderObject->update([
                'state' => $okexOrderDetail['status'],
                'update_time' => date('Y-m-d H:i:s'),
            ], 'id=' . $orderRes['id']);
            if ($okexOrderDetail['status'] == 0 || $okexOrderDetail['status'] == 1) {
                //取消订单
                $cancelOrderUrl = "https://www.okex.com/api/v1/cancel_order.do";
                $requestParamsNew = $this->sign($requestParams, $userSecret);
                $httpResponse = $this->getHttpPostRes($requestParamsNew, $cancelOrderUrl);
                $json = json_decode($httpResponse, true);
                if (isset($json['result']) && $json['result'] == true) {
                    $orderObject->update([
                        'state' => -1,
                        'update_time' => date('Y-m-d H:i:s'),
                    ], 'id=' . $orderRes['id']);
                    $orderStateObject->insert([
                        'order_id' => $orderRes['id'],
                        'third_id' => $orderRes['third_id'],
                        'create_time' => date('Y-m-d H:i:s'),
                        'state' => -1,
                    ]);
                }
            }
        } else {
            $this->__json(101, "查询第三方订单数据失败");
        }
    }
    
    public function config()
    {
        $cid = input('get.cid');
        $robotConfig = new RobotConfig();
        $res = $robotConfig->where('user_id', $this->LoginUser['id'])->where('id', $cid)->find();
        $this->__json(0, "success", $res);
    }
    
    public function start_robot()
    {
        $rid = input('post.id');
        //将机器人设置为开始状态
        $rObject = new UsersRobot();
        //查询机器人
        $robotRes = $rObject->where(['id' => $rid, 'user_id' => $this->LoginUser['id'], 'state' => 2])->find();
        if (empty($robotRes)) {
            $this->__json(101, "查询机器人失败，或者状态不正确");
        }
        //更新机器人
        $res = $rObject->save(['state' => 1], ['id' => $rid, 'user_id' => $this->LoginUser['id'], 'state' => 2]);
        if ($res) {
            //创建机器人进程数据
            $rpObject = new UsersRobotProcess();
            $rpObject->save([
                'robot_id' => $robotRes['id'],
                'config_id' => $robotRes['config_id'],
                'user_id' => $this->LoginUser['id'],
                'robot_state' => 0,
                'create_time' => date("Y-m-d H:i:s"),
            ]);
            $this->__json(0, "启动成功");
        } else {
            $this->__json(101, "启动失败，查询机器人失败，或者状态不正确");
        }
    }
    
    public function stop_robot()
    {
        $rid = input('post.id');
        //将机器人设置为关闭状态
        $rObject = new UsersRobot();
        //查询机器人
        $robotRes = $rObject->where(['id' => $rid, 'user_id' => $this->LoginUser['id'], 'state' => 1])->find();
        if (empty($robotRes)) {
            $this->__json(101, "查询机器人失败，或者状态不正确");
        }
        //更新机器人
        $res = $rObject->save(['state' => 2], ['id' => $rid, 'user_id' => $this->LoginUser['id'], 'state' => 1]);
        if ($res) {
            //杀死机器人进程数据 先查出进行编号
            $rpObject = new UsersRobotProcess();
            $res = $rpObject->where(['robot_id' => $rid, 'user_id' => $this->LoginUser['id'], 'config_id' => $robotRes['config_id'], 'robot_state' => 1])->find();
//            $rpObject->save(['robot_state'=>2],
//                ['robot_id' => $rid, 'user_id' => $user['id'], 'config_id' => $robotRes['config_id'], 'robot_state' => 1]);
            
            $killPaht = config("kill_swoole_process");
            exec("php $killPaht -p " . $res['robot_process_id'], $info);
            $this->__json(0, implode("", $info));
        } else {
            $this->__json(101, "停止失败，查询机器人失败，或者状态不正确");
        }
    }
    
    public function del_robot()
    {
        $rid = input('post.id');
        //将机器人设置为关闭状态
        $rObject = new UsersRobot();
        //查询机器人
        $robotRes = $rObject->where(['id' => $rid, 'user_id' => $this->LoginUser['id']])->find();
        if (empty($robotRes)) {
            $this->__json(101, "查询机器人失败，或者状态不正确");
        }
        //更新机器人
        $res = $rObject->save(['state' => 3], ['id' => $rid, 'user_id' => $this->LoginUser['id']]);
        if ($res) {
            //杀死机器人进程数据 先查出进行编号
            $rpObject = new UsersRobotProcess();
            $res = $rpObject->where(['robot_id' => $rid, 'user_id' => $this->LoginUser['id'], 'config_id' => $robotRes['config_id'], 'robot_state' => 1])->find();
//            $rpObject->save(['robot_state'=>2],
//                ['robot_id' => $rid, 'user_id' => $user['id'], 'config_id' => $robotRes['config_id'], 'robot_state' => 1]);
            $killPaht = config("kill_swoole_process");
            exec("php $killPaht -p " . $res['robot_process_id'], $info);
            $this->__json(0, implode("", $info));
        } else {
            $this->__json(101, "停止失败，查询机器人失败，或者状态不正确");
        }
    }
}
