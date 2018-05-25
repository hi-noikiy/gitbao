<?php
/**
 * 普通策略
 *
 * User: haoshuaiwei
 * Date: 2018/5/19
 * Time: 下午3:03
 */

include_once 'interface.php';
include_once 'abstract.php';

final class GeneralClass extends BaseAbstractClass implements BaseInterface
{
    
    public function sellOrder()
    {
        $orderRes = $this->_order;
        
        $db = $this->_db;
        $userRes = $this->_user;
        $robotRes = $this->_robot;
        $userKey = $this->_user_key;
        $userSecret = $this->_user_secret;
        $configRule = $this->_config;
        $trade_num = $this->_trade_num;
        
        $httpResponse = $this->findThirdOrderInfo();
        $json = json_decode($httpResponse, true);
        if (isset($json['result']) && $json['result'] == true) {
            // 同时根据配置的 【止盈量*买入价格+买入价格】，挂卖出单
            if (isset($json['orders'][0])) {
                $price = $orderRes['avg_price'] + $configRule['profit_number'] * $orderRes['avg_price'];
                
                $buyData = [
                    "api_key" => $userKey,
                    "symbol" => $configRule['symbol'],
                    "amount" => $orderRes['deal_amount'], //价格
                    "price" => $price,  //数量
                    "type" => OkexBuyTypeClass::$BuyAndSell[$robotRes['business_type']],
                ];
                $newBuyData = OkexBuyTypeClass::switchType($buyData, $userSecret);
                $httpResponse = CurlUtilsClass::getHttpPostRes($newBuyData, ConfigClass::$OKEX_URL['trade']);
                
                $json = json_decode($httpResponse, true);
                if (isset($json['result']) && $json['result'] == true) {
                    $orderAutoId = $db->insert("r_order",
                        [
                            "third_id" => $json['order_id'],
                            "robot_id" => $robotRes['id'],
                            "user_id" => $userRes['id'],
                            "order_type" => 2,
                            'create_time' => date("Y-m-d H:i:s"),
                            'symbol' => $configRule['symbol'],
                            'trade_type' => OkexBuyTypeClass::$BuyAndSell[$robotRes['business_type']],
                            'platform_id' => 1
                        ]
                    );
                    //同时向订单记录表插入一条记录
                    $db->insert("r_order_state",
                        [
                            "order_id" => $orderAutoId,
                            "third_id" => $json['order_id'],
                            "state" => 1,
                            'create_time' => date("Y-m-d H:i:s")
                        ]
                    );
                    //更新机器人的交易次数
                    ++$trade_num;
                    $db->update('r_users_robot', ['trade_num' => $trade_num, 'real_trade_num' => $trade_num],
                        'id=' . $robotRes['id'] . ' and user_id=' . $robotRes['user_id']);
                } else {
                    $msg = "error：机器人：" . $robotRes['robot_name'] .
                        " 请求参数：" . json_encode($newBuyData) . " 卖出失败：" . $httpResponse;
                    LogUtilsClass::WriteLogTable($db, $robotRes['id'], $robotRes['user_id'], $msg, $httpResponse);
                }
            }
        } else {
            $msg = "error：机器人：" . $robotRes['robot_name'] .
                " 卖出查询失败：" . $httpResponse;
            LogUtilsClass::WriteLogTable($db, $robotRes['id'], $robotRes['user_id'], $msg, $httpResponse);
        }
    }
    
    
    //普通策略买进
    public function buyOrder()
    {
        $orderRes = $this->_order;
        $checkTradeNumBool = $this->checkTradeNum();
        if (!$checkTradeNumBool) {
            return false;
        }
        $db = $this->_db;
        $userRes = $this->_user;
        $robotRes = $this->_robot;
        $userKey = $this->_user_key;
        $userSecret = $this->_user_secret;
        $configRule = $this->_config;
        $trade_num = $this->_trade_num;
        
        if ($robotRes['business_type'] == "buy") {
            $buyData = [
                "api_key" => $userKey,
                "symbol" => $configRule['symbol'],
                "amount" => $configRule['amount'],
                "price" => $configRule['price'],
                "type" => $robotRes['business_type'],
            ];
        } else {
            $buyData = [
                "api_key" => $userKey,
                "symbol" => $configRule['symbol'],
                "amount" => $configRule['price'],
                "price" => $configRule['amount'], //price 是数量，对方接口写反了
                "type" => $robotRes['business_type'],
            ];
        }
        $newBuyData = OkexBuyTypeClass::switchType($buyData, $userSecret);
        $httpResponse = CurlUtilsClass::getHttpPostRes($newBuyData, ConfigClass::$OKEX_URL['trade']);
        $json = json_decode($httpResponse, true);
        //针对订单下单成功做存储操作 {"result":true,"order_id":123456}
        if (isset($json['result']) && $json['result'] == true) {
            $orderAutoId = $db->insert("r_order",
                [
                    "third_id" => $json['order_id'],
                    "order_type" => 1,
                    "robot_id" => $robotRes['id'],
                    "user_id" => $userRes['id'],
                    "order_type" => 1,
                    'create_time' => date("Y-m-d H:i:s"),
                    'symbol' => $configRule['symbol'],
                    'trade_type' => $robotRes['business_type'],
                    'platform_id' => 1
                ]
            );
            //同时向订单记录表插入一条记录
            $db->insert("r_order_state",
                [
                    "order_id" => $orderAutoId,
                    "third_id" => $json['order_id'],
                    "state" => 1,
                    'create_time' => date("Y-m-d H:i:s")
                ]
            );
            
            //更新机器人的交易次数
            ++$trade_num;
            $db->update('r_users_robot', ['trade_num' => $trade_num],
                'id=' . $robotRes['id'] . ' and user_id=' . $robotRes['user_id']);
            
            $requestParams = [
                "api_key" => $userKey,
                "symbol" => $configRule['symbol'],
                "order_id" => $orderRes['third_id'],
            ];
            $requestParamsNew = OkexSingClass::sign($requestParams, $userSecret);
            $httpResponse = CurlUtilsClass::getHttpPostRes($requestParamsNew, ConfigClass::$OKEX_URL['order_info']);
            $json = json_decode($httpResponse, true);
            if (isset($json['result']) && $json['result'] == true) {
                // $okexOrderDetail['status'] -1:已撤销  0:未成交  1:部分成交  2:完全成交 3:撤单处理中
                $okexOrderDetail = $json['orders'][0];
                //更新订单一些相关信息
                $db->update('r_order', [
                    'state' => $okexOrderDetail['state'],
                    'amount' => $okexOrderDetail['amount'],
                    'price' => $okexOrderDetail['price'],
                    'deal_amount' => $okexOrderDetail['deal_amount'],
                    'avg_price' => $okexOrderDetail['avg_price'],
                    'update_time' => date('Y-m-d H:i:s'),
                ], 'id=' . $orderAutoId);
            } else {
                $msg = "error：机器人：" . $robotRes['id'] . $robotRes['robot_name'] .
                    " 请求参数：" . json_encode($requestParamsNew) . " 首单买入查询失败：" . $httpResponse;
                LogUtilsClass::WriteLogTable($db, $robotRes['id'], $robotRes['user_id'], $msg, $httpResponse);
            }
        } else {
            $msg = "error：机器人：" . $robotRes['id'] . $robotRes['robot_name'] .
                " 请求参数：" . json_encode($newBuyData) . " 首单买入失败：" . $httpResponse;
            LogUtilsClass::WriteLogTable($db, $robotRes['id'], $robotRes['user_id'], $msg, $httpResponse);
        }
    }
    
