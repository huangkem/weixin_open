<?php
/**
 *	微信公众平台PHP-SDK, 官方API部分
 *  @author  dodge <dodgepudding@gmail.com>
 *  @link https://github.com/dodgepudding/wechat-php-sdk
 *  @version 1.2
 *  usage:
 *   $options = array(
 *			'token'=>'tokenaccesskey', //填写你设定的key
 *			'appid'=>'wxdk1234567890', //填写高级调用功能的app id
 *			'appsecret'=>'xxxxxxxxxxxxxxxxxxx', //填写高级调用功能的密钥
 *		);
 *	 $weObj = new Wechat($options);
 *   $weObj->valid();
 *   $type = $weObj->getRev()->getRevType();
 *   switch($type) {
 *   		case Wechat::MSGTYPE_TEXT:
 *   			$weObj->text("hello, I'm wechat")->reply();
 *   			exit;
 *   			break;
 *   		case Wechat::MSGTYPE_EVENT:
 *   			....
 *   			break;
 *   		case Wechat::MSGTYPE_IMAGE:
 *   			...
 *   			break;
 *   		default:
 *   			$weObj->text("help info")->reply();
 *   }
 *   //获取菜单操作:
 *   $menu = $weObj->getMenu();
 *   //设置菜单
 *   $newmenu =  array(
 *   		"button"=>
 *   			array(
 *   				array('type'=>'click','name'=>'最新消息','key'=>'MENU_KEY_NEWS'),
 *   				array('type'=>'view','name'=>'我要搜索','url'=>'http://www.baidu.com'),
 *   				)
  *  		);
 *   $result = $weObj->createMenu($newmenu);
 */
include_once("wxBizMsgCrypt.php");
include_once("memcache.class.php");
class Wechat
{
	const MSGTYPE_TEXT = 'text';
	const MSGTYPE_IMAGE = 'image';
	const MSGTYPE_LOCATION = 'location';
	const MSGTYPE_LINK = 'link';
	const MSGTYPE_EVENT = 'event';
	const MSGTYPE_MUSIC = 'music';
	const MSGTYPE_NEWS = 'news';
	const MSGTYPE_VOICE = 'voice';
	const MSGTYPE_VIDEO = 'video';
	const API_URL_PREFIX = 'https://api.weixin.qq.com/cgi-bin';
	const AUTH_URL = '/token?grant_type=client_credential&';
	const BASE_URL = '/component/api_component_token';
	const OPEN_URL = '/component/api_authorizer_token?';
	const CUSTOM_SEND_URL='/message/custom/send?';
	const MENU_CREATE_URL = '/menu/create?';
	const MENU_GET_URL = '/menu/get?';
	const MENU_DELETE_URL = '/menu/delete?';
	const MEDIA_GET_URL = '/media/get?';
	const QRCODE_CREATE_URL='/qrcode/create?';
	const QR_SCENE = 0;
	const QR_LIMIT_SCENE = 1;
	const QRCODE_IMG_URL='https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=';
	const USER_GET_URL='/user/get?';
	const USER_INFO_URL='/user/info?';
	const GROUP_GET_URL='/groups/get?';
	const GROUP_CREATE_URL='/groups/create?';
	const GROUP_UPDATE_URL='/groups/update?';
	const GROUP_MEMBER_UPDATE_URL='/groups/members/update?';
	
	private $token;
	private $encodingAesKey;
	private $appid;
	private $appsecret;
	private $access_token;
	private $auth_access_token;
	private $_msg;
	private $_funcflag = false;
	private $_receive;
	public $debug =  false;
	public $errCode = 40001;
	public $errMsg = "no access";
	private $_logcallback;
	private $component_verify_ticket;
	
	public function __construct($options)
	{
		$this->token = isset($options['token'])?$options['token']:'';
		$this->encodingAesKey = isset($options['encodingAesKey'])?$options['encodingAesKey']:'';
		$this->appid = isset($options['appid'])?$options['appid']:'';
		$this->appsecret = isset($options['appsecret'])?$options['appsecret']:'';
		$this->debug = isset($options['debug'])?$options['debug']:false;
		$this->_logcallback = isset($options['logcallback'])?$options['logcallback']:false;
		$this->component_verify_ticket = isset($options['component_verify_ticket'])?$options['component_verify_ticket']:false;
	}
	public function set_diy_access_token($acc){
		$this->access_token=$acc;
	}
	/**
	 * 发送客服消息
	 * @param array $data 消息结构{"touser":"OPENID","msgtype":"news","news":{...}}
	 * @return boolean|array
	 */
	public function sendCustomMessage($data){
		if (!$this->access_token && !$this->checkAuth()) return false;
		$result = $this->http_post(self::API_URL_PREFIX.self::CUSTOM_SEND_URL.'access_token='.$this->access_token,self::json_encode($data));
		if ($result)
		{
			$json = json_decode($result,true);
			if (!$json || !empty($json['errcode'])) {
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				return false;
			}
			return $json;
		}
		return false;
	}
	/**
	 * For weixin server validation 
	 */	
	private function checkSignature()
	{
        $signature = $_GET["signature"];
        $timestamp = $_GET["timestamp"];
        $nonce = $_GET["nonce"];	
        		
		$token = $this->token;
		$tmpArr = array($token, $timestamp, $nonce);
		sort($tmpArr, SORT_STRING);
		$tmpStr = implode( $tmpArr );
		$tmpStr = sha1( $tmpStr );
		
		if( $tmpStr == $signature ){
			return true;
		}else{
			return false;
		}
	}
	public function sendMsg($url,$data){
		$result = $this->http_post($url,self::json_encode($data));
		$json = json_decode($result,true);
		return $json;
	}
	public function get_authorization_info($auth_code){
		if (!$this->access_token && !$this->checkBaseAuth()) return false;
		$data = array(
			'component_appid' => $this->appid,
			'authorization_code' => $auth_code
		);
		$_access_token=$this->access_token;
		$result = $this->http_post(self::API_URL_PREFIX.'/component/api_query_auth?component_access_token='.$_access_token,self::json_encode($data));
		$json = json_decode($result,true);
		return $json;
	}
	
