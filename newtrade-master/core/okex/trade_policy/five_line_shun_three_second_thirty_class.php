<?php
/**
 * 5线顺模型 策略 3线 策略2 市价建仓 死叉止盈
 *
 * 30分钟
 *
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/5/12
 * Time: 下午3:52
 */

include_once 'interface.php';
include_once 'abstract.php';


class FiveLineShunThreeSecondThirtyClass extends BaseAbstractClass implements BaseInterface
{
    
    public $_line_number = [7, 21, 55]; //计算平均值使用的多少条线的数组
    public $_db = null;
    public $_user = null;
    public $_user_key = null, $_user_secret = null;
    public $_config = null;
    public $_robot = null;
    public $_trade_num = null;
    private $_usdt = 0;
    private $_kline = [];
    private $_week_min = "30min";
    
    public function checkBigCycle()
    {
        parent::checkBigCycle();
        //取1小时周期均线 计算规则ma7>=ma21>=ma55
        $res = CurlUtilsClass::getHttpRes(ConfigClass::$OKEX_URL["k_line"] . "?symbol=" . $this->_config['symbol'] . "&type=1hour");
        if (empty($res)) {
            return false;
        }
        $jsonRes = json_decode($res, true);
        $tmpAvgValue = [];
        foreach ($this->_line_number as $k => $v) {
            $avgValue = $this->_calculationAverageValue($jsonRes, $v);
            $tmpAvgValue[] = $avgValue;
        }
        $returnBool = true;
        //必须依次判断 k0>k1>k2 才算成功，否则不下单
        foreach ($tmpAvgValue as $k => $v) {
            if (isset($tmpAvgValue[$k + 1])) {
                if ($tmpAvgValue[$k] < $tmpAvgValue[$k + 1]) {
                    $returnBool = false;
                }
            }
        }
        
        LogUtilsClass::WriteLogTable($this->_db, $this->_robot['id'], $this->_robot['user_id'],
            "大周期 ".json_encode($tmpAvgValue), "");
        return $returnBool;
    }
    
