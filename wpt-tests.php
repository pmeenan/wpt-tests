<?php
include('settings.inc');
require_once(__DIR__ . '/aws/aws-autoloader.php');

$location_pending = array();
$queue_lengths = array();
$max_queue_lengths = array();
foreach ($ec2_locations as $name => $loc) {
  $max_queue_lengths[$name] = $loc['count'] * $queue_multiplier;
}

if (function_exists('curl_init')) {
  $CURL_CONTEXT = curl_init();
  if ($CURL_CONTEXT !== false) {
    curl_setopt($CURL_CONTEXT, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($CURL_CONTEXT, CURLOPT_FAILONERROR, true);
    curl_setopt($CURL_CONTEXT, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($CURL_CONTEXT, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($CURL_CONTEXT, CURLOPT_DNS_CACHE_TIMEOUT, 30);
    curl_setopt($CURL_CONTEXT, CURLOPT_MAXREDIRS, 10);
    curl_setopt($CURL_CONTEXT, CURLOPT_TIMEOUT, 30);
  }
} else {
  echo "php curl module is required\n";
  exit;
}

// Loop through all of the URL lists and run the tests or check the status
$done = true;
RunTests();

function RunTests() {
  global $current_crawl;
  $status = LoadStatus();
  if ($status) {
    if (isset($status['ec2']['lastCheck']))
      $status['ec2']['lastCheck'] = 0;
    if ($status['done']) {
      TerminateEC2($status);
      WriteResults($status);
    } else {
      while (!$status['done']) {
        $started = microtime(true);
        echo "\n" . date("h:i:s") . " - Processing...\n";
        GetTestResults($status);
        SubmitTests($status);
        CheckDone($status);
        StartEC2($status);
        TerminateEC2($status);
        WriteResults($status);
        echo "\n" . date("h:i:s") . " - Saving progress...\n";
        SaveStatus($status);
        echo "\n" . date("h:i:s") . " - Waiting to check status...\n";
        // Shoot for polling every 5 minutes but wait at least 1 second
        $elapsed = microtime(true) - $started;
        $delay = max(min(300 - $elapsed, 300), 1);
        sleep($delay);
      }
    }
    echo str_pad("\r\nTesting Complete", 120);
  } else {
    echo "\rError loading data";
  }
  TerminateEC2($status);
  echo "\n";
}

function GetTestResults(&$status) {
  global $retry_count;
  global $queue_lengths;
  global $current_metrics_version;
  global $locations;
  $queue_lengths = array();
  $test_count = count($status['urls']);
  $test_index = 0;
  $checked = 0;
  // Check one location at a time so the "sequential pending" logic works
  $check_locations = array();
  foreach ($locations as $l)
    $check_locations[$l] = $l;
  foreach ($status['urls'] as $key => &$test) {
    $loc = $test['location'];
    // see if we need to re-collect the result mid-test
    if ($test['done'] && isset($test['result'])) {
      if (!isset($test['metrics_version']) || $test['metrics_version'] != $current_metrics_version) {
        $test['done'] = false;
        unset($test['result']);
      }
    }
    $test['metrics_version'] = $current_metrics_version;
    
    if (!isset($queue_lengths[$loc]))
      $queue_lengths[$loc] = 0;
    $test_index++;

    if (isset($test['id'])) {
      if ($test_index == 1 || $test_index == $test_count)
        echo str_pad("\rChecking ($test_index/$test_count) $loc: {$test['id']}", 120);
      if (isset($test['id']) && !isset($test['result']) && !$test['done']) {
        echo str_pad("\rChecking ($test_index/$test_count) $loc: {$test['id']}", 120);
        $result = GetTestResult($test['id']);
        if (isset($result)) {
          if ($result['ok']) {
            $checked++;
            $test['result'] = $result;
            $test['done'] = true;
          } elseif (isset($test['attempts']) && $test['attempts'] >= $retry_count) {
            $test['done'] = true;
          } else {
            unset($test['id']);
          }
          LogUrl($key, $test);
        } else {
          $queue_lengths[$loc]++;
        }
      }
    } elseif (!$test['done'] && isset($test['id']) && strlen($test['id'])) {
      $queue_lengths[$loc]++;
    }
  }
}

function GetTestResult($id) {
  global $server;
  global $metrics;
  $result = null;
  
  $result_url = "{$server}jsonResult.php?test=$id&noposition=1&average=0&standard=0&runs=0&requests=0&noarchive=1";
  $response = json_decode(http_fetch($result_url), true);
  if ($response && is_array($response) && isset($response['statusCode'])) {
    $status = intval($response['statusCode']);
    if ($status >= 200) {
      $ok = false;
      if (isset($response['data']['successfulFVRuns']) && $response['data']['successfulFVRuns'] > 0)
        $ok = true;
      $result = array('status' => $status, 'ok' => $ok);
      if (isset($response['data']['median']['firstView'])) {
        $result['metrics'] = array();
        foreach($metrics as $metric) {
          if (isset($response['data']['median']['firstView'][$metric])) {
            if (is_array($response['data']['median']['firstView'][$metric]))
              $value = count($response['data']['median']['firstView'][$metric]);
            else
              $value = $response['data']['median']['firstView'][$metric];
            $result['metrics'][$metric] = $value;
          }
        }
      }
    }
  } else {
    $result = array('ok' => false);
  }
  
  return $result;
}

function SubmitTests(&$status) {
  global $retry_count;
  global $queue_lengths;
  global $max_queue_lengths;
  $test_count = count($status['urls']);
  $test_index = 0;
  for ($attempt = 0; $attempt <= $retry_count; $attempt++) {
    foreach ($status['urls'] as $key => &$test) {
      if (!isset($test['attempts']) || $test['attempts'] == $attempt) {
        $test_index++;
        $loc = $test['location'];
        $parts = explode(':', $test['location']);
        $ec2loc = $parts[0];
        if ((!isset($test['id']) || !strlen($test['id'])) &&
            !$test['done'] &&
            $queue_lengths[$loc] < $max_queue_lengths[$ec2loc]) {
          $count = isset($test['attempts']) ? $test['attempts'] : 0;
          if ($count < $retry_count) {
            echo str_pad("\rSubmitting test ($test_index/$test_count)...", 120);
            if (isset($test['result']))
              unset($test['result']);
            $id = SubmitTest($test['url'], $test['location']);
            if (isset($id) && strlen($id)) {
              $test['id'] = $id;
              $queue_lengths[$loc]++;
            }
            $count++;
            $test['attempts'] = $count;
          } else {
            $test['done'] = true;
          }
          LogUrl($key, $test);
        }
      }
    }
  }
}

function SubmitTest($url, $test_location) {
  $id = null;
  
  global $server;
  global $api_key;
  global $test_options;
  $test_url = "{$server}runtest.php?f=json&k=$api_key&shard=0&location=$test_location&$test_options&url=" . urlencode($url);
  $response = json_decode(http_fetch($test_url), true);
  if ($response && is_array($response) && isset($response['data']['testId']))
    $id = $response['data']['testId'];

  return $id;
}

function CheckDone(&$status) {
  global $location_pending;
  $location_pending = array();
  echo str_pad("\n\rChecking if testing is done...", 120);
  $done = true;
  $test_count = count($status['urls']);
  $completed = 0;
  $pending = 0;
  foreach ($status['urls'] as &$test) {
    $parts = explode(':', $test['location']);
    $loc = $parts[0];
    if (!isset($location_pending[$loc]))
      $location_pending[$loc] = 0;
    if (isset($test['done']) && $test['done']) {
      $completed++;
    } else {
      $location_pending[$loc]++;
      $pending++;
      $done = false;
    }
  }
  echo str_pad("\rCompleted $completed of $test_count ($pending pending)...", 120);
  echo "\nPending by location:\n";
  foreach ($location_pending as $loc => $count) {
    echo "    $loc: $count\n";
  }
  if ($done)
    $status['done'] = true;
}

function CSVEscape($string) {
  if (strpos($string, ',') !== false)
    $string = str_replace('"', '""', $string);
    $string = "\"$string\"";
  return $string;
}

function WriteResults($status) {
  global $metrics;
  global $results_server;
  global $urls_file;
  global $current_crawl;
  global $url_column;
  global $country_column;
  global $locations;
  if ($status['done']) {
    echo str_pad("\rTesting Complete, writing results...\r\n", 120);
    $csv_in = "$urls_file.gz";
    $csv_out = "$current_crawl.csv.gz";
    if (is_file($csv_in) && !is_file($csv_out)) {
      $out = gzopen($csv_out, 'wb9');
      if ($out) {
        $missing_log = __DIR__ . '/missing.csv';
        if (is_file($missing_log))
          unlink($missing_log);
        error_log("Row,Original URL,Location,Tested URL\n", 3, $missing_log);
        $lines = gzfile($csv_in, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines && is_array($lines) && count($lines) > 1) {
          $line_count = count($lines);

          // Build the new header row
          $line = trim($lines[0]);
          foreach($metrics as $label => $metric)
            $line .= "\t\"$label\"";
          $line .= "\r\n";
          gzwrite($out, $line);
          
          // Build the new rows for each URL
          $found = 0;
          $total = 0;
          $null = 0;
          for ($row = 1; $row < $line_count; $row++) {
            $total++;
            $line = trim($lines[$row]);
            gzwrite($out, $line);
            $csv = str_getcsv($line, "\t");
            $original_url = isset($csv[$url_column]) ? trim($csv[$url_column]) : null;
            if ($original_url == 'NULL')
              $null++;
            $url = FixURL($original_url);
            $country = isset($csv[$country_column]) ? trim($csv[$country_column]) : null;
            $location = isset($locations[$country]) ? $locations[$country] : '';
            // find the matching test
            $key = hash('sha256', "$location - $url");
            if (isset($status['urls'][$key])) {
              $found++;
              foreach($metrics as $metric) {
                if ($metric == 'testID' && isset($status['urls'][$key]['id'])) {
                  gzwrite($out, "\t\"{$status['urls'][$key]['id']}\"");
                } elseif ($metric == 'resultURL' && isset($status['urls'][$key]['id'])) {
                  gzwrite($out, "\t\"{$results_server}result/{$status['urls'][$key]['id']}/\"");
                } elseif (isset($status['urls'][$key]['result']['metrics'][$metric])) {
                  gzwrite($out, "\t\"{$status['urls'][$key]['result']['metrics'][$metric]}\"");
                } else {
                  gzwrite($out, "\t");
                }
              }
            } else {
              if ($original_url != 'NULL')
                error_log("$row," . CSVEscape($original_url) . ",$location," . CSVEscape($url) . "\n", 3, $missing_log);
              foreach($metrics as $metric)
                gzwrite($out, "\t");
            }
            gzwrite($out, "\r\n");
          }
          echo str_pad("\rResults collected for $found of $total tests with $null NULL entries\n", 120);
        }
        gzclose($out);
      } else {
        echo str_pad("\rError opening $csv_out\n", 120);
      }
    }
  }
}

function LogUrl($key, $value) {
  global $current_crawl;
  $file = "$current_crawl.status";
  $entry = array('key' => $key, 'value' => $value);
  file_put_contents("$file.urls", json_encode($entry) . "\n", FILE_APPEND);
}

function SaveStatus($status) {
  global $current_crawl;
  $info = array();
  $file = "$current_crawl.status";
  foreach($status as $key => $value) {
    if ($key != 'urls')
      $info[$key] = $value;
  }
  file_put_contents("$file.info", json_encode($info));
  if (!is_file("$file.urls")) {
    foreach($status['urls'] as $key => $value) {
      LogUrl($key, $value);
    }
  }
}

function LoadStatus() {
  global $current_crawl;
  global $urls_file;
  $file = "$current_crawl.status";
  $status = null;
  if (is_file("$file.urls") && is_file("$file.info")) {
    // load the new status file (one entry per line for the urls file and just the high-level status)
    $status = json_decode(file_get_contents("$file.info"), true);
    if (isset($status) && is_array($status)) {
      $f = fopen("$file.urls", 'rb');
      if ($f) {
        $status['urls'] = array();
        while (($line = fgets($f)) !== false) {
          $entry = json_decode($line, true);
          if (isset($entry) && is_array($entry) && isset($entry['key']) && isset($entry['value'])) {
            $status['urls'][$entry['key']] = $entry['value'];
          }
        }
        fclose($f);
      }
    } else {
      $status = null;
    }
  } elseif (is_file($file)) {
    // load the old status file (JSON blob)
    $status = json_decode(file_get_contents($file), true);
    SaveStatus($status);
  }
  if (!$status || !is_array($status)) {
    // see if a list of URLs is available
    $urls = LoadUrls($urls_file);
    if ($urls && is_array($urls) && count($urls)) {
      $status = array('done'  => false, 'urls' => array());
      foreach($urls as $key => $url) {
        $url['attempts'] = 0;
        $url['done'] = false;
        $status['urls'][$key] = $url;
      }
      SaveStatus($status);
    } else {
      $status = null;
    }
  }
  return $status;
}

function FixURL($url) {
  $url = preg_replace('/{[^}]*}?/', '', trim($url));
  if (strlen($url) && strpos($url, '.') !== false) {
    if (strpos($url, '/') == -1)
      $url = $url . '/';
    if (substr($url, 0, 4) !== 'http')
      $url = 'http://' . $url;
  }
  return $url;
}

function LoadUrls($file) {
  $ok = true;
  $urls = array();
  global $locations, $url_column, $country_column;
  $unknown = array();
  $duplicate_count = 0;

  $gzfile = gzopen("$file.gz", 'r');
  if ($gzfile) {
    $count = 0;
    $first_row = true;
    while (!gzeof($gzfile)) {
      $line = gzgets($gzfile);
      if ($line) {
        $count++;
        if ($count % 1000 == 0)
          echo str_pad("\rLoading URLs: $count", 120);
        if ($first_row) {
          $first_row = false;
        } else {
          $entry = str_getcsv($line, "\t");
          if (isset($entry[$url_column]) && isset($entry[$country_column])) {
            $original_url = $entry[$url_column];
            if ($original_url != 'NULL') {
              $url = FixURL($original_url);
              $country = trim($entry[$country_column]);
              $location = null;
              if (isset($locations[$country])) {
                $location = $locations[$country];
              } else {
                $ok = false;
                if (!isset($unknown[$country]))
                  $unknown[$country] = $country;
              }
              if (isset($location) && strlen($url) && preg_match('/^(http(s)?:\/\/)?[\w\d\.\-]+/iu', $url)) {
                $key = hash('sha256', "$location - $url");
                if (!isset($urls[$key])) {
                  $urls[$key] = array('url' => $url, 'location' => $location);
                } else {
                  $duplicate_count++;
                }
              }
            }
          } else {
            echo str_pad("\rInvalid Row\n", 120);
          }
        }
      }
    }
    gzclose($gzfile);
    if (count($unknown)) {
      echo str_pad("\rUnknown Countries:\r\n", 120);
      foreach ($unknown as $country)
        echo "\r    $country\n";
    }
    $url_count = count($urls);
    echo str_pad("\rLoaded $url_count unique URLs of $count entries with $duplicate_count duplicates\n", 120);
  }
  if (!$ok)
    $urls = array();

  // sort them by location which will make the sequential checking faster
  //if (count($urls))
  //  ksort($urls);
    
  return $urls;
}
  
function http_fetch($url) {
  $ret = null;
  global $CURL_CONTEXT;
  if (isset($CURL_CONTEXT) && $CURL_CONTEXT !== false) {
    curl_setopt($CURL_CONTEXT, CURLOPT_URL, $url);
    $ret = curl_exec($CURL_CONTEXT);
  } else {
    $context = stream_context_create(array('http' => array('header'=>'Connection: close', 'timeout' => 600)));
    $ret = file_get_contents($url, false, $context);
  }
  return $ret;
}

function GetAgentCounts() {
  global $ec2_locations, $server;
  $counts = array();
  $testers = json_decode(http_fetch("{$server}getTesters.php?f=json&hidden=1"), true);
  foreach ($ec2_locations as $location => $locinfo) {
    if (isset($testers) && is_array($testers) && isset($testers['data'][$location]['testers'])) {
      $counts[$location] = 0;
      foreach($testers['data'][$location]['testers'] as $tester) {
        if (isset($tester['elapsed']) && $tester['elapsed'] < 60)
          $counts[$location]++;
      }
    }
  }
  
  return $counts;
}

function StartEC2(&$status) {
  global $ec2_locations;
  global $ec2_key;
  global $ec2_secret;
  global $location_pending;
  if (!isset($status['ec2'])) {
    $status['ec2'] = array('started' => array(), 'terminated' => array());
  }
  $testers = GetAgentCounts();
  foreach($ec2_locations as $location => $info) {
    if (!isset($status['ec2']['started'][$location]) && isset($location_pending[$location]) && $location_pending[$location] > 0) {
      $ok = true;
      $need = $info['count'];
      if (isset($testers[$location]))
        $need -= $testers[$location];
      if (isset($info['increment']))
        $need = min([$need, $info['increment']]);
      $need = max($need, 0);
      try {
        $ec2 = \Aws\Ec2\Ec2Client::factory(array('key' => $ec2_key, 'secret' => $ec2_secret, 'region' => $info['region']));
        $ec2_options = array (
          'InstanceCount' => $need,
          'SpotPrice' => $info['max_price'],
          'LaunchSpecification' => array(
            'ImageId' => $info['ami'],
            'InstanceType' => $info['size'],
            'UserData' => base64_encode($info['user_data'])
          )
        );
        $result = $ec2->requestSpotInstances($ec2_options);
      } catch (\Aws\Ec2\Exception\Ec2Exception $e) {
        $ok = false;
        $error = $e->getMessage();
        echo "\nStarting EC2 instances in $location: $error";
      } catch (Exception $e) {
        echo "\nError Starting EC2 instances in $location";
      }
      if ($ok) {
        echo "\nRequested $need EC2 instances for $location";
        $status['ec2']['started'][$location] = true;
        $status['ec2']['terminated'][$location] = false;
        $status['ec2']['lastCheck'] = time();
      }
    }
  }
  // Check hourly to make sure all of our instances are running
  if (!isset($status['ec2']['lastCheck']))
    $status['ec2']['lastCheck'] = 0;
  $elapsed = time() - $status['ec2']['lastCheck'];
  if ($elapsed >= 3600) {
    echo "\nChecking EC2 Instance counts:\n";
    $status['ec2']['lastCheck'] = time();
    foreach($ec2_locations as $location => $info) {
      if (isset($status['ec2']['started'][$location]) && isset($location_pending[$location]) && $location_pending[$location] > 0) {
        echo "  $location: ";
        try {
          $desired = $info['count'];
          $ec2 = \Aws\Ec2\Ec2Client::factory(array('key' => $ec2_key, 'secret' => $ec2_secret, 'region' => $info['region']));
          if (isset($testers[$location])) {
            $count = $testers[$location];
          } else {
            $response = $ec2->describeInstances();
            $count = 0;
            if (isset($response['Reservations'])) {
              foreach ($response['Reservations'] as $reservation) {
                foreach ($reservation['Instances'] as $instance ) {
                  if (isset($instance['ImageId']) && $instance['ImageId'] == $info['ami']) {
                    $count++;
                  }
                }
              }
            }
          }
          echo "$count/$desired ";
          if ($count < $desired) {
            $need = $desired - $count;
            if (isset($info['increment']))
              $need = min([$need, $info['increment']]);
            echo "adding $need ";
            $ec2_options = array (
              'InstanceCount' => $need,
              'SpotPrice' => $info['max_price'],
              'LaunchSpecification' => array(
                'ImageId' => $info['ami'],
                'InstanceType' => $info['size'],
                'UserData' => base64_encode($info['user_data'])
              )
            );
            $result = $ec2->requestSpotInstances($ec2_options);
            echo "OK";
            $status['ec2']['started'][$location] = true;
            $status['ec2']['terminated'][$location] = false;
          }
        } catch (\Aws\Ec2\Exception\Ec2Exception $e) {
          $error = $e->getMessage();
          echo "ERROR - $error";
        } catch (Exception $e) {
        }
        echo "\n";
      }
    }
  }
  echo "\n";
}

// Terminate EC2 instances for a given location if all of the testing is complete
function TerminateEC2(&$status) {
  global $location_pending;
  global $ec2_locations;
  global $ec2_key;
  global $ec2_secret;
  
  foreach($ec2_locations as $location => $info) {
    if ((!isset($location_pending[$location]) || !$location_pending[$location]) &&
        (!isset($status['ec2']['terminated'][$location]) || !$status['ec2']['terminated'][$location])) {
      $instances = array();
      $ok = true;
      $count = 0;
      $stopped = false;
      $attempts = 0;
      $ec2 = \Aws\Ec2\Ec2Client::factory(array('key' => $ec2_key, 'secret' => $ec2_secret, 'region' => $info['region']));
      
      // Get a list of all of the EC2 instances running in the region with the same ami ID
      $more = true;
      $next_token = null;
      while ($more && $attempts < 10) {
        $attempts++;
        try {
          echo "\nGetting instance list for $location...";
          $request = array('MaxResults' => 1000,
                           'Filters' => array(
                              array('Name' => 'image-id', 'Values' => array($info['ami'])),
                              array('Name' => 'instance-state-name', 'Values' => array('running'))));
          if (isset($next_token)) {
            $request['NextToken'] = $next_token;
          }
          $response = $ec2->describeInstances($request);
          if (isset($response['NextToken'])) {
            $next_token = $response['NextToken'];
          } else {
            $next_token = null;
            $more = false;
          }
          if (isset($response['Reservations'])) {
            foreach ($response['Reservations'] as $reservation) {
              foreach ($reservation['Instances'] as $instance ) {
                if (isset($instance['ImageId']) && $instance['ImageId'] == $info['ami']) {
                  $instances[] = $instance['InstanceId'];
                }
              }
            }
          }
        } catch (\Aws\Ec2\Exception\Ec2Exception $e) {
          $ok = false;
          $error = $e->getMessage();
          echo "\nListing running EC2 instances: $error\n";
          sleep(10);
        } catch (Exception $e) {
          echo "\nError Listing running EC2 instances: $e\n";
          sleep(10);
        }
      }
      
      // Terminate the instances
      if (count($instances)) {
        try {
          echo "\nTerminating " . count($instances) . " in $location...";
          $ec2->terminateInstances(array('InstanceIds' => $instances));
          $stopped = true;
          echo "\nTerminated " . count($instances) . " in $location";
        } catch (\Aws\Ec2\Exception\Ec2Exception $e) {
          $ok = false;
          $error = $e->getMessage();
          echo "\nTerminating EC2 instances: $error\n";
        } catch (Exception $e) {
          $ok = false;
          echo "\nError terminating EC2 instances: $e\n";
        }
      } else {
        $stopped = true;
      }
      if ($stopped) {
        $status['ec2']['terminated'][$location] = true;
      }
    }
  }
}
?>
