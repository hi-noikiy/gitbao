<?php
/**
 * 5线顺模型 策略 4线 公开策略
 *
 * 5分钟，15分钟，30分钟
 *
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/5/12
 * Time: 下午3:52
 */
include_once 'interface.php';
include_once 'abstract.php';

class FiveLineShunFourPublicClass extends BaseAbstractClass implements BaseInterface
{
    
    public $_line_number = [7, 21, 40, 60]; //计算平均值使用的多少条线的数组
    public $_db = null;
    public $_user = null;
    public $_user_key = null, $_user_secret = null;
    public $_config = null;
    public $_robot = null;
    public $_trade_num = null;
    private $_usdt = 0;
    private $_kline = [];
    
    /**
     * 获取K线数据源
     * @param int $min 要获取的K线周期
     * @param string $symbol 要获取某一个币种的K线值，例：eos_usdt
     * @return array
     */
    private function _getIsTrade(string $min, string $symbol): array
    {
        $res = CurlUtilsClass::getHttpRes(ConfigClass::$OKEX_URL["k_line"] . "?symbol=" . $symbol . "&type=" . $min);
        if (empty($res)) {
        }
        $jsonRes = json_decode($res, true);
        $tmpAvgValue = [];
        foreach ($this->_line_number as $k => $v) {
            $avgValue = $this->_calculationAverageValue($jsonRes, $v);
            $tmpAvgValue[] = $avgValue;
        }
        $returnBool = true;
        //必须依次判断 k0>k1>k2>k3 才算成功，否则不下单
        foreach ($tmpAvgValue as $k => $v) {
            if (isset($tmpAvgValue[$k + 1])) {
                if ($tmpAvgValue[$k] < $tmpAvgValue[$k + 1]) {
                    $returnBool = false;
                }
            }
        }
        
        //计算第二次，去除K线的最后一位
        $tmpAvgValue2 = [];
        array_pop($jsonRes);
        foreach ($this->_line_number as $k => $v) {
            $avgValue = $this->_calculationAverageValue($jsonRes, $v);
            $tmpAvgValue2[] = $avgValue;
        }
        $returnBool2 = true;
        //必须依次判断 k0>k1>k2>k3 才算成功
        foreach ($tmpAvgValue2 as $k => $v) {
            if (isset($tmpAvgValue2[$k + 1])) {
                if ($tmpAvgValue2[$k] < $tmpAvgValue2[$k + 1]) {
                    $returnBool2 = false;
                }
            }
        }
        
        LogUtilsClass::WriteLogTable($this->_db, $this->_robot['id'], $this->_robot['user_id'],
            json_encode([$tmpAvgValue, $tmpAvgValue2]), "");
        //如果条件都成立，则返回买的平均K线
        if ($returnBool == true && $returnBool2 == false) {
            return [$tmpAvgValue, $tmpAvgValue2];
        }
        return [];
    }
    
    /**
     * 计算数据源的平均值 ，根据计算的多少条线
     * @param array $source_data 源数据
     * @param int $line_number 需要计算的多少条线
     * @return float
     */
    private function _calculationAverageValue(array $source_data, int $line_number): float
    {
        $tmpArr = array_slice($source_data, -$line_number);
        $tmpTotalValue = 0;
        /**
         *
         * [
         * 1417536000000,    时间戳
         * 2370.16,    开
         * 2380,        高
         * 2352,        低
         * 2367.37,    收
         * 17259.83    交易量
         * ]
         */
        foreach ($tmpArr as $k => $v) {
            $tmpTotalValue += $v[4];
        }
        return round($tmpTotalValue / $line_number, 4);
    }
    