    /**
     * 获取K线数据源
     * @param int $min 要获取的K线周期
     * @param string $symbol 要获取某一个币种的K线值，例：eos_usdt
     * @return array
     */
    private function _getIsTrade(string $min, string $symbol): array
    {
        //判断大周期
        $bigRes = $this->checkBigCycle();
        if (!$bigRes) {
            return [];
        }
        //$res = CurlUtilsClass::getHttpRes(ConfigClass::$OKEX_URL["k_line"] . "?symbol=" . $symbol . "&type=" . $min);
        //使用固定周期
        $res = CurlUtilsClass::getHttpRes(ConfigClass::$OKEX_URL["k_line"] . "?symbol=" . $symbol . "&type=" . $this->_week_min);
        if (empty($res)) {
            return [];
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
     * 计算偏移差
     *
     * @return bool
     */
    public function checkSkewingPercent()
    {
        //最新市价与ma55之间的偏差，即偏离率=（市价-ma55）/市价*100
        //如果偏离率>=2,则进场条件不成立。
        //如果偏离率<2,则进场条件成立。
        
        // 先获取市场价格
        $currentMarketDetail = $this->findCurrentMarketDetail();
        if (isset($currentMarketDetail['ticker'])) {
            $c = $currentMarketDetail['ticker']['last'];
            $skewingPercent = ($c - end($this->_kline[0])) / $c * 100;
            if ($skewingPercent < 2) {
                return true;
            }
            return false;
        }
        return false;
    }
    
    /**
     * 出场条件：ma7和ma21日线死叉，则市价出掉，手中持有的单量
     *
     * @return bool
     */
    public function checkMa7AndMa21()
    {
        $week = [7, 21];
        //取机器人配置周期 计算规则ma7<=ma21
        $res = CurlUtilsClass::getHttpRes(ConfigClass::$OKEX_URL["k_line"] . "?symbol=" .
            $this->_config['symbol'] . "&type=" . $this->_config['config_type_week']);
        
        if (empty($res)) {
            return false;
        }
        $jsonRes = json_decode($res, true);
        $tmpAvgValue = [];
        foreach ($week as $k => $v) {
            $avgValue = $this->_calculationAverageValue($jsonRes, $v);
            $tmpAvgValue[] = $avgValue;
        }
        $returnBool = true;
        //必须依次判断 k0<=k1 才算成功，否则不下单
        foreach ($tmpAvgValue as $k => $v) {
            if (isset($tmpAvgValue[$k + 1])) {
                if ($tmpAvgValue[$k] > $tmpAvgValue[$k + 1]) {
                    $returnBool = false;
                }
            }
        }
        return $returnBool;
    }
    
    
    /**
     * 对外执行方法
     */
    public function run()
    {
        $this->_trade_num = $this->_robot['trade_num'];
        
        //先判断用户最后的订单状态
        $d = date("Y-m-d H:i:s", strtotime("-2 day"));
        
        $robotRes = $this->_robot;
        $configRule = $this->_config;
        
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
                    $usdt = $this->getStockUsdt();
                    if ($usdt == 0) {
                        LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], "USDT余额不足", "");
                    } else {
                        $this->_kline = $tmpRes;
                        $this->_usdt = $usdt;
                        $tmpSkewingPercent = $this->checkSkewingPercent();
                        if ($tmpSkewingPercent == true) {
                            $this->buyOrder();
                        }
                    }
                } else {
                    LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], "策略要求未达到", "");
                }
            } else if ($orderType == 2) {
                //时时的ma7<=ma21 市价完全卖出
                $ma5_71Res = $this->checkMa7AndMa21();
                if ($ma5_71Res == true) {
                    $this->sellOrder();
                }
            } else {
            
            }
        } else {
            //为空，直接下单
            $tmpRes = $this->_getIsTrade($configRule['config_type_week'], $configRule['symbol']);
            //如果是true则可以下单
            if ($tmpRes) {
                //获取用户USDT余额
                $usdt = $this->getStockUsdt();
                if ($usdt == 0) {
                    LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], "USDT余额不足", "");
                } else {
                    $this->_kline = $tmpRes;
                    $this->_usdt = $usdt;
                    $tmpSkewingPercent = $this->checkSkewingPercent();
                    if ($tmpSkewingPercent == true) {
                        $this->buyOrder();
                    }
                }
            } else {
                LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], "策略要求未达到", "");
            }
        }
    }
    
    /**
     * 买入操作
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
            "price" => $this->_config['amount'] * 0.7, // usdt 余额的10%
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
            
            //重新获取order详情
            $this->_order = $this->_db->query("select * from r_order where id={$orderAutoId}", 'Row');
            
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
    
    
    /**
     * 卖出订单操作
     *
     */
    public function sellOrder()
    {
        //如果订单数量交易量为0或者空，证明订单信息未获取到，重新获取
        if ($this->_order['deal_amount'] == 0.00000000) {
            $thirdOrderRes = $this->findThirdOrderInfo();
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
                $orderRes = $this->_db->query("select * from r_order where id={$this->_order['id']}", 'Row');
                $this->_order = $orderRes;
            }
        }
        
        $httpResponse = $this->findThirdOrderInfo();
        
        $json = json_decode($httpResponse, true);
        if (isset($json['result']) && $json['result'] == true) {
            // 市价卖单
            if (isset($json['orders'][0])) {
                //查询市价价格
                $currentDetail = $this->findCurrentMarketDetail();
                $buyData = [
                    "api_key" => $this->_user_key,
                    "symbol" => $this->_config['symbol'],
                    "amount" => $this->_order['deal_amount'],
                    "price" => $currentDetail['ticker']['last'],
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
                            'trade_type' => OkexBuyTypeClass::$BuyAndSell[$this->_order['business_type']],
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
                " 请求参数 卖出查询失败：" . $httpResponse;
            LogUtilsClass::WriteLogTable($this->_db, $this->_robot['id'], $this->_robot['user_id'], $msg, $httpResponse);
        }
    }
    
    
}