<?php

    //workaround for socket_recv on windows
    //if (!defined('MSG_DONTWAIT')) { 
        //define('MSG_DONTWAIT', 0x40);
    //}

    /**
     * partial - clone of python partial function
     * 
     * @param callback $func 
     * @params ... $func - default params, passed to $func
     * @access public
     * @return callback
     */
    function partial($func)
    {
        if (func_num_args() < 1){
            return null;
        }

        $params = array_slice(func_get_args(),1);
        $func   = func_get_arg(0);

//closure
        return function() use ($params, $func)
        {
            $new_params = func_get_args();
            $merged_params = $params;
            foreach ( $new_params as $key => $value){
                $merged_params[$key] = $new_params[$key];
            }

            call_user_func($func, $merged_params);
        };
    }

    /**
     * _pop - python list pop clone
     * 
     * @param array $array 
     * @param mixed $key 
     * @param mixed $default_value 
     * @access public
     * @return mixed
     */
    function _pop(&$array, $key, $default_value=false)
    {
        if (array_key_exists($key, $array))
        {
            $result = $array[$key];
            unset($array[$key]);
            return $result;
        }
        if ($default_value === false){
            return;
        }
        return $default_value;
    }

    /**
     * _setdefault - python setdefault clone
     * 
     * @param array $array 
     * @param mixed $key 
     * @param mixed $default_value 
     * @access public
     * @return mixed
     */
    function _setdefault(&$array, $key, $default_value=false)
    {
        if (array_key_exists($key, $array))
        {
            return $array[$key];
        }
        else {
            if ($default_value !== false){
                $array[$key] = $default_value;
                return $default_value;
            }
        }
    }



    /**
     * asyncConnection - interface, which implements python asynccore-like API for socket_select
     * 
     */
    interface asyncConnection
    {
        /**
         * fileno - should return connection resource
         * 
         * @access public
         * @return resource
         */
        public function fileno();

        /**
         * readable - if true, socket is readable
         * 
         * @access public
         * @return boolean
         */
        public function readable();

        /**
         * handle_read_event - called when some data readable
         * 
         * @access public
         * @return void
         */
        public function handle_read_event();

        /**
         * writable - if true, socket is writable
         * 
         * @access public
         * @return boolean
         */
        public function writable();

        /**
         * exceptable - if true, socket supports out-of-band data
         * 
         * @access public
         * @return boolean
         */
        public function exceptable();
        
        /**
         * handle_write_event - called when all data sent and new data can be send
         * 
         * @access public
         * @return void
         */
        public function handle_write_event();

        /**
         * handle_expt_event - called when some out-of-band data ready
         * 
         * @access public
         * @return void
         */
        public function handle_expt_event();

        /**
         * handle_close - called when socket should be closed
         * 
         * @access public
         * @return void
         */
        public function handle_close();

        /**
         * handle_error - called when some error occurs
         * 
         * @access public
         * @return void
         */
        public function handle_error();
    }

    /**
     * async - class implements something like python asyncore
     * 
     */
    class async
    {
        /**
         *  $map - array of objects, implements asyncConnection
         */
        static protected $map = array();

        /**
         * check - checking sockets using socket_select
         * 
         * @param float $timeout (in seconds)
         * @param array $map - list of objects, implements asyncConnection. If omitted, internal $map used
         * @static
         * @access public
         * @return false, if all closed, number of changed sockets (in terms of socket_select) otherwise
         */
        static function check($timeout, $map=false)
        {
            if ($map === false){
                $map = self::$map;
            }
            $readable = array();
            $writable = array();
            $excepted = array();

            $changedCount = 0;
            foreach ($map as $obj){
                if ($obj->writable()){
                    if ($obj->fileno()){
                        $writable[] = $obj->fileno();
                    }
                }
                if ($obj->readable()){
                    if ($obj->fileno()){
                        $readable[] = $obj->fileno();
                    }
                }
                if ($obj->exceptable()){
                    if ($obj->fileno()){
                        $excepted[] = $obj->fileno();
                    }
                }
            }
            if (count($readable) == 0 && count($writable) == 0 && count($excepted) == 0){
                return false;
            }
            if ($timeout == NULL){
                $changedCount =  socket_select($readable, $writable, $excepted, NULL);
            }
            else {
                $changedCount =  socket_select($readable, $writable, $excepted, floor($timeout),($timeout - floor($timeout))*1000000);
            }

            if ($changedCount == 0){
                return 0;
            }


            foreach ($map as $obj){
                if ($obj->writable() && array_search($obj->fileno(), $writeable) !== false){
                    $obj->handle_write_event();
                }
                if ($obj->readable() && array_search($obj->fileno(), $readable) !== false){
                    $obj->handle_read_event();
                }
                if ($obj->exceptable() && array_search($obj->fileno(), $excepted) !== false){
                    $obj->handle_expt_event();
                }
            }
            return $changedCount;
        }

        /**
         * loop  - looping until all sockets closed
         * 
         * @param float $timeout socket_select timeout (passed to check)
         * @param array $map - list of objects, implements asyncConnection. If omitted, internal $map used
         * @static
         * @access public
         * @return void
         */
        static function loop($timeout, $map=false, $count=-1)
        {
            do {
                $res = self::check($timeout, $map) !== false;
                $count--;
            } while (!$res && ($count != 0));
            return $res;
        }
    }

    /**
     * NotImplementedException - stub exception class, clone of python
     * 
     * @uses BadMethodCallException
     */
    class NotImplementedException extends BadMethodCallException
    {}


    /**
     * BelliteJsonRpcApi - almost `abstract` class for extending
     * 
     */
    class BelliteJsonRpcApi 
    {
        /**
         * __construct 
         * 
         * @param string $cred - credentials: IP, port and token
         * @access public
         * @return void
         */
        public function __construct($cred = false)
        {
            $cred = $this->findCredentials($cred);
            if ($cred){
                $this->_connect($cred);
            }
        }

        /**
         * auth - authorize on json-rpc
         * 
         * @param string $token - auth token
         * @access public
         * @return Promise object
         */
        public function auth($token)
        {
            return $this->_invoke('auth',array($token));
        }

        /**
         * version - returns server version
         * 
         * @access public
         * @return Promise object
         */
        public function version()
        {
            return $this->_invoke('version');
        }

        /**
         * ping - pings server
         * 
         * @access public
         * @return Promise object
         */
        public function ping()
        {
            return $this->_invoke('ping');
        }

        /**
         * respondsTo 
         * 
         * @param int $selfId 
         * @param string $cmd 
         * @access public
         * @return Promise object
         */
        function respondsTo($selfId, $cmd)
        {
            if (!$selfId){
                $selfId = 0;
            }
            return $this->_invoke('respondsTo', array($selfId, $cmd));
        }

        /**
         * perform - call remote common-purpose methods
         * 
         * @param int $selfId 
         * @param method $cmd 
         * @param array $params 
         * @access public
         * @return Promise object
         */
        function perform($selfId, $cmd, $params=null)
        {
            if (!$selfId){
                $selfId = 0;
            }
            return $this->_invoke('perform', array($selfId, $cmd, $params));
        }

        /**
         * bindEvent - binds on some remote event
         * 
         * @param int $selfId 
         * @param string $evtType - event type
         * @param mixed $res  
         * @param array $ctx - context
         * @access public
         * @return Promise object
         */
        function bindEvent($selfId=0, $evtType='*', $res=-1, $ctx=null)
        {
            if (!$selfId){
                $selfId = 0;
            }
            return $this->_invoke('bindEvent', array($selfId, $evtType, $res, $ctx));
        }

        /**
         * unbindEvent - remove event binding
         * 
         * @param int $selfId 
         * @param string $evtType 
         * @access public
         * @return Promise object
         */
        function unbindEvent($selfId=0, $evtType=null)
        {
            if (!$selfId){
                $selfId = 0;
            }
            return $this->_invoke('unbindEvent', array($selfId, $evtType));
        }

        /**
         * findCredentials - finds credentials in $cred or environment variable BELLITE_SERVER or use default
         * 
         * @param mixed $cred 
         * @access public
         * @return array
         */
        function findCredentials($cred=null)
        {
            if (!$cred){
                $cred = getenv('BELLITE_SERVER');
                if (!$cred) {
                    $cred = '127.0.0.1:3099/bellite-demo-host';
                    fwrite(STDERR,'BELLITE_SERVER environment variable not found, using "' . $cred . '"');
                }
            }
            elseif (!is_string($cred)){
                return $cred;
            }
            $host = substr($cred,0,strpos($cred,':'));
            $port = substr($cred,strpos($cred,':') +1,strpos($cred,'/') - strpos($cred,':') -1);
            $port = (int)$port;
            $token = substr($cred,strpos($cred,'/') +1);
            if (!empty($host) && !empty($token) && !empty($port)){
                return array('credentials' => $cred,
                            'token' => $token,
                            'host' => $host,
                            'port' => $port);
            }
            return false;
        }

        /**
         * _connect - connects to remote server
         * 
         * @param array $cred 
         * @access protected
         * @return void
         */
        protected function _connect($cred)
        {
            throw new NotImplementedException();
        }

        /**
         * _invoke - performs json-rpc call to remote server
         * 
         * @param string $method 
         * @param array $params 
         * @access protected
         * @return void
         */
        protected function _invoke($method, $params = null)
        {
            throw new NotImplementedException();
        }
    }

    /**
     * BelliteJsonRpc - next level of class extending
     * 
     */
    class BelliteJsonRpc extends BelliteJsonRpcApi
    {
        /**
         * _resultMap - array of Future objects
         * 
         * @access protected
         */
        protected $_resultMap = array();

        /**
         * _evtTypeMap - array of events
         * @access protected
         */
        protected $_evtTypeMap = array();

        /**
         * _logging - boolean of logging
         * 
         * @access protected
         */
        protected $_logging    = false;

        /**
         * _nextMsgId - autoincrement of json-rpc id field
         * 
         * @var float
         * @access protected
         */
        protected $_nextMsgId  = 100;


        /**
         * __construct - constructor
         * 
         * @param string $cred 
         * @param boolean $logging 
         * @access public
         * @return void
         */
        public function __construct($cred=false, $logging=false)
        {
            parent::__construct($cred);
            $this->_logging = $logging;
        }

        /**
         * _notify - call json-rpc method skipping result
         * 
         * @param string $method 
         * @param array $params 
         * @access protected
         * @return false if socket closed
         */
        protected function _notify ($method, $params = array())
        {
            return $this->_sendJsonRpc($method, $params);
        }

        /**
         * _invoke - call json-rpc method and return Promise with answer
         * 
         * @param string $method 
         * @param array $params 
         * @access protected
         * @return void
         */
        protected function _invoke($method, $params = null)
        {
            $msgId = $this->_nextMsgId;
            $this->_nextMsgId = $msgId +1;
            $res   = $this->_newResult($msgId);
            $this->_sendJsonRpc($method, $params, $msgId);
            return $res->promise; // var, not func
        }

        /**
         * _newResult - creating Future object, attached to $msgId and put it into $_resultMap
         * 
         * @param int $msgId 
         * @access protected
         * @return Future object
         */
        protected function _newResult($msgId)
        {
            $res = deferred();
            $this->_resultMap[$msgId] = $res;
            return $res;
        }

        /**
         * _sendJsonRpc - sends json-rpc call to remote server
         * 
         * @param string $method 
         * @param array $params 
         * @param integer $msgId 
         * @access protected
         * @return false if connection closed
         */
        protected function _sendJsonRpc($method, $params=null, $msgId=null)
        {
            $msg = array("jsonrpc" => "2.0", "method" => $method);
            if ($params!==null){
                $msg['params'] = $params;
            }
            if ($msgId!==null){
                $msg['id'] = $msgId;
            }
            $this->logSend($msg);
            return $this->_sendMessage(json_encode($msg));
        }

        /**
         * _sendMessage - network json-rpc string send
         * 
         * @param string $msg 
         * @access protected
         * @return void
         */
        protected function _sendMessage($msg)
        {
            throw new NotImplementedException();
        }

        /**
         * logSend - debug output of sent string
         * 
         * @param string $msg 
         * @access public
         * @return void
         */
        public function logSend($msg)
        {
            if ($this->_logging){
                print("send ==> " . json_encode($msg)) . "\r\n";
            }
        }
        
        /**
         * logRecv - debug output of received string
         * 
         * @param string $msg 
         * @access public
         * @return void
         */
        public function logRecv($msg)
        {
            if ($this->_logging){
                print("recv <== " . json_encode($msg)) . "\r\n";
            }
        }

        /**
         * _recvJsonRpc - receives json-rpc responses
         * 
         * @param array of strings $msgList 
         * @access protected
         * @return void
         */
        protected function _recvJsonRpc($msgList)
        {
            foreach ($msgList as $msg){
                $msg = json_decode($msg, true);
                $isCall = array_key_exists('method',$msg);
                if (!$msg){
                    continue;
                }
                $this->logRecv($msg);
                if ($isCall){
                    $this->on_rpc_call($msg);
                }
                else {
                    $this->on_rpc_response($msg);
                }
            }
        }

        /**
         * on_rpc_call - called on rpc callback and run some event
         * 
         * @param string $msg 
         * @access public
         * @return void
         */
        public function on_rpc_call($msg)
        {
            if (array_key_exists("method", $msg) && $msg['method'] == 'event')
            {
                $args = $msg['params'];
                $this->emit($args['evtType'],$args);
            }
        }

        /**
         * on_rpc_response - called on rpc response
         * 
         * @param string $msg 
         * @access public
         * @return void
         */
        public function on_rpc_response($msg)
        {
            $tgt = _pop($this->_resultMap, $msg['id'], false);
            if (!$tgt){
                return;
            }
            if (array_key_exists('error', $msg)){
                $reject = $tgt->reject;
                call_user_func($reject,$msg['error']);
            }
            else {
                $resolve = $tgt->resolve;
                call_user_func($resolve,array_key_exists('result',$msg) ? $msg['result'] : false);
            }
        }

        /**
         * on_connect - perform authorization and runs on_auth_succeeded or on_auth_failed
         * 
         * @param array $cred 
         * @access public
         * @return void
         */
        public function on_connect($cred)
        {
            $promise = $this->auth($cred['token']);
            $then = $promise->then;
            call_user_func($then,array($this,'on_auth_succeeded'), array($this,'on_auth_failed'));
        }

        /**
         * on_auth_succeeded - called when on_connect authed successfully and emits auth and ready callbacks
         * 
         * @param mixed $msg 
         * @access public
         * @return void
         */
        public function on_auth_succeeded($msg)
        {
//            echo ("AUTH OK ON\r\n");
            $this->emit('auth',array(true, $msg));
            $this->emit('ready');
        }

        /**
         * on_auth_failed - called if on_connect failed to authorize, emits auth handler with error flag
         * 
         * @param mixed $msg 
         * @access public
         * @return void
         */
        public function on_auth_failed($msg)
        {
            $this->emit('auth', array(false, $msg));
        }


        #~ micro event implementation ~~~~~~~~~~~~~~~~~~~~~~~
        #

        /**
         * ready - adds user callback on ready event
         * 
         * @param function $fnReady 
         * @access public
         * @return function, passed as argument
         */
        public function ready($fnReady)
        {
            return $this->on('ready', $fnReady);
        }

        /**
         * on - binds callback event handler on some condition
         * 
         * @param string $key 
         * @param function $fn 
         * @access public
         * @return function
         */
        public function on($key, $fn=false)
        {
//closure with $this
            $evtMap = &$this->_evtTypeMap; //5.3 closure with $this workaround
            $bindEvent = function($fn) use (&$key, &$fn, &$evtMap)
            {
                _setdefault($evtMap, $key, array());
                $evtMap[$key][] = $fn;
                return $fn;
            };
            if ($fn === false){
                return $bindEvent;
            }
            else {
                return call_user_func($bindEvent,$fn);
            }
        }

        /**
         * emit - calls event handler(s) for event $key with $params
         * 
         * @param string $key 
         * @param array $params 
         * @access public
         * @return void
         */
        public function emit($key, $params = array())
        {
            if (array_key_exists($key, $this->_evtTypeMap)){
                foreach ($this->_evtTypeMap[$key] as $fn){
                    call_user_func($fn,$this, $params);
                }
            }
        }
    }

    /**
     * Bellite - class for Bellite access
     * 
     * @uses BelliteJsonRpc
     * @uses asyncConnection
     */
    class Bellite extends BelliteJsonRpc implements asyncConnection
    {
        public $timeout = 0.5;

        public $conn = false;
        protected $_buf = ''; 

        public function __construct($cred=false, $logging=false)
        {
            parent::__construct($cred,$logging);
        }

        /**
         * _connect - real socket connect to Bellite json-rpc server
         * 
         * @param array $cred 
         * @access protected
         * @return void
         */
        protected function _connect($cred)
        {
            $this->conn = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($this->conn && !socket_connect($this->conn, $cred['host'], $cred['port'])){
                $this->conn = false;
            }
            if ($this->conn && !socket_set_nonblock($this->conn)){
                $this->conn = false;
            }
            if ($this->conn){
                $this->on_connect($cred);
            }
        }

        /**
         * _sendMessage - sends message into socket
         * 
         * @param string $msg 
         * @access protected
         * @return void
         */
        protected function _sendMessage($msg)
        {
            if (!$this->isConnected()){
                return false;
            }
            socket_write($this->conn, $msg . "\0");
            return true;
        }

        /**
         * isConnected - returns is Bellite socket connected or not
         * 
         * @access public
         * @return boolean
         */
        public function isConnected()
        {
            return $this->conn !== false;
        }

        /**
         * close - closes socket
         * 
         * @access public
         * @return false if already closed
         */
        public function close()
        {
            if ($this->conn){
                socket_close($this->conn);
                $this->conn = false;
            }
            else {
                return false;
            }
        }


        /**
         * fileno - part of asyncConnection interface
         * 
         * @access public
         * @return void
         */
        public function fileno()
        {
            return $this->conn;
        }

        public function readable()
        {
            return true;
        }
        public function handle_read_event()
        {
            if (!$this->isConnected()){
                return false;
            }
            $buf = $this->_buf;
            $part = '';
            while (true){
                $part = @socket_read($this->conn,4096);
                if (!$part){
                    // test to see if error was SOCKET_EWOULDBLOCK and SOCKET_EAGAIN
                    if (!in_array(socket_last_error($this->conn), array(11,35,10035))) {
                        $this->close();
                    }
                    break;
                }
                $buf .= $part;
            }
            $buf = explode("\0",$buf); #??
            $this->_buf = array_pop($buf);
            $this->_recvJsonRpc($buf);
            return true;
        }

        public function writable()
        {
            return false;
        }

        public function exceptable()
        {
            return false;
        }
       
        public function handle_write_event()
        {

        }
        public function handle_expt_event()
        {
            $this->close();
        }
        public function handle_close()
        {
            $this->close();
        }
        public function handle_error()
        {
            //error
        }

        public function loop($timeout = false)
        {
            if ($timeout === false){
                $timeout = $this->timeout;
            }
            return async::loop($timeout, array($this), 1);
        }
    }