	/**
	 * For weixin server validation 
	 * @param bool $return 是否返回
	 */
	public function valid($return=false)
    {
        $echoStr = isset($_GET["echostr"]) ? $_GET["echostr"]: '';
        if ($return) {
        		if ($echoStr) {
        			if ($this->checkSignature()) 
        				return $echoStr;
        			else
        				return false;
        		} else 
        			return $this->checkSignature();
        } else {
	        	if ($echoStr) {
	        		if ($this->checkSignature())
	        			die($echoStr);
	        		else 
	        			die('no access');
	        	}  else {
	        		if ($this->checkSignature())
	        			return true;
	        		else
	        			die('no access');
	        	}
        }
        return false;
    }
    
	/**
	 * 设置发送消息
	 * @param array $msg 消息数组
	 * @param bool $append 是否在原消息数组追加
	 */
    public function Message($msg = '',$append = false){
    		if (is_null($msg)) {
    			$this->_msg =array();
    		}elseif (is_array($msg)) {
    			if ($append)
    				$this->_msg = array_merge($this->_msg,$msg);
    			else
    				$this->_msg = $msg;
    			return $this->_msg;
    		} else {
    			return $this->_msg;
    		}
    }
    
    public function setFuncFlag($flag) {
    		$this->_funcflag = $flag;
    		return $this;
    }
    
    private function log($log){
    		if ($this->debug && function_exists($this->_logcallback)) {
    			if (is_array($log)) $log = print_r($log,true);
    			return call_user_func($this->_logcallback,$log);
    		}
    }
    
