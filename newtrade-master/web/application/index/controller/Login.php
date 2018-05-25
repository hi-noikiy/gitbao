<?php

namespace app\index\controller;

use app\index\model\Users;
use think\captcha\Captcha;
use think\Controller;

class Login extends Common
{
    public function initialize()
    {
        $this->assign("LoginUser", ['id' => '']);
    }
    
    public function index()
    {
        return $this->fetch("index");
    }
    
    public function index_login()
    {
        $this->_checkToken();
        $captcha = new Captcha();
        $captchaCheckRes = $captcha->check(input("post.vercode"));
        if (!$captchaCheckRes) {
            $this->__json(101, '验证码错误');
        }
        $u = input('post.mobile');
        $p = md5(input('post.pass'));
        $obj = new Users();
        $res = $obj->where(['username' => $u, 'pwd' => $p])->find();
        if (empty($res)) {
            $this->__json(101, '登录失败，请确认用户名和密码是否正确');
        }
        \session("LoginUser", $res);
        $this->__json(0, '登录成功');
    }
    
    public function reg()
    {
        return $this->fetch("reg");
    }
    
    public function reg_login()
    {
        $this->_checkToken();
        $data['username'] = input('post.mobile');
        $data['mobile'] = input('post.mobile');
        $data['pwd'] = input('post.pass');
        $repass = input('post.repass');
        if ($data['pwd'] != $repass) {
            $this->__json(101, '两次密码输入不一致');
        }
        
//        $checkMobileRes = $this->check_validate($data['mobile']);
//        if ($checkMobileRes == 1) {
//            $this->__json(101, '验证码错误');
//        } else if ($checkMobileRes == 2) {
//            $this->__json(101, '验证码已失效');
//        }
        $data['ip'] = $this->request->ip();
        $data['api_key'] = '';
        $data['api_secret'] = '';
        if(!empty(input('post.api_key'))){
            $data['api_key'] = $this->encrypt(input('post.api_key'));
        }
        if(!empty(input('post.api_secret'))){
            $data['api_secret'] = $this->encrypt(input('post.api_secret'));
        }
        
        $data['user_type'] = input('post.platform');
        $data['create_time'] = date("Y-m-d H:i:s");
        
        //直接绑定VIP
    
        $data['vip_span'] = 30;
        $data['vip_end_time'] = date('Y-m-d H:i:s',strtotime("+30 day"));
        $data['vip_level'] = 1;
        $data['state'] = 1;
        
        $data['pwd'] = md5($data['pwd']);
        $obj = new Users();
        $res = $obj->where(['username' => $data['username']])->find();
        if (!empty($res)) {
            $this->__json(101, '注册失败，用户已经存在');
        }
        $uid = $obj->save($data);
        if (!$uid) {
            $this->__json(101, '注册失败');
        }
        $res = $obj->where(['username' => $data['username']])->find();
        \session("LoginUser", $res);
        $this->__json(0, '注册成功');
    }
    
    public function captcha()
    {   
        $config =    [
            // 验证码字体大小
            'fontSize'    =>    30,    
            // 验证码位数
            'length'      =>    4,   
            // 关闭验证码杂点
            //'useNoise'    =>    false, 
        ];
        $captcha = new Captcha($config);
        //验证码限定为数字
        $captcha->codeSet = '0123456789';
        return $captcha->entry();
    }
    
    /**
     * 用户调用发送短信的接口
     */
    public function send_validate()
    {
        $this->_checkToken();
        $rndStr = $this->getRandomString();
        $mobileNumber = input("post.mobile");
        if (strlen($mobileNumber) != 11) {
            $this->__json(101, "手机号格式错误");
        }
        $mobileSign = "mobile_validate_" . $mobileNumber;
        if (isset($_SESSION[$mobileSign])) {
            $this->__json(101, "已经发送，请勿重复发送");
        }
        $res = $this->send_mobile_validate($mobileNumber, $rndStr);
        if ($res) {
            session($mobileSign, $rndStr);
            $this->__json(0, "发送成功");
        }
        $this->__json(101, "发送失败");
    }
    
    public function logout()
    {
        session(null);
        return $this->redirect("/");
    }
    
}
