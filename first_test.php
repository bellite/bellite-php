<?php

    $host = "192.168.1.2";
    $port = 8080;
    $token  = '';

    $id = 0;


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


    $cred = $_ENV['BELLITE_SERVER'];
    $host = substr($cred,0,strpos($cred,':'));
    $port = substr($cred,strpos($cred,':') +1,strpos($cred,'/') - strpos($cred,':') -1);
    $token = substr($cred,strpos($cred,'/') +1);

    print_r(array( $host, $port, $token));

//    print_r($_ENV);


    jsonRpcRequest($host, $port, "ping", array("name" => "lalala"));