    /**
     * 获取微信服务器发来的信息
     */
	public function getRev($aes=false)
	{
		$postStr = file_get_contents("php://input");
		$this->log($postStr);
		if (!empty($postStr)) {
			if($aes&&isset($_GET['encrypt_type'])&&$_GET['encrypt_type']=='aes'){
				$pc = new WXBizMsgCrypt($this->token,$this->encodingAesKey,$this->appid);
				$timeStamp=trim($_GET['timestamp']);
				$nonce=trim($_GET['nonce']);
				$msg_sign=trim($_GET['msg_signature']);
				$msg='';
				$errCode = $pc->decryptMsg($msg_sign, $timeStamp, $nonce, $postStr, $msg);
				if($errCode===0){
					$postStr=$msg;
				}
			}
			$this->_receive = (array)simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);
		}
		return $this;
	}
	
	/**
	 * 获取微信服务器发来的信息
	 */
	public function getRevData()
	{
		return $this->_receive;
	}
		
	/**
	 * 获取消息发送者
	 */
	public function getRevFrom() {
		if ($this->_receive)
			return $this->_receive['FromUserName'];
		else 
			return false;
	}
	
	/**
	 * 获取消息接受者
	 */
	public function getRevTo() {
		if ($this->_receive)
			return $this->_receive['ToUserName'];
		else 
			return false;
	}
	
	/**
	 * 获取接收消息的类型
	 */
	public function getRevType() {
		if (isset($this->_receive['MsgType']))
			return $this->_receive['MsgType'];
		else 
			return false;
	}
	
	/**
	 * 获取消息ID
	 */
	public function getRevID() {
		if (isset($this->_receive['MsgId']))
			return $this->_receive['MsgId'];
		else 
			return false;
	}
	
	/**
	 * 获取消息发送时间
	 */
	public function getRevCtime() {
		if (isset($this->_receive['CreateTime']))
			return $this->_receive['CreateTime'];
		else 
			return false;
	}
	
	/**
	 * 获取接收消息内容正文
	 */
	public function getRevContent(){
		if (isset($this->_receive['Content']))
			return $this->_receive['Content'];
		else if (isset($this->_receive['Recognition'])) //获取语音识别文字内容，需申请开通
			return $this->_receive['Recognition'];
		else
			return false;
	}
	
	/**
	 * 获取接收消息图片
	 */
	public function getRevPic(){
		if (isset($this->_receive['PicUrl']))
			return $this->_receive['PicUrl'];
		else 
			return false;
	}
	
	/**
	 * 获取接收消息链接
	 */
	public function getRevLink(){
		if (isset($this->_receive['Url'])){
			return array(
				'url'=>$this->_receive['Url'],
				'title'=>$this->_receive['Title'],
				'description'=>$this->_receive['Description']
			);
		} else 
			return false;
	}
	
	/**
	 * 获取接收地理位置
	 */
	public function getRevGeo(){
		if (isset($this->_receive['Location_X'])){
			return array(
				'x'=>$this->_receive['Location_X'],
				'y'=>$this->_receive['Location_Y'],
				'scale'=>$this->_receive['Scale'],
				'label'=>$this->_receive['Label']
			);
		} else if(isset($this->_receive['SendLocationInfo'])){
			return array(
				'x'=>$this->_receive['SendLocationInfo']['Location_X'],
				'y'=>$this->_receive['SendLocationInfo']['Location_Y'],
				'scale'=>$this->_receive['SendLocationInfo']['Scale'],
				'label'=>$this->_receive['SendLocationInfo']['Label'],
				'poiname'=>$this->_receive['SendLocationInfo']['Poiname']
			);
		} else 
			return false;
	}

	public function getGeoArea($geo){
		$url='http://apis.map.qq.com/ws/geocoder/v1/?location='.$geo['x'].','.$geo['y'].'&key=WUIBZ-TGG3S-6VQOE-6HOLH-KQHEF-W2BTG&get_poi=1';
		$this->http_get($url);
		$result = $this->http_get($url);
		if ($result){
			$json = json_decode($result,true);
			if($json['status']==0&&$json['message']=='query ok'){
				return $json['result']['address_component'];
			}else{
				return $json;
			}
		}else{
			return false;
		}
	}
	
	/**
	 * 获取接收事件推送
	 */
	public function getRevEvent(){
		if (isset($this->_receive['Event'])){
			return array(
				'event'=>$this->_receive['Event'],
				'key'=>$this->_receive['EventKey'],
			);
		} else 
			return false;
	}
	/**
	 * 获取ticket
	 */
	public function getRevTicket() {
		if ($this->_receive)
			return $this->_receive['Ticket'];
		else 
			return false;
	}
	
	/**
	 * 获取接收语言推送
	 */
	public function getRevVoice(){
		if (isset($this->_receive['MediaId'])){
			return array(
				'mediaid'=>$this->_receive['MediaId'],
				'format'=>$this->_receive['Format'],
			);
		} else 
			return false;
	}
	/**
	 * 获取接收图片推送
	 */
	public function getRevImage(){
		if (isset($this->_receive['MediaId'])){
			return array(
					'mediaid'=>$this->_receive['MediaId'],
					'picurl'=>$this->_receive['PicUrl']
			);
		} else
			return false;
	}
	
	/**
	 * 获取接收多张图片推送
	 */
	public function getRevMuitImage(){
		if (isset($this->_receive['Event'])){
			$data= array(
					'event'=>$this->_receive['Event'],
					'eventkey'=>$this->_receive['EventKey'],
					'sendpicsinfo'=>$this->_receive['SendPicsInfo'],
			);
			//$sendpicsinfo=$this->_receive['SendPicsInfo'];
			//$count=$sendpicsinfo['count'];
			//$data['']=array('count');
			return $data;
		} else
			return false;
	}
	
	/**
	 * 获取接收视频推送
	 */
	public function getRevVideo(){
		if (isset($this->_receive['MediaId'])){
			return array(
					'mediaid'=>$this->_receive['MediaId'],
					'thumbmediaid'=>$this->_receive['ThumbMediaId']
			);
		} else
			return false;
	}
	
	public static function xmlSafeStr($str)
	{   
		return '<![CDATA['.preg_replace("/[\\x00-\\x08\\x0b-\\x0c\\x0e-\\x1f]/",'',$str).']]>';   
	} 
	
	/**
	 * 数据XML编码
	 * @param mixed $data 数据
	 * @return string
	 */
	public static function data_to_xml($data) {
	    $xml = '';
	    foreach ($data as $key => $val) {
	        is_numeric($key) && $key = "item id=\"$key\"";
	        $xml    .=  "<$key>";
	        $xml    .=  ( is_array($val) || is_object($val)) ? self::data_to_xml($val)  : self::xmlSafeStr($val);
	        list($key, ) = explode(' ', $key);
	        $xml    .=  "</$key>";
	    }
	    return $xml;
	}	
	
	/**
	 * XML编码
	 * @param mixed $data 数据
	 * @param string $root 根节点名
	 * @param string $item 数字索引的子节点名
	 * @param string $attr 根节点属性
	 * @param string $id   数字索引子节点key转换的属性名
	 * @param string $encoding 数据编码
	 * @return string
	*/
	public function xml_encode($data, $root='xml', $item='item', $attr='', $id='id', $encoding='utf-8') {
	    if(is_array($attr)){
	        $_attr = array();
	        foreach ($attr as $key => $value) {
	            $_attr[] = "{$key}=\"{$value}\"";
	        }
	        $attr = implode(' ', $_attr);
	    }
	    $attr   = trim($attr);
	    $attr   = empty($attr) ? '' : " {$attr}";
	    $xml   = "<{$root}{$attr}>";
	    $xml   .= self::data_to_xml($data, $item, $id);
	    $xml   .= "</{$root}>";
	    return $xml;
	}
	
	/*
	*多客服转发消息
	*/
	public function dkf(){
		$msg = array(
			'ToUserName' => $this->getRevFrom(),
			'FromUserName'=>$this->getRevTo(),
			'MsgType'=>'transfer_customer_service',
			'CreateTime'=>time(),
		);
		$this->Message($msg);
		return $this;
	}
	
	/**
	 * 设置回复消息
	 * Examle: $obj->text('hello')->reply();
	 * @param string $text
	 */
	public function text($text='')
	{
		$FuncFlag = $this->_funcflag ? 1 : 0;
		$msg = array(
			'ToUserName' => $this->getRevFrom(),
			'FromUserName'=>$this->getRevTo(),
			'MsgType'=>self::MSGTYPE_TEXT,
			'Content'=>$text,
			'CreateTime'=>time(),
			'FuncFlag'=>$FuncFlag
		);
		$this->Message($msg);
		return $this;
	}
	
	/**
	 * 设置回复音乐
	 * @param string $title
	 * @param string $desc
	 * @param string $musicurl
	 * @param string $hgmusicurl
	 */
	public function music($title,$desc,$musicurl,$hgmusicurl='') {
		$FuncFlag = $this->_funcflag ? 1 : 0;
		$msg = array(
			'ToUserName' => $this->getRevFrom(),
			'FromUserName'=>$this->getRevTo(),
			'CreateTime'=>time(),
			'MsgType'=>self::MSGTYPE_MUSIC,
			'Music'=>array(
				'Title'=>$title,
				'Description'=>$desc,
				'MusicUrl'=>$musicurl,
				'HQMusicUrl'=>$hgmusicurl
			),
			'FuncFlag'=>$FuncFlag
		);
		$this->Message($msg);
		return $this;
	}
	
	/**
	 * 设置回复图文
	 * @param array $newsData 
	 * 数组结构:
	 *  array(
	 *  	[0]=>array(
	 *  		'Title'=>'msg title',
	 *  		'Description'=>'summary text',
	 *  		'PicUrl'=>'http://www.domain.com/1.jpg',
	 *  		'Url'=>'http://www.domain.com/1.html'
	 *  	),
	 *  	[1]=>....
	 *  )
	 */
	public function news($newsData=array())
	{
		$FuncFlag = $this->_funcflag ? 1 : 0;
		$count = count($newsData);
		
		$msg = array(
			'ToUserName' => $this->getRevFrom(),
			'FromUserName'=>$this->getRevTo(),
			'MsgType'=>self::MSGTYPE_NEWS,
			'CreateTime'=>time(),
			'ArticleCount'=>$count,
			'Articles'=>$newsData,
			'FuncFlag'=>$FuncFlag
		);
		$this->Message($msg);
		return $this;
	}
	
	/**
	 * 
	 * 回复微信服务器, 此函数支持链式操作
	 * @example $this->text('msg tips')->reply();
	 * @param string $msg 要发送的信息, 默认取$this->_msg
	 * @param bool $return 是否返回信息而不抛出到浏览器 默认:否
	 */
	public function reply($msg=array(),$return = false)
	{
		if (empty($msg)) 
			$msg = $this->_msg;
		$xmldata=  $this->xml_encode($msg);
		if(isset($_GET['encrypt_type'])&&$_GET['encrypt_type']=='aes'){
			$pc = new WXBizMsgCrypt($this->token,$this->encodingAesKey,$this->appid);
			$timeStamp=trim($_GET['timestamp']);
			$nonce=trim($_GET['nonce']);
			$encryptMsg='';
			$errCode = $pc->encryptMsg($xmldata, $timeStamp, $nonce, $encryptMsg);
			if($errCode===0){
				$xmldata=$encryptMsg;
			}
		}
		if ($return)
			return $xmldata;
		else
			echo $xmldata;
	}
	
	public function http_header($url){
		$oCurl = curl_init();
		if(stripos($url,"https://")!==FALSE){
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, FALSE);
		}
		curl_setopt($oCurl, CURLOPT_URL, $url);
		curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 0 );
		curl_setopt($oCurl, CURLOPT_HEADER, true);
		curl_setopt($oCurl, CURLOPT_NOBODY, 1 );
		$sContent = curl_exec($oCurl);
		$aStatus = curl_getinfo($oCurl);
		curl_close($oCurl);
		if(intval($aStatus["http_code"])==200){
			return true;
		}else{
			return false;
		}
	}

	/**
	 * GET 请求
	 * @param string $url
	 */
	private function http_get($url){
		$oCurl = curl_init();
		if(stripos($url,"https://")!==FALSE){
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, FALSE);
		}
		curl_setopt($oCurl, CURLOPT_URL, $url);
		curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1 );
		$sContent = curl_exec($oCurl);
		$aStatus = curl_getinfo($oCurl);
		curl_close($oCurl);
		if(intval($aStatus["http_code"])==200){
			return $sContent;
		}else{
			return false;
		}
	}
	
	/**
	 * POST 请求
	 * @param string $url
	 * @param array $param
	 * @return string content
	 */
	private function http_post($url,$param){
		$oCurl = curl_init();
		if(stripos($url,"https://")!==FALSE){
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, false);
		}
		if (is_string($param)) {
			$strPOST = $param;
		} else {
			$aPOST = array();
			foreach($param as $key=>$val){
				$aPOST[] = $key."=".urlencode($val);
			}
			$strPOST =  join("&", $aPOST);
		}
		curl_setopt($oCurl, CURLOPT_URL, $url);
		curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt($oCurl, CURLOPT_POST,true);
		curl_setopt($oCurl, CURLOPT_POSTFIELDS,$strPOST);
		$sContent = curl_exec($oCurl);
		$aStatus = curl_getinfo($oCurl);
		curl_close($oCurl);
		if(intval($aStatus["http_code"])==200){
			return $sContent;
		}else{
			return false;
		}
	}
	
	/**
	 * 通用auth验证方法，暂时仅用于菜单更新操作
	 * @param string $appid
	 * @param string $appsecret
	 */
	public function checkAuth($appid='',$appsecret='',$c='1'){
		if (!$appid || !$appsecret) {
			$appid = $this->appid;
			$appsecret = $this->appsecret;
		}
		$mc=new cache_memcache(array('host'=>'127.0.0.1','port'=>'12435'));
		$key='wx_access_token_'.$appid;
		$access_token=$mc->get($key);
		if($access_token){
			$this->access_token = $access_token;
			return $this->access_token;
		}else{
			$result = $this->http_get(self::API_URL_PREFIX.self::AUTH_URL.'appid='.$appid.'&secret='.$appsecret);
			if ($result){
				$json = json_decode($result,true);
				if (!$json || isset($json['errcode'])) {
					$this->errCode = $json['errcode'];
					$this->errMsg = $json['errmsg'];
					return false;
				}
				$this->access_token = $json['access_token'];
				$expire = 3600;//$json['expires_in'] ? intval($json['expires_in'])-100 : 3600;
				$mc->set($key,$this->access_token, $expire);
				return $this->access_token;
			}
		}
		return false;
	}
	public function checkBaseAuth($appid='',$appsecret='',$c='1'){
		if (!$appid || !$appsecret) {
			$appid = $this->appid;
			$appsecret = $this->appsecret;
		}
		$mc=new cache_memcache(array('host'=>'127.0.0.1','port'=>'12435'));
		$key='wx_access_token_'.$appid;
		$access_token=$mc->get($key);
		if($access_token){
			$this->access_token = $access_token;
			return $this->access_token;
		}else{
			$data=array(
				"component_appid"=>$appid,
				"component_appsecret"=>$appsecret, 
				"component_verify_ticket"=>$this->component_verify_ticket,
			);
			$result = $this->http_post(self::API_URL_PREFIX.self::BASE_URL,self::json_encode($data));
			if ($result){
				$json = json_decode($result,true);
				if (!$json || isset($json['errcode'])) {
					$this->errCode = $json['errcode'];
					$this->errMsg = $json['errmsg'];
					return false;
				}
				$this->access_token = $json['component_access_token'];
				$expire = 3600;//$json['expires_in'] ? intval($json['expires_in'])-100 : 3600;
				$mc->set($key,$this->access_token, $expire);
				return $this->access_token;
			}
		}
		return false;
	}
	public function checkOpenAuth($wx_auth){
		if (!$this->access_token && !$this->checkBaseAuth()) return false;
		$refresh_token=$wx_auth['refresh_token'];

		$mc=new cache_memcache(array('host'=>'127.0.0.1','port'=>'12435'));
		$key='wx_refresh_token_'.md5($refresh_token);
		$auth_access_token=$mc->get($key);
		if($auth_access_token){
			$this->auth_access_token = $auth_access_token;
			return $this->auth_access_token;
		}else{
			$data=array(
				"component_appid"=>$this->appid,
				"authorizer_appid"=>$wx_auth['appid'],
				"authorizer_refresh_token"=>$wx_auth['refresh_token'],
			);
			$result = $this->http_post(self::API_URL_PREFIX.self::OPEN_URL.'component_access_token='.$this->access_token,self::json_encode($data));
			if ($result){
				$json = json_decode($result,true);
				if (!$json || isset($json['errcode'])) {
					$this->errCode = $json['errcode'];
					$this->errMsg = $json['errmsg'];
					if($this->errCode=='40001'){
						$this->resetAuth($this->appid);
					}
					return false;
				}
				$this->auth_access_token = $json['authorizer_access_token'];
				$expire = 3600;//$json['expires_in'] ? intval($json['expires_in'])-100 : 3600;
				$mc->set($key,$this->auth_access_token, $expire);
				return $this->auth_access_token;
			}
		}
		return false;
	}

	public function resetOpenAuth($wx_auth){
		$refresh_token=$wx_auth['refresh_token'];

		$mc=new cache_memcache(array('host'=>'127.0.0.1','port'=>'12435'));
		$key='wx_refresh_token_'.md5($refresh_token);
		$auth_access_token=$mc->get($key);
		if($auth_access_token){
			$expire=-1;
			$mc->set($key,'', $expire);
		}
		$this->auth_access_token ='';
	}

	/**
	 * 删除验证数据
	 * @param string $appid
	 */
	public function resetAuth($appid=''){
		$mc=new cache_memcache(array('host'=>'127.0.0.1','port'=>'12435'));
		$key='wx_access_token_'.$appid;
		$access_token=$mc->get($key);
		if(!empty($access_token)){
			$expire=-1;
			$mc->set($key,'', $expire);
		}

		$this->access_token = '';
		//TODO: remove cache
		return true;
	}
		
	/**
	 * 微信api不支持中文转义的json结构
	 * @param array $arr
	 */
	static function json_encode($arr) {
		$parts = array ();
		$is_list = false;
		//Find out if the given array is a numerical array
		$keys = array_keys ( $arr );
		$max_length = count ( $arr ) - 1;
		if (($keys [0] === 0) && ($keys [$max_length] === $max_length )) { //See if the first key is 0 and last key is length - 1
			$is_list = true;
			for($i = 0; $i < count ( $keys ); $i ++) { //See if each key correspondes to its position
				if ($i != $keys [$i]) { //A key fails at position check.
					$is_list = false; //It is an associative array.
					break;
				}
			}
		}
		foreach ( $arr as $key => $value ) {
			if (is_array ( $value )) { //Custom handling for arrays
				if ($is_list)
					$parts [] = self::json_encode ( $value ); /* :RECURSION: */
				else
					$parts [] = '"' . $key . '":' . self::json_encode ( $value ); /* :RECURSION: */
			} else {
				$str = '';
				if (! $is_list)
					$str = '"' . $key . '":';
				//Custom handling for multiple data types
				if (is_numeric ( $value ) && $value<2000000000)
					$str .= $value; //Numbers
				elseif ($value === false)
				$str .= 'false'; //The booleans
				elseif ($value === true)
				$str .= 'true';
				else
					$str .= '"' . addslashes ( $value ) . '"'; //All other things
				// :TODO: Is there any more datatype we should be in the lookout for? (Object?)
				$parts [] = $str;
			}
		}
		$json = implode ( ',', $parts );
		if ($is_list)
			return '[' . $json . ']'; //Return numerical JSON
		return '{' . $json . '}'; //Return associative JSON
	}
	
	/**
	 * 创建菜单
	 * @param array $data 菜单数组数据
	 
		$arr=array (
			'button' => array (
				0 => array (
					'type' => 'click',
					'name' => '今日歌曲',
					'key' => 'MENU_KEY_MUSIC',
				),
				1 => array (
					'type' => 'view',
					'name' => '歌手简介',
					'url' => 'http://www.qq.com/',
				),
				2 => array (
					'name' => '菜单',
					'sub_button' => array (
						0 => array (
							'type' => 'click',
							'name' => 'hello word',
							'key' => 'MENU_KEY_MENU',
						),
						1 => array (
							'type' => 'click',
							'name' => '赞一下我们',
							'key' => 'MENU_KEY_GOOD',
						),
					),
				),
			),
		);
	 * example:
	 {
	 "button":[
	 {
	 "type":"click",
	 "name":"今日歌曲",
	 "key":"MENU_KEY_MUSIC"
	 },
	 {
	 "type":"view",
	 "name":"歌手简介",
	 "url":"http://www.qq.com/"
	 },
	 {
	 "name":"菜单",
	 "sub_button":[
	 {
	 "type":"click",
	 "name":"hello word",
	 "key":"MENU_KEY_MENU"
	 },
	 {
	 "type":"click",
	 "name":"赞一下我们",
	 "key":"MENU_KEY_GOOD"
	 }]
	 }]
	 }
	 */
	public function createMenu($data){
		if (!$this->access_token && !$this->checkAuth()) return false;
		$result = $this->http_post(self::API_URL_PREFIX.self::MENU_CREATE_URL.'access_token='.$this->access_token,self::json_encode($data));
		if ($result)
		{
			//exit($json['errcode'].'+!@');
			$json = json_decode($result,true);
			//return $json;
			if (!$json || $json['errcode']>0) {
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				return false;
			}
			return true;
		}
		return false;
	}
	public function createOpenMenu($data,$wx_auth){
		if (!$this->auth_access_token && !$this->checkOpenAuth($wx_auth)) return false;
		$result = $this->http_post(self::API_URL_PREFIX.self::MENU_CREATE_URL.'access_token='.$this->auth_access_token,self::json_encode($data));
		if ($result)
		{
			//exit($json['errcode'].'+!@');
			$json = json_decode($result,true);
			//return $json;
			if (!$json || $json['errcode']>0) {
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				if($this->errCode=='40001'){
					$this->resetOpenAuth($wx_auth);
				}
				return false;
			}
			return true;
		}
		return false;
	}
	
	/**
	 * 获取菜单
	 * @return array('menu'=>array(....s))
	 */
	public function getMenu(){
		if (!$this->access_token && !$this->checkAuth()) return false;
		$result = $this->http_get(self::API_URL_PREFIX.self::MENU_GET_URL.'access_token='.$this->access_token);
		if ($result)
		{
			$json = json_decode($result,true);
			if (!$json || isset($json['errcode'])) {
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				return false;
			}
			return $json;
		}
		return false;
	}
	
	/**
	 * 删除菜单
	 * @return boolean
	 */
	public function deleteMenu(){
		if (!$this->access_token && !$this->checkAuth()) return false;
		$result = $this->http_get(self::API_URL_PREFIX.self::MENU_DELETE_URL.'access_token='.$this->access_token);
		if ($result)
		{
			$json = json_decode($result,true);
			if (!$json || $json['errcode']>0) {
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				return false;
			}
			return true;
		}
		return false;
	}

	/**
	 * 根据媒体文件ID获取媒体文件
	 * @param string $media_id 媒体文件id
	 * @return raw data
	 */
	public function getMedia($media_id){
		if (!$this->access_token && !$this->checkAuth()) return false;
		$result = $this->http_get(self::API_URL_PREFIX.self::MEDIA_GET_URL.'access_token='.$this->access_token.'&media_id='.$media_id);
		if ($result)
		{
			$json = json_decode($result,true);
			if (isset($json['errcode'])) {
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				return false;
			}
			return $result;
		}
		return false;
	}	
	
	/**
	 * 创建二维码ticket
	 * @param int $scene_id 自定义追踪id
	 * @param int $type 0:临时二维码；1:永久二维码(此时expire参数无效)
	 * @param int $expire 临时二维码有效期，最大为1800秒
	 * @return array('ticket'=>'qrcode字串','expire_seconds'=>1800)
	 */
	public function getQRCode($scene_id,$type=0,$expire=1800){
		if (!$this->access_token && !$this->checkAuth()) return false;
		echo $this->access_token;
		$data = array(
			'action_name'=>$type?"QR_LIMIT_SCENE":"QR_SCENE",
			'expire_seconds'=>$expire,
			'action_info'=>array('scene'=>array('scene_id'=>$scene_id))
		);
		$result = $this->http_post(self::API_URL_PREFIX.self::QRCODE_CREATE_URL.'access_token='.$this->access_token,self::json_encode($data));
		if ($result)
		{
			$json = json_decode($result,true);
			if (!$json || $json['errcode']>0) {
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				return false;
			}
			return $json;
		}
		return false;
	}
	
	/**
	 * 获取二维码图片
	 * @param string $ticket 传入由getQRCode方法生成的ticket参数
	 * @return string url 返回http地址
	 */
	public function getQRUrl($ticket) {
		return self::QRCODE_IMG_URL.urlencode($ticket);
	}
	
	/**
	 * 批量获取关注用户列表
	 * @param unknown $next_openid
	 */
	public function getUserList($next_openid=''){
		if (!$this->access_token && !$this->checkAuth()) return false;
		$result = $this->http_get(self::API_URL_PREFIX.self::USER_GET_URL.'access_token='.$this->access_token.'&next_openid='.$next_openid);
		if ($result)
		{
			$json = json_decode($result,true);
			if (isset($json['errcode'])) {
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				return false;
			}
			return $result;
		}
		return false;
	}
	
	/**
	 * 获取关注者详细信息
	 * @param string $openid
	 * @return array
	 */
	public function getUserInfo($openid){
		if (!$this->access_token && !$this->checkAuth()) return false;
		$result = $this->http_get(self::API_URL_PREFIX.self::USER_INFO_URL.'access_token='.$this->access_token.'&openid='.$openid);
		if ($result)
		{
			$json = json_decode($result,true);
			if (isset($json['errcode'])) {
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				return false;
			}
			return $result;
		}
		return false;
	}
	
	/**
	 * 获取用户分组列表
	 * @return boolean|array
	 */
	public function getGroup(){
		if (!$this->access_token && !$this->checkAuth()) return false;
		$result = $this->http_get(self::API_URL_PREFIX.self::GROUP_GET_URL.'access_token='.$this->access_token);
		if ($result)
		{
			$json = json_decode($result,true);
			if (isset($json['errcode'])) {
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				return false;
			}
			return $result;
		}
		return false;
	}
	
	/**
	 * 新增自定分组
	 * @param string $name 分组名称
	 * @return boolean|array
	 */
	public function createGroup($name){
		if (!$this->access_token && !$this->checkAuth()) return false;
		$data = array(
				'group'=>array('name'=>$name)
		);
		$result = $this->http_post(self::API_URL_PREFIX.self::GROUP_CREATE_URL.'access_token='.$this->access_token,self::json_encode($data));
		if ($result)
		{
			$json = json_decode($result,true);
			if (!$json || $json['errcode']>0) {
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				return false;
			}
			return $json;
		}
		return false;
	}
	
	/**
	 * 更改分组名称
	 * @param int $groupid 分组id
	 * @param string $name 分组名称
	 * @return boolean|array
	 */
	public function updateGroup($groupid,$name){
		if (!$this->access_token && !$this->checkAuth()) return false;
		$data = array(
				'group'=>array('id'=>$groupid,'name'=>$name)
		);
		$result = $this->http_post(self::API_URL_PREFIX.self::GROUP_UPDATE_URL.'access_token='.$this->access_token,self::json_encode($data));
		if ($result)
		{
			$json = json_decode($result,true);
			if (!$json || $json['errcode']>0) {
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				return false;
			}
			return $json;
		}
		return false;
	}
	
	/**
	 * 移动用户分组
	 * @param int $groupid 分组id
	 * @param string $openid 用户openid
	 * @return boolean|array
	 */
	public function updateGroupMembers($groupid,$openid){
		if (!$this->access_token && !$this->checkAuth()) return false;
		$data = array(
				'openid'=>$openid,
				'to_groupid'=>$groupid
		);
		$result = $this->http_post(self::API_URL_PREFIX.self::GROUP_MEMBER_UPDATE_URL.'access_token='.$this->access_token,self::json_encode($data));
		if ($result)
		{
			$json = json_decode($result,true);
			if (!$json || $json['errcode']>0) {
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				return false;
			}
			return $json;
		}
		return false;
	}
	/*
	*原服务号微信认证逻辑判断流程
	*/
	public function wxcert(){
		$db=MySQL::getdb();
		$ticket=$this->getRevTicket();
		$infos=$db->get_one('select * from `qgyinglou`.`qy_wx_ticket` where `ticket`="'.$ticket.'"');
		if(empty($infos)){
			$this->text('二维码已经失效，请刷新页面后，重新扫描认证')->reply();
		}else{
			$userid=$infos['userid'];
			if($userid<1){
				$this->text('二维码已经失效，请刷新页面后，重新扫描认证')->reply();
				return true;
			}
			//$cert=$db->get_one('select * from `qgyinglou`.`qy_wx_cert` where `userid`='.$userid);
			//if(!empty($cert)){
			//	$this->text('当前账户已经认证过，请撤销认证后再重新扫描认证')->reply();
			//}else{
				$openid=$this->getRevFrom();
				$cert=$db->get_one('select * from `qgyinglou`.`qy_wx_cert` where `openid`="'.$openid.'"');
				if(empty($cert)){
					$cert_arr=array(
						'userid'=>$userid,
						'openid'=>$openid,
						'addtime'=>TIME
					);
					$db->insert('qgyinglou.qy_wx_cert',$cert_arr);
					$this->text('恭喜您，微信扫描验证成功，请点击页面提交按钮，保存认证信息！')->reply();
				}elseif($cert['userid']==$userid){
					$this->text('您的微信验证已经通过，不需要重复扫描验证！')->reply();
				}else{
					$this->text('当前微信已经验证过其他账户了。')->reply();
				}
			//}
		}
	}

	public function getms() {
		return $this->errMsg;
	}
	public function getco() {
		return $this->errCode;
	}
	/*
	*新订阅号微信认证逻辑判断流程
	*/
	public function qywxcert($uid=0){
		$uid=intval($uid);
		$db=MySQL::getdb();
		$ticket=$this->getRevTicket();
		$db->query('SET NAMES \'gbk\'');
		$infos=$db->get_one('select * from `qgyinglou`.`qy_member` where `uid`='.$uid);
		if(empty($infos)){
			$this->text('无效的会员认证号码，请检查后重新输入')->reply();
		}else{
			$userid=$infos['uid'];
			/*
			if($userid<1){
				$this->text('二维码已经失效，请刷新页面后，重新扫描认证')->reply();
				return true;
			}*/
			$openid=$this->getRevFrom();
			$cert=$db->get_one('select * from `qgyinglou`.`qy_wx_cert` where `userid`='.$userid);
			if(!empty($cert)){
				if($cert['openid']==$openid){
					$this->text('您的微信验证已经通过，不需要重复验证！')->reply();
				}else{
					$this->text('当前账户已经验证过了')->reply();
				}
			}else{
				$cert=$db->get_one('select * from `qgyinglou`.`qy_wx_cert` where `openid`="'.$openid.'"');
				if(empty($cert)){
					$cert_arr=array(
						'userid'=>$userid,
						'openid'=>$openid,
						'addtime'=>TIME
					);
					$db->insert('qgyinglou.qy_wx_cert',$cert_arr);
					$this->text('恭喜您，微信验证成功，请点击页面提交按钮，保存认证信息！')->reply();
				}elseif($cert['userid']==$userid){
					$this->text('您的微信验证已经通过，不需要重复验证！')->reply();
				}else{
					$this->text('当前微信已经验证过其他账户了。'.$openid)->reply();
				}
			}
		}
		$db->query('SET NAMES \'utf8\'');
	}
}