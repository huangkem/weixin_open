<?php
class cache_memcache{
	private $memcache;

    function __construct($config){
		$this->memcache = new Memcache;
		$this->memcache->connect($config['host'], $config['port']);
    }

    function cache_memcache($config){
		$this->__construct($config);
    }

	function get($name){
        return $this->memcache->get($name);
    }

    function set($name, $value, $ttl = 0){
         return $this->memcache->set($name, $value, 0, $ttl);
    }

    function rm($name){
        return $this->memcache->delete($name);
    }

    function clear(){
        return $this->memcache->flush();
    }
}
