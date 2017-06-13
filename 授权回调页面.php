<?php
include_once "connect.php";
$uid=intval($_REQUEST['uid']);

$auth_code=$_REQUEST['auth_code'];

function get_authorization_info($appId,$component_access_token,$auth_code){
	$url = 'https://api.weixin.qq.com/cgi-bin/component/api_query_auth?component_access_token='.$component_access_token;
	$data = array(
		'component_appid' => $appId,
		'authorization_code' => $auth_code
	);
	$postdata = json_encode( $data );
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, FALSE );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, FALSE );
	$response = curl_exec( $ch );
	curl_close( $ch );
	$result = json_decode( $response, true );
	return $result['authorization_info'];
}

function get_authorizer_info($appId,$component_access_token,$authorizer_appid){
	$url = 'https://api.weixin.qq.com/cgi-bin/component/api_get_authorizer_info?component_access_token=' . $component_access_token;
	$data = array(
		'component_appid' => $appId,
		'authorizer_appid' => $authorizer_appid
		);
	$postdata = json_encode( $data );
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, FALSE );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, FALSE );
	$response = curl_exec( $ch );
	curl_close( $ch );
	$result = json_decode( $response, true );
	return $result['authorizer_info'];
}


$authorization_info=get_authorization_info($appId,$component_access_token,$auth_code);
$authorizer_appid=$authorization_info['authorizer_appid'];
$authorizer_info=get_authorizer_info($appId,$component_access_token,$authorizer_appid);
$access_token=$authorization_info['authorizer_access_token'];
$refresh_token=$authorization_info['authorizer_refresh_token'];
$client=$db->get_One('select * from `wx_open`.`wx_client` where uid="'.$uid.'" and appid="'.$authorizer_appid.'"');

if(!empty($client)){
	$db->query("update `wx_open`.`wx_client` set appid='$authorizer_appid',access_token='$access_token',refresh_token='$refresh_token' where uid='$uid' and appid='$authorizer_appid'");
}else{
	$db->query("insert into `wx_open`.`wx_client` (uid,appid,access_token,refresh_token) values ('$uid','$authorizer_appid','$access_token','$refresh_token')");	
}
$old=$db->get_One('select * from `web_ws`.`web_wx_user` where userid='.$uid.' and wx_id="'.$authorizer_info['user_name'].'"');
if(empty($old)){
	$infos=array(
		'wx_name'=>$authorizer_info['nick_name'],	
		'wx_id'=>$authorizer_info['user_name'],	
		'wx_face'=>$authorizer_info['qrcode_url'],	
		'userid'=>$uid,	
		'wx_no'=>$authorizer_info['alias'],	
		'addtime'=>TIME,	
		'appid'=>$authorizer_appid
	);
	$db->insert('web_ws.web_wx_user',$infos);
}
showmessage('授权成功！','http://web.7192.cn/');