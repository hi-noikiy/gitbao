<?php
include_once "../config/config.php";
include_once CORE_PATH . "/../db/mysql_class.php";

//监控机器人进程存活，通过okr_robot_process表中的robot_process_id监控
//如果机器人死掉，则同步关闭okr_robot机器人状态
function DaemonFunc(swoole_process $worker)
{
    
    $db = new MysqlClass(ConfigClass::$DB['HOST'], ConfigClass::$DB['USER'], ConfigClass::$DB['PASSWORD'],
        ConfigClass::$DB['DB_NAME'], ConfigClass::$DB['CHARSET']);
    
    while (1) {
        $robotRes = $db->query("select * from r_users_robot_process where robot_state=1");
        if (!empty($robotRes)) {
            foreach ($robotRes as $k => $v) {
                $robotState = \Swoole\Process::kill($v["robot_process_id"], 0);
                if (!$robotState) {
                    //更新机器人进程为关闭状态
                    $db->update("r_users_robot_process", ["robot_state" => 2, "update_time" => date("Y-m-d H:i:s")],
                        "id=" . $v["id"]);
                } else {
                    //更新机器检查存活时间
                    $db->update("r_users_robot_process", ["update_time" => date("Y-m-d H:i:s")],
                        "id=" . $v["id"]);
                }
            }
        }
        //每3秒检查一下，所有机器人的存活
        sleep(1);
    }
}

$process = new \Swoole\Process('DaemonFunc', false, false);
$process->start();

