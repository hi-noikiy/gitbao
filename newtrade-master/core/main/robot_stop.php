<?php
/**
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/5/13
 * Time: 下午5:57
 */

include_once "../config/config.php";
include_once CORE_PATH . "/../db/mysql_class.php";
include_once CORE_PATH . "/../utils/log_utils_class.php";

class RobotStopClass {

    private $db = null;
    
    public function __construct()
    {
        $this->db = new MysqlClass(ConfigClass::$DB['HOST'],ConfigClass::$DB['USER'],ConfigClass::$DB['PASSWORD'],
            ConfigClass::$DB['DB_NAME'],ConfigClass::$DB['CHARSET']);
        $this->_run();
    }
    
    private function _run(){
        //获取所有在执行中的机器人，然后全部暂停
        $res = $this->db->query("select * from r_users_robot where state=1");
        foreach ($res as $k=>$v){
            $this->db->update('r_users_robot',[
                'state'=>2,
                'update_time'=>date('Y-m-d H:i:s'),
            ]," id={$v['id']}");
            $this->db->update('r_users_robot_process',[
                'robot_state'=>2,
                'update_time'=>date('Y-m-d H:i:s'),
            ]," robot_id={$v['id']} and user_id={$v['user_id']}");
            LogUtilsClass::WriteLogTable($this->db,$v['id'],$v['user_id'],"服务更新，所有在运行的任务停止","");
        }
        echo "停止所有任务，执行完成！\n";
    }
    
}
new RobotStopClass();