#!/usr/bin/php
<?php

$queue  = '/topic/stats.prod.*';
$id = uniqid("");

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
      $stomp = NULL;
      break;
    } // catch

//    echo $frame->body . "\n";
    print_r( json_decode($frame->body) );

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

  $creator->addDataSource("$label:$type:600:0:U");
//  $creator->addArchive("AVERAGE:0.5:1:24");
//  $creator->addArchive("AVERAGE:0.5:6:10");

  $creator->addArchive("HWPREDICT:500:0.1:0.0035:288:2");
  $creator->addArchive("SEASONAL:288:0.1:1:smoothing-window=0.1");
  $creator->addArchive("DEVPREDICT:500:4");
  $creator->addArchive("DEVSEASONAL:288:0.1:1:smoothing-window=0.1");
  $creator->addArchive("FAILURES:500:6:9:4");
  $creator->addArchive("AVERAGE:0.5:1:500");
  $creator->addArchive("AVERAGE:0.5:1:500");
  $creator->addArchive("AVERAGE:0.5:1:600");
  $creator->addArchive("AVERAGE:0.5:3:260");
  $creator->addArchive("AVERAGE:0.5:6:700");
  $creator->addArchive("AVERAGE:0.5:1:9600");
  $creator->addArchive("AVERAGE:0.5:24:500");
  $creator->addArchive("AVERAGE:0.5:24:775");
  $creator->addArchive("AVERAGE:0.5:288:797");
  $creator->addArchive("MAX:0.5:1:500");
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

function graph_rrd($rrd_file, $label, $png_file) {
  $graphObj = new RRDGraph($png_file);
  $graphObj->setOptions(
    array(
        "--start" => time()-28800,
        "--end" => time(),
        "--vertical-label" => "m/s",
        "DEF:myspeed=$rrd_file:$label:AVERAGE",
//        "CDEF:realspeed=myspeed,1000,*",
        "LINE1:myspeed#FF0000"
    )
  );
  $graphObj->save();
} // graph_rrd

function add_data($json) {
  $file = $json->id;

  $rrd_file = dirname(__FILE__) . "/rrd/$file.rrd";
  $png_file = dirname(__FILE__) . "/png/$file.png";

  $label = str_replace(" ", "", $json->label);

  if (!file_exists($rrd_file)) {
    // must create the rrd file
    create_rrd($rrd_file, $label, $json);
  } // if

  update_rrd($rrd_file, $label, $json->timestamp, $json->value);
  graph_rrd($rrd_file, $label, $png_file);
} // add_data

?>