/*#~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
#~ Fate promise/future (micro) implementation
#~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
    # */
    #

    /**
     * PromiseApi - `abstract` promise class
     * 
     */
    class PromiseApi
    {
        /**
         * then - function,
         * 
         * @var function
         * @access public
         */
        public $then = false;

        public function always($fn)
        {
            return call_user_func($this->$then,$fn, $fn);
        }

        public function fail($failure)
        {
            return call_user_func($this->$then,false,$failure);
        }

        public function done($success)
        {
            return call_user_func($this->$then,$success, false);
        }
    }

    class Promise extends PromiseApi
    {
        public function __construct($then)
        {
            if ($then){
                $this->then = $then;
            }
        }

        public function promise()
        {
            return $this;
        }
    }

    class Future extends PromiseApi
    {
        public $promise;
        public $resolve = false;
        public $reject = false;

        static public $counter = 0;
        public $myCounter = 0;

        public function __construct($then, $resolve=false, $reject=false)
        {
            $this->myCounter = self::$counter;
            self::$counter++;

            $this->promise = new Promise($then);
            if ($resolve){
                $this->resolve = $resolve;
            }
            if ($reject){
                $this->reject = $reject;
            }
        }

        function then()
        {
            return $this->promise->then;
        }
    }

    function deferred()
    {
        $cb = array();
        $answer = false;
        $future = false;
        $reject = false;
        $resolve = false;
//closure
        $then=function($success=false, $failure=false) use (&$cb, &$answer, &$future)
        {
            $cb[] = array($success, $failure);
            if ($answer){
                call_user_func($answer);
            }
            return $future->promise; 
        };


//closure
        $resolve = function($result) use (&$cb, &$answer, &$future, &$resolve, &$reject)
        {
            while (count($cb) > 0){
                $pair    = array_pop($cb);
                $success = $pair[0];
                $failure = $pair[1];
                try {
                    if ($success){
                        $res = call_user_func($success, $result);
                        if ($res){
                            $result = $res;
                        }
                    }
                } catch (Exception $err){
                    if ($failure) {
                        $res = call_user_func($failure, $err);
                    }
                    if (!$res){
                        return call_user_func($reject, $err);
                    }
                    else {
                        return call_user_func($reject, $res);
                    }
                }
            }
            $answer = partial($resolve,$result);
        };

//closure
        $reject = function ($error) use (&$cb, &$answer, &$future, &$reject)
        {
            while (count($cb) > 0){
                $pair    = array_pop($cb);
                $failure = $pair[1];
                try {
                    if ($failure){
                        $res = call_user_func($failure,$error);
                        if ($res){
                            $error = $res;
                        }
                    }
                } catch (Exception $err){
                    $res = $err;
                    if (count($cb) == 0){
                        //some exphook
                    }
                }
            }
            $answer = partial($reject,$error);
        };

        $future = new Future($then, $resolve, $reject);
        return $future;
    }







    //$app = new Bellite();
    //$app->ready(function() use (&$app){
        //print ("READY");
        //$app->ping();
        //$app->version();
        //$app->perform(142, "echo", array("name" => array(false, true, 42, "value")));

        //$app->bindEvent(118, "*");
        //$app->unbindEvent(118, "*");

        //$app->on('testEvent', function($app, $eobj){
            //print "TEST EVENT\r\n";
            //print_r($eobj);
            //if (isset($eobj['evt'])){
                //$app->perform(0,$eobj['evt']);
            //}
            //else {
                //$app->close();
            //}
        //});

        //$app->bindEvent(0,"testEvent", 42, array('testCtx' => 'lalala'));
        //$app->perform(0,"testEvent");
    //});
    //$app->loop();

