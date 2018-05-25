<?php
/**
 * 5线顺模型 策略 3线 策略1 分批建仓，固定止盈
 *
 * 5分钟
 *
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/5/12
 * Time: 下午3:52
 */

include_once 'interface.php';
include_once 'abstract.php';


class FiveLineShunThreeFirstFiveClass extends BaseAbstractClass implements BaseInterface
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
    private $_skewing_percent = 0;
    private $_week_min = "5min";
    
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
     * @return bool|float
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
                $this->_skewing_percent = $skewingPercent;
                return true;
            }
            return false;
        }
        return false;
    }
    
    /**
     * 出场条件
     *
     * 1、止损设定：ma55，最终止损
     * 2、止盈设定：
     * 止盈挂单：偏离度<0.3,则止盈设定为：进场价格*1.01.
     * 偏离度>0.3,则止盈设定为：进场价格*（100+偏离度*2.5）/100
     *
     * @return bool
     */
    public function checkMa55AndSkewingPercent()
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
        
        //取机器人配置周期
        $res = CurlUtilsClass::getHttpRes(ConfigClass::$OKEX_URL["k_line"] . "?symbol=" .
            $this->_config['symbol'] . "&type=" . $this->_config['config_type_week']);
        
        if (empty($res)) {
            return false;
        }
        $jsonRes = json_decode($res, true);
        //计算时时ma55值
        $ma55 = $this->_calculationAverageValue($jsonRes, 55);
        
        //计算止盈价格
        if ($this->_order['skewing_percent'] < 0.3) {
            $price = $this->_order['avg_price'] * 1.01;
        } else {
            $price = $this->_order['avg_price'] * (100 + $this->_order['skewing_percent'] * 2.5) / 100;
        }
        
        //获取市价
        $currentMarketDetail = $this->findCurrentMarketDetail();
        if (isset($currentMarketDetail['ticker'])) {
            $c = $currentMarketDetail['ticker']['last'];
            if ($c < $ma55 || $c >= $price) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 限价单的报价方式：15分钟ma10
     *
     */
    public function getMa10By15()
    {
        //取机器人配置周期
        $res = CurlUtilsClass::getHttpRes(ConfigClass::$OKEX_URL["k_line"] . "?symbol=" .
            $this->_config['symbol'] . "&type=15min");
        
        if (empty($res)) {
            return false;
        }
        $jsonRes = json_decode($res, true);
        //计算时时ma10值
        $ma10 = $this->_calculationAverageValue($jsonRes, 10);
        return $ma10;
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
                        if ($tmpSkewingPercent !== false) {
                            $this->buyOrder();
                        }
                    }
                } else {
                    LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], "策略要求未达到", "");
                }
            } else if ($orderType == 2) {
                //市价<ma55 止损 or 市>=止盈价格
                //止盈价格分两种，根据偏移度来计算
                // 偏离度>0.3,则止盈设定为：进场价格*（100+偏离度*2.5）/100
                // 偏离度<0.3,则止盈设定为：进场价格*1.01
                $ma55_skewingRes = $this->checkMa55AndSkewingPercent();
                if ($ma55_skewingRes == true) {
                    $this->sellOrder();
                }
            } else {
                //监听
                $this->listenOrder();
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
                    if ($tmpSkewingPercent !== false) {
                        $this->buyOrder();
                    }
                }
            } else {
                LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], "策略要求未达到", "");
            }
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
    
    /**
     * 买入操作
     */
    public function buyOrder()
    {
    
        $checkTradeNumBool = $this->checkTradeNum();
        if (!$checkTradeNumBool) {
            return false;
        }
        
        //市价 进入50%仓位
        $buyData = [
            "api_key" => $this->_user_key,
            "symbol" => $this->_config['symbol'],
            "amount" => $this->_config['price'],
            "price" => $this->_config['amount'] * 0.5, // usdt 余额的50%
            "type" => "buy_market",
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
                    'skewing_percent' => $this->_skewing_percent,
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
            
            //限价单同时买入
            $this->_buyOrder();
            
            //重新获取order详情
            $this->_order = $this->_db->query("select * from r_order where id={$orderAutoId}", 'Row');
            
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
    
    //限价单同时购买
    private function _buyOrder()
    {
        $httpResponse = $this->findThirdOrderInfo();
        $json = json_decode($httpResponse, true);
        if (isset($json['result']) && $json['result'] == true) {
            $okexOrderDetail = $json['orders'][0];
        }
        //限价 进入50%仓位
        $buyData = [
            "api_key" => $this->_user_key,
            "symbol" => $this->_config['symbol'],
            "amount" => $okexOrderDetail['deal_amount'],
            "price" => $this->_config['amount'] * 0.5, // usdt 余额的50%
            "type" => "buy",
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
                    'skewing_percent' => $this->_skewing_percent,
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
    
    
    /**
     * 卖出订单操作
     *
     */
    public function sellOrder()
    {
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