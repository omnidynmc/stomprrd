#!/usr/bin/php
<?php

$queue  = '/topic/stats.prod.*';
$id = uniqid("");

$stomp = NULL;

$stomp_connect_retry = 15;
$stomp_count = 0;

// read a frame
while(1) {
  $ok = connect_stomp("127.0.0.1:61613", "feeds", "feeds", $stomp);
  if (!$ok) {
    $stomp_count++;
    sleepfor($stomp_connect_retry);
    continue;
  } // if

  try {
    $stomp->subscribe($queue, array('id' => $id, 'openstomp.prefetch' => 0));
  } // try
  catch(StompException $ex) {
    sleepfor($stomp_connect_retry);
    continue;
  } // catch

  $ack = 0;
  $stat_num_packets = 0;
  $stat_last_ts = time();
  $next = time() + 15;
  $next_ack = time() + 2;
  $last_frame = NULL;
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
    catch(Exception $ex) {
      // error disconnect time
      echo "STOMP Error " . $ex . "\n";
      $stomp = NULL;
      break;
    } // catch

    if ($last_frame &&
        ($next_ack < time() || $ack % 4096 == 0)) {
//            var_dump($last_frame);
      //echo $frame->headers['destination'] . " ";
      $subscription = $last_frame->headers['subscription'];
      $stomp->ack($last_frame, array('subscription' => $subscription, 'content-length' => 0));
      echo $ack . "\n";
      $next_ack = time() + 2;
    } // if

    echo $frame->body . "\n";
    $stat_num_packets++;
    $ack++;

    if ($next < time()) {
      $diff = time() - $stat_last_ts;
      echo "a/s=".number_format($stat_num_packets/$diff , 2)."\n";
      $stat_num_packets = 0;
      $stat_last_ts = time();
      $next = time()+15;
    } // if

    $last_frame = $frame;
  } // while

  unset($stomp);
  echo "STOMP Disconnected\n";
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
  } catch(Exception $e) {
    echo "STOMP Connection failed: " . $e->getMessage() . "\n";
    return false;
  } // catch

  echo "STOMP Connected to: $host\n";
  return true;
} // connect_stomp


?>

