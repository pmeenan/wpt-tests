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

  if (is_file("$file.gz")) {
    $csv_file = array_map(function($v){return str_getcsv($v, "\t");}, gzfile("$file.gz"));
    if ($csv_file && is_array($csv_file) && count($csv_file) > 1) {
      $first = true;
      foreach ($csv_file as $entry) {
        if ($first) {
          $first = false;
        } else {
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
      if (count($unknown)) {
        echo "Unknown Countries: ";
        foreach ($unknown as $country)
          echo "$country,";
        echo "\n";
      }
      $count = count($csv_file);
      $url_count = count($urls);
      echo "\n";
      echo "Rows in CSV: $count\n";
      echo "Unique URL/location combinations: $url_count\n";
      echo "Duplicate URL/location combinations: $duplicate_count\n";
      echo "NULL URLs: $null_count\n";
      echo "Invalid URLs: $invalid_urls\n";
      echo "Invalid CSV Rows: $invalid_rows\n";
    }
  }
}
  
?>
