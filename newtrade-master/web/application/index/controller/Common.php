<?php
/**
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/5/1
 * Time: 上午9:22
 */

namespace app\index\controller;

use Qcloud\Sms\SmsSingleSender;
use Qcloud\Sms\SmsVoiceVerifyCodeSender;
use think\Controller;

class Common extends Controller
{
    private $AES_KEY = 'okex_zp_j9n23,j234k2-+1212m234jj3';
    //交易币种
    protected $COIN_ARR = ['eos_usdt', 'btc_usdt', 'ltc_usdt', 'eth_usdt', 'okb_usdt'];
    //k线周期 1min 5min
    protected $WEEK_KLINE = [
        '5min' => '5分钟',
        '15min' => '15分钟',
        '30min' => '30分钟',
    ];
    protected $LoginUser = null;
    
    public function initialize()
    {
        $res = \session("LoginUser");
        if (empty($res)) {
            $this->redirect("/index/login/");
        }
        $this->LoginUser = $res;
        $this->assign("LoginUser", $res);
    }
    
    public function __json($code, $msg, $data = null, $count = 0)
    {
        echo json_encode([
            'code' => $code,
            'msg' => $msg,
            'error_msg' => $msg,
            'data' => $data,
            'count' => $count,
        ]);
        exit();
    }
    
    public function _checkToken()
    {
        $token = input('post.__token__');
        $localToken = session("__token__");
        if ($token != $localToken) {
            $this->__json(101, '登录失败，非正常操作');
        }
    }
    
    
    public function sign($params, $apiKeySecret)
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
    
    /**
     * [encrypt aes加密]
     * @param [type]     $input [要加密的数据]
     * @param [type]     $key [加密key]
     * @return [type]       [加密后的数据]
     */
    public function encrypt($input)
    {
        $data = openssl_encrypt($input, 'AES-128-ECB', $this->AES_KEY, OPENSSL_RAW_DATA);
        $data = strtoupper(bin2hex($data));
        return $data;
    }
    
    /**
     * [decrypt aes解密]
     * @param [type]     $sStr [要解密的数据]
     * @param [type]     $sKey [加密key]
     * @return [type]       [解密后的数据]
     */
    public function decrypt($sStr)
    {
        $decrypted = openssl_decrypt(hex2bin($sStr), 'AES-128-ECB', $this->AES_KEY, OPENSSL_RAW_DATA);
        return $decrypted;
    }
    
    /**
     *
     * CURL GET方式请求
     * @param string $url
     * @param Array $params
     */
    public function getHttpRes($url, $params = NULL)
    {
        $final_url = $url;
        if ($params != null) {
            $param = '?';
            foreach ($params as $key => $value) {
                $param .= $key . '=' . $value . '&';
            }
            $final_url .= $param;
        }
        $ch = curl_init($final_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        if (strpos($final_url, 'https') === 0) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
    
    /**
     *
     * CURL POST方式请求
     * @param string $url
     * @param Array $data
     * @param array $header 删除头部array("Expect:");
     */
    public function getHttpPostRes($data, $url, $header = array())
    {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url); //定义表单提交地址
        curl_setopt($ch, CURLOPT_POST, 1); //定义提交类型 1：POST ；0：GET
        curl_setopt($ch, CURLOPT_HEADER, 0); //定义是否显示状态头 1：显示 ； 0：不显示
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header); //定义请求类型
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); //定义是否直接输出返回流
        if (strpos($url, 'https') === 0) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data); //定义提交的数据
        $response = curl_exec($ch); //接收返回信息
        if (curl_errno($ch)) { //出错则显示错误信息
            return curl_error($ch);
        }
        curl_close($ch); //关闭curl链接
        return $response; //显示返回信息
    }
    
    /**
     * 发送短信验证码
     *
     * @param $mobile_number 手机号
     * @param $code 验证码
     * @return bool
     */
    public function send_mobile_validate($mobile_number, $code)
    {
        $content = "您的验证码：{$code}，有效期为10分钟，请您尽快使用！";
        try {
            $sender = new SmsSingleSender(1400093428, "c97bba395414844dddd235985d23cc47");
            $result = $sender->send(0, "86", $mobile_number,
                $content, "", "");
            $rsp = json_decode($result);
            if ($rsp['result'] == 0) {
                return true;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    
    /**
     * 校验验证码
     *
     * @param $mobileNumber 手机号
     * @return int 0成功 1验证失败 2验证码失效
     */
    public function check_validate($mobileNumber)
    {
        $sessionRndStr = session("mobile_validate_" . $mobileNumber);
        $inputRndStr = input("post.validate");
        if ($sessionRndStr == $inputRndStr) {
            //清空验证码
            session("mobile_validate_" . $mobileNumber, 1);
            return 0;
        }
        //如果3次输入失败，则清空，需要重新获取
        $err = "mobile_validate_err_" . $mobileNumber;
        if (isset($_SESSION[$err]) && $_SESSION[$err] <= 3) {
            session($err, $_SESSION[$err] + 1);
        } else {
            if (isset($_SESSION[$err]) && $_SESSION[$err] > 3) {
                //清空验证码 让用户重新发送
                session("mobile_validate_" . $mobileNumber, 1);
                return 2;
            } else {
                session($err, 1);
            }
        }
        return 1;
    }
    
    /**
     *
     * 获取随机字符串
     *
     * @param int $len
     * @param string $type 1数字 2字符 3数字+字符     默认1
     *
     * @return bool
     */
    public function getRandomString($len = 6, $type = '1')
    {
        if ($type == '1') {
            $str = '0123456789';
        } elseif ($type == '2') {
            $str = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxzy';
        } elseif ($type == '3') {
            $str = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxzy';
        }
        
        $n = $len;
        $len = strlen($str) - 1;
        $s = '';
        for ($i = 0; $i < $n; $i++) {
            $s .= $str [rand(0, $len)];
        }
        
        return $s;
    }
    
}