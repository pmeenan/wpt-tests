<?php
$urls = null;
$results = array();
$dates = array();
$files = glob('webperf_*.csv.gz');
sort($files);
$count = 0;
foreach ($files as $file) {
  echo "processing $file...\n";
  $count++;
  LoadResults($file);
  // Delete any entries that didn't exist in all of the runs
  $delete = array();
  foreach ($urls as $key => $data) {
    if (count($data) != $count)
      $delete[] = $key;
  }
  foreach ($delete as $key)
    unset($urls[$key]);
  $urlcount = count($urls);
  echo "$urlcount URLs had results across all runs to this point\n\n";
}

// Write out the results
$metrics = ['si' => 20000, 'ttfb' => 5000, 'render' => 20000, 'dcl' => 20000, 'load' => 60000, 'bytes' => 20000000, 'requests' => 1000, 'rtt' => 1000, 'id' => 0];
echo "writing results...\n";
$speedy = array();
foreach ($metrics as $metric => $max) {
  $out = fopen("raw_$metric.txt", 'wb');
  if ($out) {
    fwrite($out, 'URL');
    foreach ($dates as $date) {
      fwrite($out, "\t$date");
      if ($metric == 'si')
        $speedy[$date] = 0;
    }
    fwrite($out, "\n");
    foreach ($urls as $key => $data) {
      fwrite($out, $key);
      foreach ($dates as $date) {
        $val = $data[$date][$metric];
        fwrite($out, "\t$val");
        if ($val <= 5000 && $metric == 'si')
          $speedy[$date]++;
      }
      fwrite($out, "\n");
    }
    fclose($out);
  }
}

// Generate the histograms
echo "writing histograms...\n";
foreach ($metrics as $metric => $max) {
  if ($max > 0) {
    $increment = $max / 200;
    $histograms = array();
    for ($bucket = 0; $bucket < $max; $bucket += $increment) {
      $histograms[$bucket] = array();
      foreach ($dates as $date) {
        $histograms[$bucket][$date] = 0;
      }
    }
    foreach ($urls as $key => $data) {
      foreach ($dates as $date) {
        $val = $data[$date][$metric];
        $bucket = min($max - $increment, max(0, intval($val / $increment) * $increment));
        $histograms[$bucket][$date]++;
      }
    }
    $out = fopen("histogram_$metric.csv", 'wb');
    if ($out) {
      fwrite($out, 'Bucket');
      foreach ($dates as $date)
        fwrite($out, ",$date");
      fwrite($out, "\n");
      foreach ($histograms as $bucket => $data) {
        fwrite($out, $bucket);
        foreach ($dates as $date) {
          $count = $data[$date];
          fwrite($out, ",$count");
        }
        fwrite($out, "\n");
      }
      fclose($out);
    }
  }
}

$total = count($urls);
echo "\n$total URLs:\n";
foreach ($dates as $date) {
  $pct = number_format(floatval($speedy[$date]) / floatval($total) * 100.0, 2);
  echo "$date speedy: {$speedy[$date]} ($pct%)\n";
}

function LoadResults($file) {
  global $urls;
  global $dates;
  $first_file = false;
  $url_column = 15;
  $country_column = 2;
  $test_id_column = 35;
  $speed_index_column = 21;
  $columns = ['ttfb' => 16, 'render' => 17, 'dcl' => 18, 'load' => 19, 'si' => 21, 'bytes' => 23, 'requests' => 25, 'rtt' => 42];
  if (preg_match('/webperf_(\d\d\d\d)_(\d\d)_(\d\d)\.csv\.gz/', $file, $matches) && is_array($matches)) {
    $date = "{$matches[2]}/{$matches[3]}/{$matches[1]}";
    $dates[] = $date;
    if (!isset($urls)) {
      $urls = array();
      $first_file = true;
    }
    $gzfile = gzopen($file, 'r');
    if ($gzfile) {
      $count = 0;
      $first_row = true;
      while (!gzeof($gzfile)) {
        $line = gzgets($gzfile);
        if ($line) {
          $count++;
          if ($count % 1000 == 0)
            echo "\r$count";
          if ($first_row) {
            $first_row = false;
          } else {
            $row = str_getcsv($line, "\t");
            if (isset($row[$url_column]) &&
                isset($row[$country_column]) &&
                isset($row[$speed_index_column]) && strlen($row[$speed_index_column])) {
              $url = trim($row[$url_column]);
              $country = trim($row[$country_column]);
              $si = intval($row[$speed_index_column]);
              $id = $row[$test_id_column];
              if (strlen($url) && strlen($country) && $si > 0) {
                $key = "$country - $url";
                if ($first_file || isset($urls[$key])) {
                  if (!isset($urls[$key]))
                    $urls[$key] = array();
                  if (!isset($results[$key]))
                    $results[$key] = array();
                  $values = array('id' => $id);
                  foreach ($columns as $label => $column)
                    $values[$label] = intval($row[$column]);
                  $urls[$key][$date] = $values;
                }
              }
            }
          }
        }
      }
      gzclose($gzfile);
      echo "\rProcessed $count rows\n";
    }
  }
}
?>