    /**
     * 对外执行方法
     * 买入价格 = 买入价格+(买入价格-ma60)*3
     */
    public function run()
    {
        //先判断用户最后的订单状态
        $d = date("Y-m-d H:i:s", strtotime("-2 day"));
        
        $userKey = $this->_user_key;
        $userSecret = $this->_user_secret;
        $robotRes = $this->_robot;
        $configRule = $this->_config;
        $userRes = $this->_user;
        $trade_num = $this->_trade_num;
        
        //查询机器人的最后一订单，并根据订单的类型及状态，再决定是否下单
        $orderRes = $this->_db->query("select * from r_order where robot_id={$robotRes['id']} and create_time>='{$d}'" .
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
                $tmpRes = $this->_getIsTrade($configRule['config_type_week'], $configRule['symbol']);
                //如果是true则可以下单
                if ($tmpRes) {
                    //获取用户USDT余额
                    $usdt = $this->_getStockUsdt($userKey, $userSecret);
                    if ($usdt == 0) {
                        LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], "USDT余额不足", "");
                    } else {
                        $this->_kline = $tmpRes;
                        $this->_usdt = $usdt;
                        $this->buyOrder();
                    }
                } else {
                    LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], "策略要求未达到", "");
                }
            } else if ($orderType == 2) {
                //正常流程卖出
                $this->sellOrder();
            } else {
                // 如果都不在那几个状态，就时时的监听，并更新进度
                $ma60 = json_decode($orderRes['k_line_avg_data'], true);
                
                // 先获取市场价格
                $currentMarketDetail = $this->findCurrentMarketDetail();
                if (isset($currentMarketDetail['ticker']) && $currentMarketDetail['ticker']['last'] > end($ma60)) {
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
                        ], 'id=' . $orderRes['id']);
                        $msg = "success：机器人：" . $robotRes['id'] . $robotRes['robot_name'];
                        LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], $msg, $httpResponse);
                    } else {
                        $msg = "error：机器人：" . $robotRes['id'] . $robotRes['robot_name'] .
                            " 监听订单查询失败：" . $httpResponse;
                        LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], $msg, $httpResponse);
                    }
                } else {
                    //时时监控市场价 当小于买入时的ma60价格
                    //撤消原有单，重新下单，按市价直接卖出
                    $err = $this->cancelOrder($orderRes['id']);
                    if ($err) {
                        //撤消成功
                        LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], "撤消失败", "");
                    } else {
                        //撤消失败
                        LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], "撤消成功", "");
                    }
                    $httpResponse = $this->findThirdOrderInfo();
                    $json = json_decode($httpResponse, true);
                    if (isset($json['result']) && $json['result'] == true) {
                        // 市场价卖出，挂卖出单
                        if (isset($json['orders'][0])) {
                            // $okexOrderDetail['status'] -1:已撤销  0:未成交  1:部分成交  2:完全成交 3:撤单处理中
                            $okexOrderDetail = $json['orders'][0];
                            
                            $price = $currentMarketDetail['last'];
                            
                            $buyData = [
                                "api_key" => $userKey,
                                "symbol" => $configRule['symbol'],
                                "amount" => $okexOrderDetail['deal_amount'],
                                "price" => $price,
                                "type" => OkexBuyTypeClass::$BuyAndSell[$robotRes['business_type']],
                            ];
                            $newBuyData = OkexBuyTypeClass::switchType($buyData, $userSecret);
                            $httpResponse = CurlUtilsClass::getHttpPostRes($newBuyData, ConfigClass::$OKEX_URL['trade']);
                            
                            $json = json_decode($httpResponse, true);
                            if (isset($json['result']) && $json['result'] == true) {
                                $orderAutoId = $this->_db->insert("r_order",
                                    [
                                        "third_id" => $json['order_id'],
                                        "robot_id" => $robotRes['id'],
                                        "user_id" => $userRes['id'],
                                        "order_type" => $orderType,
                                        'create_time' => date("Y-m-d H:i:s"),
                                        'symbol' => $configRule['symbol'],
                                        'trade_type' => OkexBuyTypeClass::$BuyAndSell[$robotRes['business_type']],
                                        'platform_id' => 1,
                                        'k_line_avg_data' => $orderRes['k_line_avg_data'],
                                        'k_line_avg_data_2' => $orderRes['k_line_avg_data_2'],
                                    ]
                                );
                                //同时向订单记录表插入一条记录
                                $this->_db->insert("r_order_state",
                                    [
                                        "order_id" => $orderAutoId,
                                        "third_id" => $json['order_id'],
                                        "state" => 1,
                                        'create_time' => date("Y-m-d H:i:s")
                                    ]
                                );
                                //更新机器人的交易次数
                                ++$trade_num;
                                $this->_db->update('r_users_robot', ['trade_num' => $trade_num, 'real_trade_num' => $trade_num],
                                    'id=' . $robotRes['id'] . ' and user_id=' . $robotRes['user_id']);
                            } else {
                                $msg = "error：机器人：" . $robotRes['id'] . $robotRes['robot_name'] .
                                    " 请求参数：" . json_encode($newBuyData) . " 卖出失败：" . $httpResponse;
                                LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], $msg, $httpResponse);
                            }
                        }
                    } else {
                        $msg = "error：机器人：" . $robotRes['id'] . $robotRes['robot_name'] .
                            "卖出查询失败：" . $httpResponse;
                        LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], $msg, $httpResponse);
                    }
                }
            }
        } else {
            //为空，直接下单
            $tmpRes = $this->_getIsTrade($configRule['config_type_week'], $configRule['symbol']);
            //如果是true则可以下单
            if ($tmpRes) {
                //获取用户USDT余额
                $usdt = $this->_getStockUsdt($userKey, $userSecret);
                if ($usdt == 0) {
                    LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], "USDT余额不足", "");
                } else {
                    $this->_kline = $tmpRes;
                    $this->_usdt = $usdt;
                    $this->buyOrder();
                }
            } else {
                LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], "策略要求未达到", "");
            }
        }
    }
    
    
    /**
     * 卖出订单操作
     *
     */
    public function sellOrder()
    {
        
        //如果订单数量交易量为0或者空，证明订单信息未获取到，重新获取
        if ($this->_order['deal_amount'] == 0.00000000) {
            $thirdOrderRes = $this->findThirdOrderInfo($this->_user_key, $this->_user_secret, $this->_config['symbol'], $this->_order['third_id']);
            if ($thirdOrderRes) {
                //更新订单一些相关信息
                $this->_db->update('r_order', [
                    'state' => $thirdOrderRes['status'],
                    'amount' => $thirdOrderRes['amount'],
                    'price' => $thirdOrderRes['price'],
                    'deal_amount' => $thirdOrderRes['deal_amount'],
                    'avg_price' => $thirdOrderRes['avg_price'],
                    'update_time' => date('Y-m-d H:i:s'),
                ], 'id=' . $this->_order['id']);
                $this->_order = $this->_db->query("select * from r_order where id={$this->_order['id']}", 'Row');
            }
        }
        
        $httpResponse = $this->findThirdOrderInfo();
        $json = json_decode($httpResponse, true);
        if (isset($json['result']) && $json['result'] == true) {
            // 同时根据配置的 【（买入价格-ma60）* 2.5 + 买入价格 】，挂卖出单
            if (isset($json['orders'][0])) {
                $orderDetail = $json['orders'][0];
                $ma60 = json_decode($this->_order['k_line_avg_data'], true);
                $price = $this->_order['avg_price'] + ($this->_order['avg_price'] - end($ma60)) * 2.5;
                
                $buyData = [
                    "api_key" => $this->_user_key,
                    "symbol" => $this->_config['symbol'],
                    "amount" => $this->_order['deal_amount'],
                    "price" => $price,
                    "type" => OkexBuyTypeClass::$BuyAndSell[$this->_order['business_type']],
                ];
                $newBuyData = OkexBuyTypeClass::switchType($buyData, $this->_user_secret);
                $httpResponse = CurlUtilsClass::getHttpPostRes($newBuyData, ConfigClass::$OKEX_URL['trade']);
                
                $json = json_decode($httpResponse, true);
                if (isset($json['result']) && $json['result'] == true) {
                    $orderAutoId = $this->_db->insert("r_order",
                        [
                            "third_id" => $json['order_id'],
                            "robot_id" => $this->_robot['id'],
                            "user_id" => $this->_user['id'],
                            "order_type" => 2,
                            'create_time' => date("Y-m-d H:i:s"),
                            'symbol' => $this->_config['symbol'],
                            'trade_type' => OkexBuyTypeClass::$BuyAndSell[$this->_robot['business_type']],
                            'platform_id' => 1,
                            'k_line_avg_data' => $this->_order['k_line_avg_data'],
                            'k_line_avg_data_2' => $this->_order['k_line_avg_data_2'],
                        ]
                    );
                    //同时向订单记录表插入一条记录
                    $this->_db->insert("r_order_state",
                        [
                            "order_id" => $orderAutoId,
                            "third_id" => $json['order_id'],
                            "state" => 1,
                            'create_time' => date("Y-m-d H:i:s")
                        ]
                    );
                    //更新机器人的交易次数
                    ++$this->_trade_num;
                    $this->_db->update('r_users_robot', ['trade_num' => $this->_trade_num, 'real_trade_num' => $this->_trade_num],
                        'id=' . $this->_robot['id'] . ' and user_id=' . $this->_robot['user_id']);
                } else {
                    $msg = "error：机器人：" . $this->_robot['id'] . $this->_robot['robot_name'] .
                        " 请求参数：" . json_encode($newBuyData) . " 卖出失败：" . $httpResponse;
                    LogUtilsClass::WriteLogTable($this->_db, $this->_robot['id'], $this->_robot['user_id'], $msg, $httpResponse);
                }
            }
        } else {
            $msg = "error：机器人：" . $this->_robot['id'] . $this->_robot['robot_name'] .
                " 卖出查询失败：" . $httpResponse;
            LogUtilsClass::WriteLogTable($this->_db, $this->_robot['id'], $this->_robot['user_id'], $msg, $httpResponse);
        }
    }
    
    /**
     * 买入操作
     *
     */
    public function buyOrder()
    {
        
        $checkTradeNumBool = $this->checkTradeNum();
        if (!$checkTradeNumBool) {
            return false;
        }
        
        $buyData = [
            "api_key" => $this->_user_key,
            "symbol" => $this->_config['symbol'],
            "amount" => $this->_config['price'],
            "price" => $this->_config['amount'] * 0.1, // usdt 余额的10%
            "type" => $this->_robot['business_type'],
        ];
        
        $newBuyData = OkexBuyTypeClass::switchType($buyData, $this->_user_secret);
        $httpResponse = CurlUtilsClass::getHttpPostRes($newBuyData, ConfigClass::$OKEX_URL['trade']);
        $json = json_decode($httpResponse, true);
        //针对订单下单成功做存储操作 {"result":true,"order_id":123456}
        if (isset($json['result']) && $json['result'] == true) {
            $orderAutoId = $this->_db->insert("r_order",
                [
                    "third_id" => $json['order_id'],
                    "order_type" => 1,
                    "robot_id" => $this->_robot['id'],
                    "user_id" => $this->_user['id'],
                    "order_type" => 1,
                    'create_time' => date("Y-m-d H:i:s"),
                    'symbol' => $this->_config['symbol'],
                    'trade_type' => $this->_robot['business_type'],
                    'platform_id' => 1,
                    'k_line_avg_data' => json_encode($this->_kline[0]),
                    'k_line_avg_data_2' => json_encode($this->_kline[1]),
                ]
            );
            //同时向订单记录表插入一条记录
            $this->_db->insert("r_order_state",
                [
                    "order_id" => $orderAutoId,
                    "third_id" => $json['order_id'],
                    "state" => 1,
                    'create_time' => date("Y-m-d H:i:s")
                ]
            );
            
            //更新机器人的交易次数
            ++$this->_trade_num;
            $this->_db->update('r_users_robot', ['trade_num' => $this->_trade_num],
                'id=' . $this->_robot['id'] . ' and user_id=' . $this->_robot['user_id']);
            
            $httpResponse = $this->findThirdOrderInfo();
            $json = json_decode($httpResponse, true);
            if (isset($json['result']) && $json['result'] == true) {
                // $okexOrderDetail['status'] -1:已撤销  0:未成交  1:部分成交  2:完全成交 3:撤单处理中
                $okexOrderDetail = $json['orders'][0];
                //更新订单一些相关信息
                $this->_db->update('r_order', [
                    'state' => $okexOrderDetail['state'],
                    'amount' => $okexOrderDetail['amount'],
                    'price' => $okexOrderDetail['price'],
                    'deal_amount' => $okexOrderDetail['deal_amount'],
                    'avg_price' => $okexOrderDetail['avg_price'],
                    'update_time' => date('Y-m-d H:i:s'),
                ], 'id=' . $orderAutoId);
            } else {
                $msg = "error：机器人：" . $this->_robot['id'] . $this->_robot['robot_name'] .
                    " 买入查询失败：" . $httpResponse;
                LogUtilsClass::WriteLogTable($this->_db, $this->_robot['id'], $this->_robot['user_id'], $msg, $httpResponse);
            }
        } else {
            $msg = "error：机器人：" . $this->_robot['id'] . $this->_robot['robot_name'] .
                " 请求参数：" . json_encode($newBuyData) . " 买入失败：" . $httpResponse;
            LogUtilsClass::WriteLogTable($this->_db, $this->_robot['id'], $this->_robot['user_id'], $msg, $httpResponse);
        }
    }
    
}