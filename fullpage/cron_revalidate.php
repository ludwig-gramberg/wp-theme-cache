<?php
/**
 * updates cache entries that are older than X
 * call like so:
 * php -f cron_revalidate.php 300 300 500
 * parameter 1 is max age of cache entry in seconds before recreation
 * parameter 2 is runningtime in seconds
 * parameter 3 is poll interval in ms
 */
require_once realpath(dirname(__FILE__)).'/cron_header.php';

$cron_cache_maxage = array_key_exists(1, $_SERVER['argv']) ? $_SERVER['argv'][1] : 300; // s
$cron_runtime = array_key_exists(2, $_SERVER['argv']) ? $_SERVER['argv'][2] : 300; // s
$cron_interval = array_key_exists(3, $_SERVER['argv']) ? $_SERVER['argv'][3] : 1000; // ms

tc_cron_revalidate($tc_fp_mysql, $wp_base_dir.$tc_fp_folder, $cron_cache_maxage, $cron_runtime, $cron_interval);