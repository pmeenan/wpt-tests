<?php
include('settings.inc');

$metrics = array('Time to First Byte' => 'TTFB',
                 'Time to Start Render' => 'render',
                 'Time to DOM Content Loaded' => 'domContentLoadedEventStart',
                 'Time to Load Event' => 'docTime',
                 'Time to Fully Loaded' => 'fullyLoaded',
                 'Speed Index' => 'SpeedIndex',
                 'Visually Complete' => 'visualComplete',
                 'Page Weight (bytes)' => 'bytesIn',
                 'Page Weight to Load Event (bytes)' => 'bytesInDoc',
                 'Request Count' => 'requestsFull',
                 'Request Count to Load Event' => 'requestsDoc',
                 'Connection Count' => 'connections',
                 'Number of Unique Domains' => 'domains',
                 'Number of DOM Elements' => 'domElements',
                 'Bytes Eligible for gzip' => 'gzip_total',
                 'Available gzip savings' => 'gzip_savings',
                 'JPEG Image Bytes' => 'image_total',
                 'Available JPEG Image Byte Savings' => 'image_savings',
                 'CDN Score' => 'score_cdn',
                 'Caching Score' => 'score_cache',
                 'Base Page Redirect Count' => 'base_page_redirects',
                 'Base Page TTFB' => 'base_page_ttfb',
                 'Server RTT' => 'server_rtt');

$file = "$current_crawl.csv";
if (is_file($file)) {
  $values = array();
  echo "Loading $file...\n";
  $f = fopen($file, 'rb');
  if ($f) {
    while (($line = fgets($f)) !== false) {
      $columns = explode("\t", $line);
      foreach ($columns as &$column)
        $column = trim($column, " \"\r\n");
      if (isset($c)) {
        $channel = $columns[$c['channel']];
        $channel = 'all';
        if (strlen($channel)) {
          if (!isset($values[$channel])) {
            $values[$channel] = array('values' => array(), 'percentiles' => array());
            foreach ($metrics as $label => $key) {
              $values[$channel]['values'][$label] = array();
              $values[$channel]['percentiles'][$label] = array();
            }
          }
          foreach ($metrics as $label => $key) {
            if (isset($c[$label]) && isset($columns[$c[$label]]) && strlen($columns[$c[$label]]) && $columns[$c[$label]] >= 0)
              $values[$channel]['values'][$label][] = intval($columns[$c[$label]]);
          }
        }
      } else {
        $c = array();
        $count = count($columns);
        for ($i = 0; $i < $count; $i++)
          $c[$columns[$i]] = $i;
      }
    }
    fclose($f);
  } else {
    echo "Error opening csv file $file\n";
  }
} else {
  echo "csv file $file missing\n";
}

if (isset($values)) {
  foreach ($values as $channel => $data) {
    echo "\nProcessing $channel...\n";
    foreach ($metrics as $label => $key) {
      $count = count($data['values'][$label]);
      echo "Sorting $count results for $label...\n";
      sort($data['values'][$label], SORT_NUMERIC);
      // Pick 1001 values to populate the percentiles
      for($i = 0; $i <= 1000; $i++) {
        $percentile_key = "p" . ($i / 10.0);
        $index = min($count - 1, max(0, intval(floatval(($count - 1) * $i) / 1000.0)));
        if ($index < $count)
          $data['percentiles'][$label][$percentile_key] = $data['values'][$label][$index];
        else
          $data['percentiles'][$percentile_key] = '';
      }
    }
    $file = "metrics-$channel-$current_crawl.csv";
    echo "Writing results to $file...\n";
    $csv = "Percentile";
    foreach ($metrics as $label => $key)
      $csv .= ",$label";
    $csv .= "\n";
    for($i = 0; $i <= 1000; $i++) {
      $percentile = $i / 10.0;
      $percentile_key = "p" . $percentile;
      $csv .= number_format($percentile, 1);
      foreach ($metrics as $label => $key)
        $csv .= ",{$data['percentiles'][$label][$percentile_key]}";
      $csv .= "\n";
    }
    file_put_contents($file, $csv);
  }
  echo "Done\n";
}
?>
