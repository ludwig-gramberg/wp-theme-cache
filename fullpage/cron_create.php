<?php
/**
 * creates new cache entries
 * call like so:
 * php -f cron_create.php 300 500
 * parameter 1 is runningtime in seconds
 * parameter 2 is poll interval in ms
 */
require_once realpath(dirname(__FILE__)).'/cron_header.php';

$cron_runtime = array_key_exists(1, $_SERVER['argv']) ? $_SERVER['argv'][1] : 300; // s
$cron_interval = array_key_exists(2, $_SERVER['argv']) ? $_SERVER['argv'][2] : 500; // ms

tc_cron_create($tc_fp_mysql, $wp_base_dir.$tc_fp_folder, $cron_runtime, $cron_interval);