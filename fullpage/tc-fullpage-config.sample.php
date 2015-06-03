<?php
// copy this file to the wp root named tc-fullpage-config.php
$tc_fp_prefix = $_SERVER['HTTPS'] ? 'https-' : 'http-';
$tc_fp_nocache_cookies = array(
    'PHPSESSID',
);
$tc_fp_nocache_params = array(
    's',
    'replytocom',
);
$tc_fp_requests = array(
    '#^/$#' => array(),
);
$tc_fp_mysql = array(
    'host' => '',
    'user' => '',
    'pass' => '',
    'name' => '',
    'table' => 'wp_theme_cache_fullpage',
);
$tc_fp_folder = 'wp-content/uploads/tcfpc/';