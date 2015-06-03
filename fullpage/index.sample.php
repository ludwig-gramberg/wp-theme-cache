<?php
// add the following code to the top of your wordpress index.php

/* begin fullpage cache */
if(file_exists('tc-fullpage-config.php')) {
    require_once 'tc-fullpage-config.php';
    require_once 'wp-content/plugins/theme-cache/fullpage/functions.php';
    tc_process_request($tc_fp_requests, $tc_fp_nocache_cookies, $tc_fp_nocache_params, $tc_fp_mysql, $tc_fp_folder, $tc_fp_prefix);
}
/* endof fullpage cache */