<?php
/**
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/4/21
 * Time: 上午8:47
 */
include_once "okex_sign_class.php";

class OkexBuyTypeClass
{
    public static $BuyAndSell = [
        'buy_market'=>'sell_market',   //sell_market
        'sell_market'=>'buy_market',
        'buy'=>'sell',
        'sell'=>'buy', //buy
    ];
    
    public static function switchType( $params, $secret_key)
    {
        $res = null;
        switch ($params['type']) {
            case "buy_market":
                $res = self::buyMarket($params, $secret_key);
                break;
            case "sell_market":
                $res = self::sellMarket($params, $secret_key);
                break;
            case "buy":
                $res = self::buy($params, $secret_key);
                break;
            case "sell":
                $res = self::sell($params, $secret_key);
                break;
            default:
                break;
        }
        return $res;
    }
    
    //市价买单
    private static function buyMarket($params, $secret_key)
    {
        //市价买单，去掉amount
        if (isset($params['amount'])) {
            unset($params['amount']);
        }
        return OkexSingClass::sign($params, $secret_key);
    }
    
    //市价卖单
    private static function sellMarket($params, $secret_key)
    {
        //市价卖单，去掉price
        if (isset($params['price'])) {
            unset($params['price']);
        }
        return OkexSingClass::sign($params, $secret_key);
    }
    
    //限价买单
    private static function buy($params, $secret_key)
    {
        return OkexSingClass::sign($params, $secret_key);
    }
    
    //限价卖单
    private static function sell($params, $secret_key)
    {
        return OkexSingClass::sign($params, $secret_key);
        
    }
    
}