    public function run()
    {
        $this->_trade_num = $this->_robot['trade_num'];
        
        //以下是普通策略
        $d = date("Y-m-d H:i:s", strtotime("-2 day"));
        //查询机器人的最后一订单，并根据订单的类型及状态，再决定是否下单
        $orderRes = $this->_db->query("select * from r_order where robot_id={$this->_robot['id']} and create_time>='{$d}'" .
            " order by id desc limit 1", "Row");
        
        $this->_order = $orderRes;
        
        if (!empty($orderRes)) {
            $orderType = 0;
            if ($orderRes['order_type'] == "1" && $orderRes['state'] == "2") {
                //类型为买进，状态为完全成交，则进行挂卖单
                $orderType = 2;
            } else if ($orderRes['order_type'] == "1" && $orderRes['state'] == "-1") {
                //类型为买进，状态为撤消，则进行挂卖单
                $orderType = 2;
            } else if ($orderRes['order_type'] == "2" && $orderRes['state'] == "2") {
                //类型为卖出，状态为完全成交，则进行下单
                $orderType = 1;
            } else if ($orderRes['order_type'] == "2" && $orderRes['state'] == "-1") {
                //类型为卖出，状态为撤消，则进行下单
                $orderType = 1;
            }
            if ($orderType == 1) {
                //买进
                $this->buyOrder();
            } else if ($orderType == 2) {
                //卖出
                $this->sellOrder();
            } else {
                //监听
                $this->listenOrder();
            }
        } else {
            //为空直接下单
            $this->buyOrder();
        }
    }
    
    public function listenOrder()
    {
        // 如果都不在那几个状态，就时时的监听，并更新进度
        $httpResponse = $this->findThirdOrderInfo();
        $json = json_decode($httpResponse, true);
        if (isset($json['result']) && $json['result'] == true) {
            // $okexOrderDetail['status'] -1:已撤销  0:未成交  1:部分成交  2:完全成交 3:撤单处理中
            $okexOrderDetail = $json['orders'][0];
            //更新订单一些相关信息
            $this->_db->update('r_order', [
                'state' => $okexOrderDetail['status'],
                'amount' => $okexOrderDetail['amount'],
                'price' => $okexOrderDetail['price'],
                'deal_amount' => $okexOrderDetail['deal_amount'],
                'avg_price' => $okexOrderDetail['avg_price'],
                'update_time' => date('Y-m-d H:i:s'),
            ], 'id=' . $this->_order['id']);
            $msg = "success：机器人：" . $this->_robot['robot_name'];
            LogUtilsClass::WriteLogTable($this->_db, $this->_robot['id'], $this->_robot['user_id'], $msg, $httpResponse);
        } else {
            $msg = "error：机器人：" . $this->_robot['robot_name'] . " 监听订单查询失败：" . $httpResponse;
            LogUtilsClass::WriteLogTable($this->_db, $this->_robot['id'], $this->_robot['user_id'], $msg, $httpResponse);
        }
    }
}