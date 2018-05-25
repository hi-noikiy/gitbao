<?php
/**
 * 抽象策略交易类，基础类
 *
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/5/13
 * Time: 下午3:55
 */

abstract class BaseAbstractClass
{
    public $_line_number = [7, 21]; //计算平均值使用的多少条线的数组
    public $_db = null;
    public $_user = null;
    public $_user_key = null, $_user_secret = null;
    public $_config = null;
    public $_robot = null;
    public $_trade_num = null;
    public $_order = null;
    
    /**
     * 判断大周期条件
     */
    public function checkBigCycle()
    {
    }
    
    /**
     * 时时监听进度
     */
    public function listenOrder()
    {
    }
    
    /**
     * 查询OKex下单的信息
     *
     * [
     * amount:委托数量
     * create_date: 委托时间
     * avg_price:平均成交价
     * deal_amount:成交数量
     * order_id:订单ID
     * orders_id:订单ID(不建议使用)
     * price:委托价格
     * status:-1:已撤销  0:未成交  1:部分成交  2:完全成交 3:撤单处理中
     * type:buy_market:市价买入 / sell_market:市价卖出
     * ]
     *
     * @return bool|array
     */
    public function findThirdOrderInfo()
    {
        $requestParams = [
            "api_key" => $this->_user_key,
            "symbol" => $this->_config['symbol'],
            "order_id" => $this->_order['third_id'],
        ];
        
        $requestParamsNew = OkexSingClass::sign($requestParams, $this->_user_secret);
        $httpResponse = CurlUtilsClass::getHttpPostRes($requestParamsNew, ConfigClass::$OKEX_URL['order_info']);
        $json = json_decode($httpResponse, true);
        if (isset($json['result']) && $json['result'] == true) {
            if (isset($json['orders'][0])) {
                return $json['orders'][0];
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
    
    
    /**
     * 查询用户的USDT余额
     *
     * @return int
     */
    public function getStockUsdt()
    {
        $url = ConfigClass::$OKEX_URL['user_info'];
        $res = CurlUtilsClass::getHttpPostRes(null, $url . '?' . OkexSingClass::sign(['api_key' => $this->_user_key]
                , $this->_user_secret));
        $res = json_decode($res, true);
        $returnValue = 0;
        if ($res['result']) {
            $fundsFree = $res['info']['funds']['free'];
            $fundsFreezed = $res['info']['funds']['freezed'];
            foreach ($fundsFree as $k => $v) {
                if ($k == "usdt") {
                    $returnValue = $v;
                    break;
                }
            }
            
            return $returnValue;
        }
        return $returnValue;
    }
    
    /**
     * 买入操作
     */
    public function buyOrder()
    {
    }
    
    
    /**
     * 卖出订单操作
     *
     */
    public function sellOrder()
    {
    }
    
    /**
     * 取消订单 订单ID
     *
     * @return bool
     */
    public function cancelOrder()
    {
        $oid = $this->_order['id'];
        $orderRes = $this->_db->query('select * from r_order where id=' . $oid, 'Row');
        if (empty($orderRes)) {
            return false;
        }
        //查询订单状态
        //如果是在【0未成交、1部分成交】则取消订单
        $userKey = $this->_user_key;
        $userSecret = $this->_user_secret;
        $requestParams = [
            "api_key" => $userKey,
            "symbol" => $orderRes['symbol'],
            "order_id" => $orderRes['third_id'],
        ];
        $requestParamsNew = OkexSingClass::sign($requestParams, $userSecret);
        $httpResponse = CurlUtilsClass::getHttpPostRes($requestParamsNew, "https://www.okex.com/api/v1/order_info.do");
        $json = json_decode($httpResponse, true);
        if (isset($json['result']) && $json['result'] == true) {
            // $okexOrderDetail['status'] -1:已撤销  0:未成交  1:部分成交  2:完全成交 3:撤单处理中
            if (!isset($json['orders'][0])) {
                $this->__json(101, "查询第三方订单数据失败，订单为空");
            }
            $okexOrderDetail = $json['orders'][0];
            //更新订单一些相关信息
            $this->_db->update('r_order', [
                'state' => $okexOrderDetail['status'],
                'update_time' => date('Y-m-d H:i:s'),
            ], 'id=' . $orderRes['id']);
            if ($okexOrderDetail['status'] == 0 || $okexOrderDetail['status'] == 1) {
                //取消订单
                $cancelOrderUrl = "https://www.okex.com/api/v1/cancel_order.do";
                $requestParamsNew = OkexSingClass::sign($requestParams, $userSecret);
                $httpResponse = CurlUtilsClass::getHttpPostRes($requestParamsNew, $cancelOrderUrl);
                $json = json_decode($httpResponse, true);
                if (isset($json['result']) && $json['result'] == true) {
                    $this->_db->update('r_order', [
                        'state' => -1,
                        'update_time' => date('Y-m-d H:i:s'),
                    ], 'id=' . $orderRes['id']);
                    $this->_db->insert('r_order_state', [
                        'order_id' => $orderRes['id'],
                        'third_id' => $orderRes['third_id'],
                        'create_time' => date('Y-m-d H:i:s'),
                        'state' => -1,
                    ]);
                    return true;
                } else {
                    return false;
                }
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
    
    
    /**
     * 判断交易次数
     *
     * @return bool
     */
    public function checkTradeNum()
    {
        $robotTradeUseNum = intval($this->_robot['trade_use_num']) * 2;
        $robotTradeNum = $this->_robot['trade_num'];
        if ($robotTradeNum >= $robotTradeUseNum) {
            //如果为false则停止机器人，杀死进程
            $this->_db->update('r_users_robot', [
                'state' => 2,
                'update_time' => date('Y-m-d H:i:s'),
            ], " id={$this->_robot['id']}");
            //查询进程，并且杀死
            $processRes = $this->_db->query("select * from r_users_robot_process where
  robot_id={$this->_robot['id']} and user_id={$this->_robot['user_id']} and robot_state = 1", "Row");
            $this->_db->update('r_users_robot_process', [
                'robot_state' => 2,
                'update_time' => date('Y-m-d H:i:s'),
            ], " robot_id={$this->_robot['id']} and user_id={$this->_robot['user_id']}");
            
            LogUtilsClass::WriteLogTable($this->_db, $this->_robot['id'], $this->_robot['user_id'],
                "使用次数达到限制，自动关闭", "");
            
            $killState = \Swoole\Process::kill($processRes["robot_process_id"], 0);
            if ($killState) {
                \Swoole\Process::kill($processRes['robot_process_id']);
            }
            return false;
        }
        return true;
    }
    
    /**
     * 查询市场行情
     *
     *
     * {
     * "date":"1410431279",
     * "ticker":{
     * "buy":"33.15",
     * "high":"34.15",
     * "last":"33.15",
     * "low":"32.05",
     * "sell":"33.16",
     * "vol":"10532696.39199642"
     * }
     * }
     *
     *
     * date: 返回数据时服务器时间
     * buy: 买一价
     * high: 最高价
     * last: 最新成交价
     * low: 最低价
     * sell: 卖一价
     * vol: 成交量(最近的24小时)
     */
    public function findCurrentMarketDetail()
    {
        $currentMarketDetail = CurlUtilsClass::getHttpRes(ConfigClass::$OKEX_URL['ticket_info'] . $this->_config['symbol']);
        $currentMarketDetail = json_decode($currentMarketDetail, true);
        return $currentMarketDetail;
    }
    
}