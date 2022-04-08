#!/usr/bin/php
<?php

use MathParser\StdMathParser;
use MathParser\Interpreting\Evaluator;

require("vendor/autoload.php");

pcntl_async_signals(true);

$queue  = '/topic/stats.prod.*';
$id = uniqid("");


function sig_handler($sig) {
    switch($sig) {
        case SIGHUP:
          reload_config();
    }
}

$config = array();
$translate_table = array();
$table = array();

pcntl_signal(SIGINT,  "sig_handler");

file_put_contents("stomprrd.pid", getmypid());

function reload_config() {
  global $config, $translate_table, $table;

  $config = yaml_parse_file(
    'config.yml',
  );

  print_r($config);

  $translate_table = array();

  foreach($config AS $section) {
    foreach ($section['data'] as $key => $value) {
      $identifier = $value["id"];
      if (array_key_exists($identifier, $translate_table)) {
        $v = &$translate_table[$identifier];

        if (!array_key_exists("id", $v)) {
          array_push($v, $value);
        } // if
        else {
          $tmp = $translate_table[$identifier];
          $v = array($tmp, $value);
        } // else

        continue;
      } // if

      $translate_table[$identifier] = $value;
    } // for
  } // for

  print_r($translate_table);
} // reload_config

reload_config();

$stomp = NULL;

$stomp_connect_retry = 15;
$stomp_count = 0;

// read a frame
while(1) {
  $ok = connect_stomp("openaprs:61613", "stompstats", "stompstats", $stomp);
  if (!$ok) {
    $stomp_count++;
    sleepfor($stomp_connect_retry);
    continue;
  } // if

  try {
    $stomp->subscribe($queue, array('id' => $id, 'openstomp.prefetch' => 1024));
  } // try
  catch(StompException $ex) {
    sleepfor($stomp_connect_retry);
    continue;
  } // catch

  $ack = 0;
  $start_ts = time();
  $next = time() + 15;
  $next_ack = time() + 3;
  $stomp_count++;

  while(1) {
    if (!$stomp->hasFrame()) {
      echo "no work, sleeping for 2 seconds\n";
      sleep(2);
      continue;
    } // if

    try {
      $frame = $stomp->readFrame();
    } // try
    catch(StompException $ex) {
      // error disconnect time
      echo "*** ERROR: Could not read frame!\n";
      $stomp = NULL;
      break;
    } // catch

    if ($frame == NULL) {
      echo "*** ERROR: Caughtn null frame!\n";
      break;
    } // if

//    echo $frame->body . "\n";
    $json = json_decode($frame->body);
    add_data($json);

    if ($next_ack < time() || $ack % 512 == 0) {
      //      var_dump($frame);
      //echo $frame->headers['destination'] . " ";
      $subscription = $frame->headers['subscription'];
      $stomp->ack($frame, array('subscription' => $subscription, 'content-length' => 0));
      echo $ack . "\n";
      $next_ack = time() + 1;
    } // if
    $ack++;

    if ($next < time()) {
      $diff = time() - $start_ts;
      echo "a/s=".number_format($ack/$diff , 2)."\n";
      $next = time()+15;
    } // if
  } // while

  sleepfor($stomp_connect_retry);
} // while

// close connection
unset($stomp);

function sleepfor($seconds) {
  while($seconds > 0) {
    if ($seconds % 5 == 0) echo "Sleeping for $seconds\n";
    $seconds--;
    sleep(1);
  } // while
} // sleepfor

function connect_stomp($host, $login, $passcode, &$stomp) {
  echo "STOMP Connecting to: $host\n";
  try {
    $stomp = new Stomp($host, $login, $passcode);
    //$stomp = new Stomp('tcp://localhost:61613');
  } catch(StompException $e) {
    echo "STOMP Connection failed: " . $e->getMessage() . "\n";
    return false;
  } // catch

  echo "STOMP Connected to: $host\n";
  return true;
} // connect_stomp

