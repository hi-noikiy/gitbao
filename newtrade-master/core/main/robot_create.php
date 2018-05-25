<?php
/**
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/4/13
 * Time: 下午8:54
 */
date_default_timezone_set("Asia/Shanghai");

include_once "robot_core.php";


//监听待创建的机器人，并且进行创建
function DaemonFunc(swoole_process $process)
{
    
    $db = new MysqlClass(ConfigClass::$DB['HOST'], ConfigClass::$DB['USER'], ConfigClass::$DB['PASSWORD'],
        ConfigClass::$DB['DB_NAME'], ConfigClass::$DB['CHARSET']);
    
    while (1) {
        $robotRes = $db->query("select id from r_users_robot_process where robot_state=0");
        if (!empty($robotRes)) {
            foreach ($robotRes as $v) {
                //传入待创建的机器人唯一编号
                RobotCoreClass::createRobot($v['id']);
            }
        }
        swoole_process::wait(false);
        //每3秒检查一下，所有待创建的机器人
        sleep(1);
    }
}

$process = new \Swoole\Process('DaemonFunc', false, true);
$process->start();
