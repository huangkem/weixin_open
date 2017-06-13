<?php
include_once "connect.php";
$uid=intval($_REQUEST['uid']);

function get_token($appid,$appsecret,$ticket){	
	$url = "https://api.weixin.qq.com/cgi-bin/component/api_component_token";
	$data = array(
		'component_appid' => $appid,
		'component_appsecret' => $appsecret,
		'component_verify_ticket' => $ticket
	);
	$data = json_encode( $data );
	$ch = curl_init(); //用curl发送数据给api
	curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, FALSE );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, FALSE );
	$response = curl_exec( $ch );
	curl_close( $ch );
	$result = json_decode($response,true);
	$component_access_token=$result['component_access_token'];
	return $component_access_token;
}


function get_code($appId,$component_access_token){
	$url = 'https://api.weixin.qq.com/cgi-bin/component/api_create_preauthcode?component_access_token=' . $component_access_token;
	$data = array(
		'component_appid' => $appId
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
	$code=$result['pre_auth_code'];
	return $code;
}

$component_access_token=get_token($appId,$appsecret,$ticket);
$code=get_code($appId,$component_access_token);
$db->query("update `wx_open`.`wx_account` set component_access_token='$component_access_token',pre_auth_code='$code' where id=2");
if(!empty($code)){	
	header('location:https://mp.weixin.qq.com/cgi-bin/componentloginpage?component_appid='.$appId.'&pre_auth_code=' . $code . '&redirect_uri=http://wx.7192.cn/'.$uid.'/auth');
}
?>
