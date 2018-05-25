<?php
/**
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/5/1
 * Time: 上午9:43
 */

class LogUtilsClass
{
    public static $log_path = __DIR__ . "/../logs/";
    
    public static function Write($msg)
    {
        $fileName = self::$log_path . date('Ymd');
        if (!file_exists($fileName)) {
            $hd = fopen($fileName, "w+");
            fclose($hd);
        }
        file_put_contents($fileName, date('Y-m-d H:i:s') . '  ' . $msg . "\n", FILE_APPEND);
        return;
    }
    
    public static function WriteLogTable($db, $rid, $uid, $msg, $msg_req)
    {
        $res = $db->insert('r_users_robot_log', [
            'robot_id' => $rid,
            'user_id' => $uid,
            'log_content' => $msg,
            'log_response_content' => $msg_req,
            'create_time' => date('Y-m-d H:i:s')
        ]);
        return $res;
    }
}