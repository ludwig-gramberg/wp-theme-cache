<?php
session_start();

$absPath = realpath(dirname(__FILE__).'/../../../../').'/';
$configFile = $absPath.'tc-fullpage-config.php';

if(file_exists($configFile)) {

    require_once $configFile;
    require_once $absPath.'wp-content/plugins/theme-cache/fullpage/functions.php';

    tc_process_request($tc_fp_cache, $tc_fp_requests, $tc_fp_mysql, $tc_fp_folder, $tc_fp_prefix, $tc_fp_hostname);
}