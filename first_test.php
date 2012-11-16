<?php

    $host = "192.168.1.2";
    $port = 8080;
    $token  = '';

    $id = 0;

    function partial($func)
    {
        if (func_num_args() < 1){
            return null;
        }

        $params = array_slice(func_get_args(),1);
        $func   = func_get_arg(0);

        //print_r(func_get_args());

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



    function jsonRpcRequest ($host, $port, $method, $params)
    {
        global $id;
        $id++;
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket){
            print "socket created successfully\r\n";
        }
        $isConnected = socket_connect($socket, $host, $port);
        if ($isConnected){
            print "socket connected successfully\r\n";
        }

        $requestString = json_encode(array("jsonrpc" => "2.0", "method" => $method, 'params' => $params, "id" => $id));
        $writeLen = socket_write($socket, $requestString . "\0");
        print "written: ".$requestString." len: ".$writeLen ."\r\n";
        $response = socket_read($socket, 1000000);
        print "READ: ". $response;
    }

//    print_r (json_decode($_ENV['BELLITE_SERVER']), true);
    //
    //
    //
    //
    //

    interface asyncConnection
    {
        public function fileno();
        public function readable();
        public function handle_read_event();
        public function writable();
        public function exceptable();
        public function handle_write_event();
        public function handle_expt_event();
        public function handle_close();
        public function handle_error();
    }

    class async
    {
        static protected $map = array();

        static function loop($timeout, $map)
        {
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
//                print ("SELECT " .floor($timeout) . " " . ($timeout - floor($timeout))*1000000 . "\r\n");
            }

            if ($changedCount == 0){
 //               print ("SELECT: NOTHING\r\n");
                return false;
            }
  //          print ("SELECT: SOMETHING " . $changedCount . "\r\n");


            foreach ($map as $obj){
                if ($obj->writable() && array_search($obj->fileno(), $writeable) !== false){
                    print ("handle write " . $obj->fileno() . "\r\n");
                    $obj->handle_write_event();
                }
                if ($obj->readable() && array_search($obj->fileno(), $readable) !== false){
//                    print ("handle read " . $obj->fileno() . "\r\n");
                    $obj->handle_read_event();
                }
                if ($obj->exceptable() && array_search($obj->fileno(), $excepted) !== false){
                    print ("handle write " . $obj->fileno() . "\r\n");
                    $obj->handle_expt_event();
                }
            }
        }
    }

    class NotImplementedException extends BadMethodCallException
    {}


    class BelliteJsonRpcApi 
    {
        public function __construct($cred = false)
        {
            $cred = $this->findCredentials($cred);
            if ($cred){
                $this->_connect($cred);
            }
        }

        public function auth($token)
        {
            return $this->_invoke('auth',array($token));
        }

        public function version()
        {
            return $this->_invoke('version');
        }

        public function ping()
        {
            return $this->_invoke('ping');
        }

        function respondsTo($selfId, $cmd)
        {
            if (!$selfId){
                $selfId = 0;
            }
            return $this->_invoke('respondsTo', array($selfId, $cmd));
        }

        function perform($selfId, $cmd, $params=null)
        {
            if (!$selfId){
                $selfId = 0;
            }
            return $this->_invoke('perform', array($selfId, $cmd, $params));
        }

        function bindEvent($selfId=0, $evtType='*', $res=-1, $ctx=null)
        {
            if (!$selfId){
                $selfId = 0;
            }
            return $this->_invoke('bindEvent', array($selfId, $evtType, $res, $ctx));
        }

        function unbindEvent($selfId=0, $evtType=null)
        {
            if (!$selfId){
                $selfId = 0;
            }
            return $this->_invoke('unbindEvent', array($selfId, $evtType));
        }

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

        protected function _connect($cred)
        {
            throw new NotImplementedException();
        }

        protected function _invoke($method, $params = array())
        {
            throw new NotImplementedException();
        }
    }

    class BelliteJsonRpc extends BelliteJsonRpcApi
    {
        protected $_resultMap = array();
        protected $_evtTypeMap = array();
        protected $_logging    = false;
        protected $_nextMsgId  = 100;

        public function __construct($cred=false, $logging=false)
        {
            parent::__construct($cred);
            $this->_logging = $logging;
        }

        protected function _notify ($method, $params)
        {
            return $this->_sendJsonRpc($method, $params);
        }

        protected function _invoke($method, $params = array())
        {
            $msgId = $this->_nextMsgId;
            $this->_nextMsgId = $msgId +1;
            $res   = $this->_newResult($msgId);
            $this->_sendJsonRpc($method, $params, $msgId);
            return $res->promise; // var, not func
        }

        protected function _newResult($msgId)
        {
            $res = deferred();
            echo "_newResutl: " . get_class($res) . "\r\n";
            $this->_resultMap[$msgId] = $res;
            return $res;
        }

        protected function _sendJsonRpc($method, $params=false, $msgId=false)
        {
            $msg = array("jsonrpc" => "2.0", "method" => $method, 'params' => $params);
            if ($msgId){
                $msg['id'] = $msgId;
            }
            $this->logSend($msg);
            return $this->_sendMessage(json_encode($msg));
        }

        protected function _sendMessage($msg)
        {
            throw new NotImplementedException();
        }

        public function logSend($msg)
        {
            print("send ==> " . json_encode($msg)) . "\r\n";
        }
        
        public function logRecv($msg)
        {
            print("recv <== " . json_encode($msg)) . "\r\n";
        }

        protected function _recvJsonRpc($msgList)
        {
//            print("_recvJsonRpc  :" . print_r($msgList,true));
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

        public function on_rpc_call($msg)
        {
            if (array_key_exists("method", $msg) && $msg['method'] == 'event')
            {
                $args = $msg['params'];
                $this->emit($args['evtType'],$args);
            }
        }

        public function on_rpc_response($msg)
        {
//            print("RPC RESPONSE " . print_r($this->_resultMap,true));
//            print("RPC RESPONSE MSG " . print_r($msg,true));
            $tgt = _pop($this->_resultMap, $msg['id'], false);
//            print("RPC RESPONSE TGT" . get_class($tgt));
            if (!$tgt){
                return;
            }
            if (array_key_exists('error', $msg)){
                print("RPC RESPONSE TGT REJECT" . $msg['error']);
                $reject = $tgt->reject;
                $reject($msg['error']);
            }
            else {
 //               print("RPC RESPONSE TGT RESOLVE" . print_r($msg['result'], true));
                $resolve = $tgt->resolve;
                $resolve(array_key_exists('result',$msg) ? $msg['result'] : false);
            }
        }

        public function on_connect($cred)
        {
            $promise = $this->auth($cred['token']);
            $then = $promise->then;
            $then(array($this,'on_auth_succeeded'), array($this,'on_auth_failed'));
//            echo '$promise type: ' . get_class($promise) . " ". print_r($promise, true) . "\r\n";
        }

        public function on_auth_succeeded($msg)
        {
            echo ("AUTH OK ON\r\n");
            $this->emit('auth',array(true, $msg));
            $this->emit('ready');
        }

        public function on_auth_failed($msg)
        {
            print ("AUTH FAILED");
            $this->emit('auth', array(false, $msg));
        }


        #~ micro event implementation ~~~~~~~~~~~~~~~~~~~~~~~
        #

        public function ready($fnReady)
        {
            return $this->on('ready', $fnReady);
        }

        public function on($key, $fn=false)
        {
            $bindEvent = function($fn) use (&$key, &$fn)
            {
                _setdefault($this->_evtTypeMap, $key, array());
                $this->_evtTypeMap[$key][] = $fn;
                return $fn;
            };
            if ($fn === false){
                return $bindEvent;
            }
            else {
                return $bindEvent($fn);
            }
        }

        public function emit($key, $params = array())
        {
            if (array_key_exists($key, $this->_evtTypeMap)){
//                print("evtmap for $key  " . print_r($this->_evtTypeMap[$key], true));
                foreach ($this->_evtTypeMap[$key] as $fn){
                    //print("FN emit: " . print_r($fn, true));
                    $fn($this, $params);
                }
            }
        }
    }

    class Bellite extends BelliteJsonRpc implements asyncConnection
    {
        public $timeout_conn = 0.5;
        public $timeout_send = 0.01;
        public $timeout_recv = 0.000001;

        public $conn = false;
        protected $_buf = ''; 

        public function __construct($cred=false, $logging=false)
        {
            parent::__construct($cred,$logging);
        }

        protected function _connect($cred)
        {
            $this->conn = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($this->conn && !socket_connect($this->conn, $cred['host'], $cred['port'])){
                $this->conn = false;
            }
            if (!socket_set_nonblock($this->conn)){
                $this->conn = false;
            }
            if ($this->conn){
                $this->on_connect($cred);
            }
        }

        protected function _sendMessage($msg)
        {
            if (!$this->isConnected()){
                return false;
            }
            socket_write($this->conn, $msg . "\0");
        }

        public function isConnected()
        {
            return $this->conn !== false;
        }

        public function close()
        {
            echo "CLOSE!";
            if ($this->conn){
                socket_close($this->conn);
                $this->conn = false;
            }
            else {
                return false;
            }
        }


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
//            print ("in: handle_read_event\r\n");
            if (!$this->isConnected()){
                return false;
            }
            //print ("in: handle_read_event: connected\r\n");
            $buf = $this->_buf;
            //print ("in: handle_read_event: buf '" . $buf . "'\r\n");
            $part = '';
            $pkt_count = 0;
            while (true){
                $len = @socket_recv($this->conn, $part, 4096, MSG_DONTWAIT);
                //print ("in: handle_read_event: part: '" . $buf . "' " . strlen($buf) . "\r\n");
                if (!$len){
                    if ($pkt_count===0 && socket_last_error($this->conn) != 11) {
                        $this->close();
                    }
                    break;
                }
                $buf .= $part;
                $pkt_count++;
            }
            $buf = explode("\0",$buf); #??
            //print ("in: handle_read_event: buf explode: " . print_r($buf,true) . "\r\n");
            $this->_buf = array_pop($buf);
            //print ("in: handle_read_event: this->buf : " . print_r($buf,true) . "\r\n");
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
            print ("EXPT len false");
            $this->close();
        }
        public function handle_close()
        {
            print ("HANDLE len false");
            $this->close();
        }
        public function handle_error()
        {
            //error
        }
    }


