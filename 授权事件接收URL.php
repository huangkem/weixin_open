<?php 
include_once "connect.php";
include_once "wxBizMsgCrypt.php";
//第三方发送消息给公众平台
$timeStamp  = empty($_GET['timestamp'])     ? ""    : trim($_GET['timestamp']) ;
$nonce      = empty($_GET['nonce'])     ? ""    : trim($_GET['nonce']) ;
$msg_sign   = empty($_GET['msg_signature']) ? ""    : trim($_GET['msg_signature']) ;
$encryptMsg = file_get_contents('php://input');
$pc = new WXBizMsgCrypt($token, $encodingAesKey, $appId);

$xml_tree = new DOMDocument();
$xml_tree->loadXML($encryptMsg);
$array_e = $xml_tree->getElementsByTagName('Encrypt');
$encrypt = $array_e->item(0)->nodeValue;


$format = "<xml><ToUserName><![CDATA[toUser]]></ToUserName><Encrypt><![CDATA[%s]]></Encrypt></xml>";
$from_xml = sprintf($format, $encrypt);


// 第三方收到公众号平台发送的消息
$msg = '';
$errCode = $pc->decryptMsg($msg_sign, $timeStamp, $nonce, $from_xml, $msg);

if ($errCode == 0) {
    //print("解密后: " . $msg . "\n");
    $xml = new DOMDocument();
    $xml->loadXML($msg);
    $array_e = $xml->getElementsByTagName('ComponentVerifyTicket');
    $component_verify_ticket = $array_e->item(0)->nodeValue;
    // logResult('解密后的component_verify_ticket是：'.$component_verify_ticket);   
    if(!empty($component_verify_ticket)){
        $db->query("update `wx_open`.`wx_account` set component_verify_ticket='$component_verify_ticket' where id=2");
    }
    echo 'success';
} else {
    // logResult('解密后失败：'.$errCode);
    //$db->query("insert into `wx_open`.`wx_account` (errCode) VALUES ($errCode)");
	echo 'failed';
}