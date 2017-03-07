<?php
include('settings.inc');

CheckUrls($urls_file);


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

function CheckUrls($file) {
  $ok = true;
  $urls = array();
  global $locations, $url_column, $country_column;
  $unknown = array();
  $duplicate_count = 0;
  $null_count = 0;
  $invalid_rows = 0;
  $invalid_urls = 0;
  $rowcount = 0;

  $gzfile = gzopen("$file.gz", 'r');
  if ($gzfile) {
    $count = 0;
    $first_row = true;
    while (!gzeof($gzfile)) {
      $line = gzgets($gzfile);
      if ($line) {
        $rowcount++;
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
                if (!isset($unknown[$country]))
                  $unknown[$country] = $country;
              }
              if (isset($location) && strlen($url) && preg_match('/^(http(s)?:\/\/)?[\w\d\.\-]+/iu', $url)) {
                $key = hash('sha256', "$location - $url");
                if (!isset($urls[$key])) {
                  $urls[$key] = 1;
                } else {
                  $duplicate_count++;
                }
              } elseif (isset($location)) {
                $invalid_urls++;
              }
            } else {
              $null_count++;
            }
          } else {
            $invalid_rows++;
          }
        }
      }
    }
    gzclose($gzfile);
    if (count($unknown)) {
      echo "Unknown Countries: ";
      foreach ($unknown as $country)
        echo "$country,";
      echo "\n";
    }
    $url_count = count($urls);
    echo "\n";
    echo "Rows in CSV: $rowcount\n";
    echo "Unique URL/location combinations: $url_count\n";
    echo "Duplicate URL/location combinations: $duplicate_count\n";
    echo "NULL URLs: $null_count\n";
    echo "Invalid URLs: $invalid_urls\n";
    echo "Invalid CSV Rows: $invalid_rows\n";
  }
}
  
?>
