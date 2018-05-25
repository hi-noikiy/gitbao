<?php
/**
 * 机器人终结者
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


class RobotTerminatorThirtyClass extends BaseAbstractClass implements BaseInterface
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
    private $_week_min = "30min";
    //止盈系数
    private $_ratio = 1.020;
    //仓位系数
    private $_usdt_ratio = 0.10;
    private $_is_check_big = true;
    
    public function checkBigCycle()
    {
        parent::checkBigCycle();
        //初始条件：1小时周期
        // Ma7>=ma21, 且上一根为否！
        $res = CurlUtilsClass::getHttpRes(ConfigClass::$OKEX_URL["k_line"] . "?symbol=" . $this->_config['symbol'] . "&type=1hour");
        if (empty($res)) {
            return false;
        }
        $jsonRes = json_decode($res, true);
        $tmpAvgValue = [];
        foreach ([7, 21] as $k => $v) {
            $avgValue = $this->_calculationAverageValue($jsonRes, $v);
            $tmpAvgValue[] = $avgValue;
        }
        $returnBool = true;
        //必须依次判断 k0>=k1 才算成功，否则不下单
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
        foreach ([7, 21] as $k => $v) {
            $avgValue = $this->_calculationAverageValue($jsonRes, $v);
            $tmpAvgValue2[] = $avgValue;
        }
        $returnBool2 = true;
        //必须依次判断 k0>k1 才算成功
        foreach ($tmpAvgValue2 as $k => $v) {
            if (isset($tmpAvgValue2[$k + 1])) {
                if ($tmpAvgValue2[$k] < $tmpAvgValue2[$k + 1]) {
                    $returnBool2 = false;
                }
            }
        }
        
        // Ma7>=ma21 成立, Ma7>=ma21 不成立 则可以进入下一个条件
        if ($returnBool == true && $returnBool2 == false) {
            return true;
        }
        return false;
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
        //$this->_is_check_big 为true则表示，按初始周期走，默认按初始周期
        if ($this->_is_check_big) {
            $bigRes = $this->checkBigCycle();
            if (!$bigRes) {
                return [];
            }
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
        //如果ma7>ma21,且市价<ma7,则进场做多
        foreach ($tmpAvgValue as $k => $v) {
            if (isset($tmpAvgValue[$k + 1])) {
                if ($tmpAvgValue[$k] <= $tmpAvgValue[$k + 1]) {
                    $returnBool = false;
                }
            }
        }
        //获取市价 $c初始价，防止出错
        $c = 100000000000;
        $currentMarketDetail = $this->findCurrentMarketDetail();
        if (isset($currentMarketDetail['ticker'])) {
            $c = $currentMarketDetail['ticker']['last'];
        }
    
        LogUtilsClass::WriteLogTable($this->_db, $this->_robot['id'], $this->_robot['user_id'],
            "".json_encode([$tmpAvgValue,$c]), "");
        //如果条件都成立，则返回买的平均K线
        if ($returnBool == true && $c < $tmpAvgValue[0]) {
            return [$tmpAvgValue, [$c]];
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
     * 死亡条件 ：Ma7<ma21, 则进入初始条件的循环!
     *
     * @return bool
     */
    private function _checkMa7AndMa21()
    {
        //$res = CurlUtilsClass::getHttpRes(ConfigClass::$OKEX_URL["k_line"] . "?symbol=" . $symbol . "&type=" . $min);
        $res = CurlUtilsClass::getHttpRes(ConfigClass::$OKEX_URL["k_line"] . "?symbol=" . $this->_config['symbol']
            . "&type=" . $this->_week_min);
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
        // Ma7<ma21, 则进入初始条件的循环!
        foreach ($tmpAvgValue as $k => $v) {
            if (isset($tmpAvgValue[$k + 1])) {
                if ($tmpAvgValue[$k] >= $tmpAvgValue[$k + 1]) {
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
            } else if ($orderRes['order_type'] == "2" && $orderRes['is_over_state'] == "2") {
                //类型为卖出，死亡状态为2，则进行下单
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
                        $this->buyOrder();
                    }
                } else {
                    LogUtilsClass::WriteLogTable($this->_db, $robotRes['id'], $robotRes['user_id'], "策略要求未达到", "");
                }
            } else if ($orderType == 2) {
                $this->sellOrder();
            } else {
                //监听
                $lState = $this->listenOrder();
                //如果完全成交，则设置大周期判断为false
                if ($lState == 2) {
                    $this->_is_check_big = false;
                } else {
                    $tmpRes = $this->_checkMa7AndMa21();
                    //如果死亡条件成立，则设置大周期判断为true，且将该单子死亡标记为2，防止继续监听该订单
                    if ($tmpRes) {
                        $this->_is_check_big = true;
                        //更新订单死亡标记
                        $this->_db->update('r_order', [
                            'is_over_state' => 2,
                            'update_time' => date('Y-m-d H:i:s'),
                        ], 'id=' . $this->_order['id']);
                    }
                }
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
                    $this->buyOrder();
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
            return $okexOrderDetail['status'];
        } else {
            $msg = "error：机器人：" . $this->_robot['robot_name'] . " 监听订单查询失败：" . $httpResponse;
            LogUtilsClass::WriteLogTable($this->_db, $this->_robot['id'], $this->_robot['user_id'], $msg, $httpResponse);
            return 0;
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
            "price" => $this->_config['amount'] * $this->_usdt_ratio, // usdt 全额
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
        $httpResponse = $this->findThirdOrderInfo();
        
        $json = json_decode($httpResponse, true);
        if (isset($json['result']) && $json['result'] == true) {
            // 市价卖单
            if (isset($json['orders'][0])) {
                //止盈价格：进场价格 * $this->_ratio
                $buyData = [
                    "api_key" => $this->_user_key,
                    "symbol" => $this->_config['symbol'],
                    "amount" => $this->_order['deal_amount'],
                    "price" => $this->_order['avg_price'] * $this->_ratio,
                    "type" => "sell",
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