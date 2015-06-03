<?php
// copy this file to the wp root named tc-fullpage-init.php
$tc_fp_cache = true;
$tc_fp_prefix = $_SERVER['HTTPS'] ? 'https-' : 'http-';
$tc_fp_requests = array(
    '#^/$#' => array(),
);
//if(array_key_exists('nc', $_GET)) {
//    $tc_fp_cache = false;
//}