<?php
    require_once "../bellite.php";

    $app = new Bellite(false, true);
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
                print "CLOSE in TEST EVENT";
                $app->close();
            }
        });

        $app->bindEvent(0,"testEvent", 42, array('testCtx' => 'lalala'));
        $app->perform(0,"testEvent");
    });
    $app->loop();

