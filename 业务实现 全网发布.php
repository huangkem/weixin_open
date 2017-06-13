<?php
require('weChat.class.php');

$weObj = new Wechat(array(
	'token'=>'第三方平台的token',
	'appid'=>'第三方平台appid',
	'appsecret'=>'第三方平台secret',
	'encodingAesKey'=>'第三方平台解密key',
	'component_verify_ticket'=>'授权事件接收URL接收',
));
$weObj->getRev(true);

$type = $weObj->getRevType();

$this_appid=$_GET['appid'];
$fromUsername = $weObj->getRevFrom();
$toUsername = $weObj->getRevTo();
if($this_appid=='wx570bc396a51b8ff8'){
		
	switch($type) {
		case Wechat::MSGTYPE_EVENT:
			$event = $weObj->getRevEvent();
			$weObj->text($event['event'].'from_callback')->reply();
			break;
		case Wechat::MSGTYPE_TEXT:
			$keyword = $weObj->getRevContent();
			if(strpos($keyword,'QUERY_AUTH_CODE')!== false){
				$keyword=str_replace('QUERY_AUTH_CODE:','',$keyword);
				$tokenInfo = $weObj->get_authorization_info ( $keyword ); 
				$tokenInfo[]=$keyword;
				logData($tokenInfo);
				$param ['authorizerAccessToken'] = $tokenInfo ['authorization_info'] ['authorizer_access_token'];
				$weObj->set_diy_access_token($param ['authorizerAccessToken']);
				$data=array(
					'touser'=>$fromUsername,
					'msgtype'=>'text',
					'text'=>array(
						'content'=>$keyword.'_from_api',
					)
				);
				$weObj->sendCustomMessage($data);
			}else{
				$weObj->text($keyword.'_callback')->reply();
			}
			break;
	}
	exit;
}