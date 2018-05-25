<?php
/**
 * Created by PhpStorm.
 * User: haoshuaiwei
 * Date: 2018/4/13
 * Time: 下午8:54
 */
date_default_timezone_set("Asia/Shanghai");

$param_arr = getopt('p:');

if(!isset($param_arr['p']) || empty($param_arr['p'])){
    echo "机器人进程号错误";
    exit();
}

$res = swoole_process::kill($param_arr['p']);

if ($res){
    echo "已经停止该机器人";
}else{
    echo "停止该机器人失败，可能该机器人已经停止";
}