<?php
/**
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/4/14
 * Time: 上午9:28
 */
include_once "../config/config.php";
class AesUtilsClass
{
    
    /**
     * [encrypt aes加密]
     * @param [type]     $input [要加密的数据]
     * @param [type]     $key [加密key]
     * @return [type]       [加密后的数据]
     */
    public static function encrypt($input)
    {
        $data = openssl_encrypt($input, 'AES-128-ECB', AES_KEY, OPENSSL_RAW_DATA);
        $data = strtoupper(bin2hex($data));
        return $data;
    }
    /**
     * [decrypt aes解密]
     * @param [type]     $sStr [要解密的数据]
     * @param [type]     $sKey [加密key]
     * @return [type]       [解密后的数据]
     */
    public static function decrypt($sStr)
    {
        $decrypted = openssl_decrypt(hex2bin($sStr), 'AES-128-ECB', AES_KEY, OPENSSL_RAW_DATA);
        return $decrypted;
    }
}