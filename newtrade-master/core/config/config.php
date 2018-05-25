<?php
/**
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/4/14
 * Time: 上午8:38
 */


class ConfigClass
{
    static $DB = [
        'HOST' => '127.0.0.1',
        'PORT' => '3306',
        'USER' => 'root',
        'PASSWORD' => '1234567890',
        'DB_NAME' => 'robot_trade',
        'CHARSET' => 'utf8',
    ];
    
    static $OKEX_URL = [
        'trade' => 'https://www.okex.com/api/v1/trade.do',
        'order_info' => 'https://www.okex.com/api/v1/order_info.do',
        'cancel' => 'https://www.okex.com/api/v1/cancel_order.do',
        'k_line' => 'https://www.okex.com/api/v1/kline.do',
        'user_info' => 'https://www.okex.com/api/v1/userinfo.do',
        'ticket_info' => 'https://www.okex.com/api/v1/ticker.do?symbol=',
    ];
}

define("AES_KEY", "okex_zp_j9n23,j234k2-+1212m234jj3");
define("CORE_PATH", __DIR__);