/*#~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
#~ Fate promise/future (micro) implementation
#~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
    # */
    #

    class PromiseApi
    {
        public $then = false;
        public function always($fn)
        {
            return $this->$then($fn, $fn);
        }

        public function fail($failure)
        {
            return $this->$then(false,$failure);
        }

        public function done($success)
        {
            return $this->$then($success, false);
        }
    }

    class Promise extends PromiseApi
    {
        public function __construct($then)
        {
            if ($then){
                $this->then = $then;
//                echo "PROMISE THEN";
                print_r($this->then);
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
            print("Future #" . $this->myCounter . "\r\n");
            self::$counter++;

            $this->promise = new Promise($then);
            if ($resolve){
                $this->resolve = $resolve;
            }
            if ($reject){
                $this->reject = $reject;
            }
        }

/*        function __call($name, $args)
        {
            if ($name == 'resolve' && $this->resolve){
                $fn = $this->resolve;
                $fn($args[0]);
            }
            if ($name == 'reject' && $this->reject){
                $fn = $this->rejecct;
                $fn($args[0]);
            }
        } */

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

        $then=function($success=false, $failure=false) use (&$cb, &$answer, &$future)
        {
            print_r('IN THEN for future #'. $future->myCounter);
            $cb[] = array($success, $failure);
            if ($answer){
                call_user_func($answer);
            }
            return $future->promise; //TODO: how it should works? func as object...
        };


        $resolve = function($result) use (&$cb, &$answer, &$future, &$resolve, &$reject)
        {
            print ("closure RESOLVE " .print_r($result, true));
            //print ("closure RESOLVE CB" .print_r($cb, true));
            //print ("closure RESOLVE this" .print_r($this, true));
            while (count($cb) > 0){
                $pair    = array_pop($cb);
                $success = $pair[0];
                $failure = $pair[1];
                try {
                    if ($success){
                        $res = $success($result);
                        if ($res){
                            $result = $res;
                        }
                    }
                } catch (Exception $err){
                    if ($failure) {
                        $res = $failure($err);
                    }
                    if (!$res){
                        return reject($err);
                    }
                    else {
                        return reject($res);
                    }
                }
            }
            $answer = partial($resolve,$result);//wtf partial?
        };

        $reject = function ($error) use (&$cb, &$answer, &$future, &$reject)
        {
            while (count($cb) > 0){
                $pair    = array_pop($cb);
                $failure = $pair[1];
                try {
                    if ($failure){
                        $res = $failure($error);
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
            $answer = partial($reject,$error);//wtf partial?
        };

        //$this = Future(then, resolve, reject); //
        //return $this;
        $future = new Future($then, $resolve, $reject);
//        print ("NEW FUTURE #" . $future->myCounter . " " . print_r($future, true));
        return $future;
    }





    $cred = getenv('BELLITE_SERVER');
    $host = substr($cred,0,strpos($cred,':'));
    $port = substr($cred,strpos($cred,':') +1,strpos($cred,'/') - strpos($cred,':') -1);
    $token = substr($cred,strpos($cred,'/') +1);

//    print_r(array( $host, $port, $token));

//    print ("!!!!" .print_r(explode("\0", "aaaa\0"),true));
    $app = new Bellite();
    $app->ready(function() use (&$app){
        print ("READY");
        $app->ping();
        $app->version();
        $app->perform(142, "echo", array("name" => array(false, true, 42, "value")));

        $app->bindEvent(118, "*");
        $app->unbindEvent(118, "*");

        $app->on('testEvent', function($app, $eobj){
            print "TEST EVENT\r\n";
            print_r($eobj);
            if (isset($eobj['evt'])){
                $app->perform(0,$eobj['evt']);
            }
            else {
                $app->close();
            }
        });

        $app->bindEvent(0,"testEvent", 42, array('testCtx' => true));
        $app->perform(0,"testEvent");
    });
    while (true){
        async::loop(false,array($app));
        async::loop(false,array($app));
        async::loop(false,array($app));
    }

//    print_r ($api->findCredentials());

//    print_r($_ENV);


//    jsonRpcRequest($host, $port, "ping", array("name" => "lalala"));
