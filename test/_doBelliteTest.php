<?php
    require_once "../bellite.php";

    setlocale(LC_ALL,'');

    $app = new Bellite();
    $app->on("ready", function() use (&$app){
        $app->ping();
        $app->version();
        $app->perform(142, "echo", array("name" => array(false, true, 42, "value")));

        $app->bindEvent(118, "*");
        $app->unbindEvent(118, "*");

        $app->on('testEvent', function($app, $eobj){
            if (isset($eobj['evt'])){
                $app->perform(0,$eobj['evt']);
            }
            else {
                $app->close();
            }
        });

        $app->bindEvent(0,"testEvent", 42, array('testCtx' => 'lalala'));
        $app->perform(0,"testEvent");
    });

    while ($app->loop()) {}

