<?php

/**
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/4/13
 * Time: 下午10:05
 */
include_once "../config/config.php";
include_once CORE_PATH . "/../db/mysql_class.php";
include_once CORE_PATH . "/../utils/curl_utils_class.php";
include_once CORE_PATH . "/../utils/aes_utils_class.php";
include_once CORE_PATH . "/../utils/log_utils_class.php";
include_once CORE_PATH . "/../okex/okex_sign_class.php";
include_once CORE_PATH . "/../okex/okex_buy_type_class.php";

class RobotCoreClass
{
    
    //创建机器人
    public static function createRobot($id)
    {
        
        $db = new MysqlClass(ConfigClass::$DB['HOST'], ConfigClass::$DB['USER'], ConfigClass::$DB['PASSWORD'],
            ConfigClass::$DB['DB_NAME'], ConfigClass::$DB['CHARSET']);
        
        $pro = new \Swoole\Process('RobotCoreClass::robotCallbackFunc', false, true);
        
        //根据$id 读取待创建的相关信息
        $robot_process = $db->query("select * from r_users_robot_process where id={$id}", "Row");
        //写入通道内，待实现方法读取
        $pro->write(json_encode($robot_process));
        $pid = $pro->start();
        
        //创建机器人进程，更新数据表
        $db->update("r_users_robot_process",
            [
                "robot_process_id" => $pid,
                "robot_state" => 1,
                "update_time" => date('Y-m-d H:i:s'),
            ]
            , "id=" . $id);
    }
    
    //机器人要执行的方法
    public static function robotCallbackFunc(swoole_process $process)
    {
        $queueJson = $process->read();
        $queueRes = json_decode($queueJson, true);
        
        $db = new MysqlClass(ConfigClass::$DB['HOST'], ConfigClass::$DB['USER'], ConfigClass::$DB['PASSWORD'],
            ConfigClass::$DB['DB_NAME'], ConfigClass::$DB['CHARSET']);
        //读取策略
        $configRule = $db->query("select * from r_robot_config where id=" . $queueRes['config_id'], 'Row');
        //读取用户信息
        $userRes = $db->query("select * from r_users where id=" . $queueRes['user_id'], 'Row');
        //解密用户key及secret
        $userKey = AesUtilsClass::decrypt($userRes['api_key']);
        $userSecret = AesUtilsClass::decrypt($userRes['api_secret']);
        //根据config_type 获取策略执行路径
        $policy = $db->query("select policy_name,policy_zh_name from r_policy where id={$configRule['config_type']}", 'Row');
        
        while (1) {
            
            //读取机器人信息
            $robotRes = $db->query("select * from r_users_robot where id=" . $queueRes['robot_id'], 'Row');
            
            //高级策略，根据config_type 值决定
            include_once "../okex/trade_policy/" . $policy['policy_name'] . ".php";
            $className = str_replace(" ", "", ucwords(str_replace("_", " ", $policy['policy_name'])));
            $runObject = new $className();
            $runObject->_db = $db;
            $runObject->_user = $userRes;
            $runObject->_config = $configRule;
            $runObject->_robot = $robotRes;
            $runObject->_user_key = $userKey;
            $runObject->_user_secret = $userSecret;
            //开始执行策略
            $runObject->run();
            
            sleep($configRule['sleep_time']);
        }
        
    }
    
}