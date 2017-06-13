<?php
/*数据库链接部分*/
$db = MySQL::getdb();


$wx_account=$db->get_One('select * from `wx_open`.`wx_account` where id=2');
/*获取第三方平台的数据*/
$appId = $wx_account['appId'];
$encodingAesKey = $wx_account['encodingAesKey']; 
$ticket = $wx_account['component_verify_ticket'];
$appsecret = $wx_account['appsecret'];
$token = $wx_account['token'];
$component_access_token = $wx_account['component_access_token'];