function create_rrd($rrd_file, $label, &$json) {
  $type = strtoupper($json->graph_type);

  $creator = new RRDCreator($rrd_file, time() - 10, 30);

  echo "Graph label " . $label . ", type " . $type . "\n";

//  $creator->addDataSource("$label:$type:600:0:U");
  $creator->addDataSource("$label:GAUGE:600:0:U");
//  $creator->addArchive("RRA:AVERAGE:0.5:1:24");
//  $creator->addArchive("RRA:AVERAGE:0.5:6:10");

  $creator->addArchive("HWPREDICT:500:0.1:0.0035:288:2");
  $creator->addArchive("SEASONAL:288:0.1:1:smoothing-window=0.1");
  $creator->addArchive("DEVPREDICT:500:4");
  $creator->addArchive("DEVSEASONAL:288:0.1:1:smoothing-window=0.1");
  $creator->addArchive("FAILURES:500:6:9:4");
  $creator->addArchive("AVERAGE:0.5:1:500");
  $creator->addArchive("AVERAGE:0.5:1:600");
  $creator->addArchive("AVERAGE:0.5:3:260");
  $creator->addArchive("AVERAGE:0.5:6:700");
  $creator->addArchive("AVERAGE:0.5:1:9600");
  $creator->addArchive("AVERAGE:0.5:24:500");
  $creator->addArchive("AVERAGE:0.5:24:775");
  $creator->addArchive("AVERAGE:0.5:288:797");
  $creator->addArchive("MAX:0.5:1:500");
  $creator->addArchive("MAX:0.5:1:600");
  $creator->addArchive("MAX:0.5:3:260");
  $creator->addArchive("MAX:0.5:6:700");
  $creator->addArchive("MAX:0.5:24:500");
  $creator->addArchive("MAX:0.5:24:775");
  $creator->addArchive("MAX:0.5:288:797");

  $creator->save();
} // function create_rrd

function update_rrd($file, $label, $timestamp, $value) {
  $updater = new RRDUpdater($file);
  $updater->update(array($label => $value), $timestamp);
} // update_rrd

function graph_rrd($rrd_file, $label, $png_file, $work) {
  $ylabel = safe_key("ylabel", $work, "y axis");
  $title = safe_key("title", $work, $label);

  $graphObj = new RRDGraph($png_file);
  $graphObj->setOptions(
    array(
        "--start" => time()-28800,
        "--end" => time(),
        "--title" => $title,
        "--vertical-label" => $ylabel,
        "DEF:$label=$rrd_file:$label:AVERAGE",
//        "CDEF:$label=my$label,300,*",
        "COMMENT:\\n",
        "GPRINT:$label:LAST:current %6.3lf",
        "LINE1:$label#FF0000"
    )
  );
  $graphObj->save();
} // graph_rrd

function safe_key($key, $map, $def="") {
  return array_key_exists($key, $map) ? $map[$key] : $def;
} // safe_key

function add_data($json) {
  global $translate_table;

  $path = "/var/www/html";

  echo "Looking for " . $json->id . "\n";
  if ( !array_key_exists($json->id, $translate_table) ) return;
  $table = $translate_table[$json->id];

  $work = !array_key_exists("id", $table) ? $table : array($table);

  print_r($work);

  foreach ($work AS $obj) {
    $file = $obj["name"];

    print_r($obj);

    echo "Writing to " . $file . "\n";

//  $rrd_file = dirname(__FILE__) . "/rrd/$file.rrd";
//  $png_file = dirname(__FILE__) . "/png/$file.png";
    $rrd_file = $path . "/rrd/$file.rrd";
    $png_file = $path . "/png/$file.png";

//  $label = str_replace(" ", "", $json->label);
    $label = $file;

    if (!file_exists($rrd_file)) {
      // must create the rrd file
      create_rrd($rrd_file, $label, $json);
    } // if

    $value = $json->value;

    if (isset($obj["equation"])) {
      $equation = $obj["equation"];
      if (isset($obj["non-zero"]) && $value == 0.0) echo "Skipping can't use equation with zero value\n";
      else {
        $parser = new StdMathParser();
        // Generate an abstract syntax tree
        $AST = $parser->parse($equation);

        // Do something with the AST, e.g. evaluate the expression:
        $evaluator = new Evaluator();
        $evaluator->setVariables(['x' => $value]);

        $value = $AST->accept($evaluator);
      } // if
    } // if

    update_rrd($rrd_file, $label, $json->timestamp, $value);
    graph_rrd($rrd_file, $label, $png_file, $obj);
  } // foreach

} // add_data

