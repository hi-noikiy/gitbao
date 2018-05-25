<?php
/**
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/4/14
 * Time: 上午9:46
 */

class OkexSingClass
{
    
    public static function sign($params, $apiKeySecret)
    {
        
        if (empty($params)) {
            return "签名参数不能为空";
        } else if (empty($apiKeySecret)) {
            return "下单秘钥不能为空";
        }
        
        ksort($params);
        $sign = "";
        while ($key = key($params)) {
            $sign .= $key . "=" . $params[$key] . "&";
            next($params);
        }
        $signUrl = $sign . "secret_key=" . $apiKeySecret;
        $sign = strtoupper(md5($signUrl));
        $signUrl .= "&sign=" . $sign;
        return $signUrl;
    }
}