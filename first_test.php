<?php

    $host = "192.168.1.2";
    $port = 8080;
    $token  = '';

    $id = 0;

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
        public function writeable();
        public function exceptable();
        public function handle_write_event();
        public function handle_expt_event();
        public function handle_close();
        public function handle_error();
    }

    class async
    {
        static private var $map = array();

        static function loop($timeout, $map)
        {
            $readable = array();
            $writable = array();
            $excepted = array();

            $changedCount = 0;
            foreach ($map as $obj){
                if ($obj->writable()){
                    $writable[] = $obj->fileno();
                }
                if ($obj->readable()){
                    $readable[] = $obj->fileno();
                }
                if ($obj->exceptable()){
                    $excepted[] = $obj->fileno();
                }
            }
            if ($timeout == NULL){
                $changedCount =  socket_read($readable, $writable, $excepted, NULL);
            }
            else {
                $changedCount =  socket_read($readable, $writable, $excepted, floor($timeout),($timeout - floor($timeout))*1000000);
            }

            if ($changedCount == 0){
                return false;
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
        }
    }

    class NotImplementedException extends BadMethodCallException
    {}


    class BelliteJsonRpcApi 
    {
        function __construct($cred = false)
        {
            $cred = $this->findCredentials($cred);
            if ($cred){
                $this->_connect($cred);
            }
        }

        function auth($token)
        {
            return $this->_invoke('auth',array($token));
        }

        function version()
        {
            return $this->_invoke('version');
        }

        function ping()
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

        function perform($selfId, $cmd, $params=false)
        {
            if (!$selfId){
                $selfId = 0;
            }
            return $this->_invoke('perform', array($selfId, $cmd, $params));
        }

        function bindEvent($selfId=0, $evtType='*', $res=-1, $ctx=false)
        {
            if (!$selfId){
                $selfId = 0;
            }
            return $this->_invoke('bindEvent', array($selfId, $evtType, $res, $ctx));
        }

        function unbindEvent($selfId=0, $evtType=false)
        {
            if (!$selfId){
                $selfId = 0;
            }
            return $this->_invoke('perform', array($selfId, $evtType));
        }

        function findCredentials($cred=false)
        {
            if (!$cred){
                if (array_key_exists('BELLITE_SERVER', $_ENV)){
                    $cred = $_ENV['BELLITE_SERVER'];
                }
                else {
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

        private function _connect($cred)
        {
            throw new NotImplementedException();
        }

        private function _invoke($method, $params = array())
        {
            throw new NotImplementedException();
        }
    }

    class BelliteJsonRpc extends BelliteJsonRpcApi
    {
        private $_resultMap = array();
        private $_evtTypeMap = array();
        private $_logging    = false;
        private $_nextMsgId  = 100;

        function __construct($cred=false, $logging=false)
        {
            parent::__construct($cred);
            $this->_logging = $logging;
        }

        private function _notify ($method, $params)
        {
            return $this->_sendJsonRpc($method, $params)
        }

        private function _invoke($method, $params)
        {
            $msgId = $this->_nextMsgId;
            $this->_nextMsgId = $msgId +1;
            $res   = $this->_newResult($msgId);
            $this->_sendJsonRpc($method, $params, $msgId);
            return $res->promise();
        }

        private function _newResult($msgId)
        {
            $res = deferred();
            $this->_resultMap[$msgId] = $res;
            return $res;
        }

        private function _sendJsonRpc($method, $params=false, $msgId=false)
        {
            $msg = array("jsonrpc" => "2.0", "method" => $method, 'params' => $params);
            if ($msgId){
                $msg['id'] = $msgId;
            }
            $this->logSend($msg);
            return $this->_sendMessage(json_encode($msg));
        }

        private function _sendMessage($msg)
        {
            throw new NotImplementedException();
        }

        public function logSend($msg)
        {
            print("send ==> " . json_encode($msg));
        }
        
        public function logRecv($msg)
        {
            print("recv <== " . json_encode($msg));
        }

        private function _recvJsonRpc($msgList)
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
            $tgt = _pop($this->_resultMap, $msg['id'], false);
            if (!$tgt){
                return;
            }
            if (array_key_exists('error', $msg)){
                $tgt->reject($msg['error']);
            }
            else {
                $tgt->resolve(array_key_exists('result',$msg) ? $msg['result'] : false);
            }
        }

        public function on_connect($cred)
        {
            $promise = $this->auth($cred['token'])
            $promise->then($this->on_auth_succeeded, $this->on_auth_failed);
        }

        public function on_auth_succeeded($msg)
        {
            $this->emit('auth',true, $msg);
            $this->emit('ready');
        }

        public function on_auth_failed($msg)
        {
            $this->emit('auth', false, $msg);
        }


        #~ micro event implementation ~~~~~~~~~~~~~~~~~~~~~~~
        #

        public function ready($fnReady)
        {
            return $this->on('ready', $fnReady);
        }

        public function on($key, $fn=false)
        {
            function bindEvent($fn)
            {
                $val = _setdefault($this->_evtTypeMap, $key, array());
                $val[] = $fn;
                return $fn;
            }
            if ($fn === false){
                return bindEvent;
            }
            else {
                return bindEvent($fn);
            }
        }

        public function emit($key, $params)
        {
            if (array_key_exists($key, $this->_evtTypeMap)){
                foreach ($this->_evtTypeMap[$key] as $fn){
                    $fn($this, $params);
                }
            }
        }
    }

    class Bellite extends BelliteJsonRpc implements asyncConnection
    {
        $timeout_conn = 0.5;
        $timeout_send = 0.01;
        $timeout_recv = 0.000001;

        $conn = false;
        private var $_buf = ''; 

        public function _connect($cred)
        {
            $this->conn = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!socket_set_nonblock($socket)){
                $this->conn = false;
            }
            if ($this->conn && !socket_connect($socket, $cred['host'], $cred['port'])){
                $this->conn = false;
            }
            if ($this->conn){
                $this->on_connect($cred);
            }
        }

        private function _sendMessage($msg)
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
            if ($this->conn){
                socket_close($this->conn);
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
            if (!$this->isConnected()){
                return false;
            }
            $buf = $this->_buf;
            while (true){
                $part = socket_read($socket, 4096);
                if ($part === false){
                    $this->close();
                    break;
                }
                $_buf .= $part;
            }
            $_buf = explode('\0',$_buf); #??
            $this->_buf = array_pop($this->_buf);
            $this->_recvJsonRpc($_buf);
            return true;
        }
        public function writeable()
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
    }


/*#~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
#~ Fate promise/future (micro) implementation
#~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
    # */
    #

    class PromiseApi
    {
        public var $then = false;
        public function always($fn)
        {
            return $this->$then($fn, $fn)
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
        function __construct($then)
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
        public var $promise;
        public var $resolve = false;
        public var $reject = false;

        function __construct($then, $resolve=false, $reject=false)
        {
            $this->promise = new Promise($then);
            if ($resolve){
                $this->resolve = $resolve;
            }
            if ($reject){
                $this->reject = $reject;
            }
        }

        function __call($name, $args)
        {
            if ($name == 'resolve' && $this->resolve){
                $fn = $this->resolve;
                $fn($args[0]);
            }
            if ($name == 'reject' && $this->reject){
                $fn = $this->rejecct;
                $fn($args[0]);
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

        function then($success=false, $failure=false)
        {
            $cb[] = array($success, $failure);
            if ($answer){
                $answer()
            }
            return $this->promise //TODO: how it should works? func as object...
        }

        function resolve($result)
        {
            while (count($cb) > 0){
                $pair    = array_pop($cb);
                $success = $pair[0];
                $failure = $pair[1];
                try {
                    if ($success){
                        $res = $success($result);
                        if ($res){
                            $result = $res
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
            $answer = partial(resolve,$result);//wtf partial?
        }

        function reject($error)
        {
            while (count($cb) > 0){
                $pair    = array_pop($cb);
                $failure = $pair[1];
                try {
                    if ($failure){
                        $res = $failure($result);
                        if ($res){
                            $error = $res
                        }
                    }
                } catch (Exception $err){
                    $res = $err
                    if (count($cb) == 0){
                        //some exphook
                    }
                }
            }
            $answer = partial(resolve,$result);//wtf partial?
        }

        $this = Future(then, resolve, reject);
        return $this;
    }





    $cred = $_ENV['BELLITE_SERVER'];
    $host = substr($cred,0,strpos($cred,':'));
    $port = substr($cred,strpos($cred,':') +1,strpos($cred,'/') - strpos($cred,':') -1);
    $token = substr($cred,strpos($cred,'/') +1);

//    print_r(array( $host, $port, $token));

    $bel = new BelliteJsonRpc();
//    print_r ($api->findCredentials());

//    print_r($_ENV);


    jsonRpcRequest($host, $port, "ping", array("name" => "lalala"));
