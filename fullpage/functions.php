<?php
function tc_flag_cache_create($request, $filename, $config) {
    try {
        $db = new PDO('mysql:host='.$config['host'].';dbname='.$config['name'], $config['user'], $config['pass'], array(
            PDO::ATTR_PERSISTENT => false,
            PDO::ERRMODE_EXCEPTION => true,
        ));
        $stm = $db->prepare('
            INSERT INTO `'.$config['table'].'` (`file`,`request`,`created`)
            VALUES (:file, :request, NULL)
            ON DUPLICATE KEY UPDATE `created`=NULL;
        ');
        $stm->bindValue(':file', $filename);
        $stm->bindValue(':request', $request);
        $stm->execute();
        $db = null;
    } catch(Exception $e) {
        error_log((string)$e);
    }
}

function tc_request_to_filename($prefix, $hostname, $request, $params = array()) {

    $filename = trim($request, '/');
    $filename = $filename == '' ? '_' : $filename;
    $filename = str_replace('/', '-', $filename);
    foreach($params as $param => $value) {
        $filename .= '_'.str_replace('/', '-', $param.'-'.$value);
    }
    $filename .= '.html';

    return $prefix.$hostname.'-'.$filename;
}

/**
 * @param $tc_fp_requests
 * @param $tc_fp_mysql
 * @param $tc_fp_folder
 * @param $tc_fp_prefix
 * @param array $tc_fp_hostnames
 */
function tc_process_request($tc_fp_requests, $tc_fp_mysql, $tc_fp_folder, $tc_fp_prefix, $tc_fp_hostnames) {

    $tc_fp_hostnames = (array)$tc_fp_hostnames;

    $tc_fp_start = microtime(true);
    if($_SERVER['REQUEST_METHOD'] != 'GET') {
        return;
    }

    if($_SERVER['HTTP_USER_AGENT'] == 'wp_tcfpc_fetch') {
        return;
    }

    $tc_fp_hostname = $_SERVER['HTTP_HOST'];
    if(!in_array($tc_fp_hostname, $tc_fp_hostnames)) {
        return;
    }

    // normalize request
    $request = trim(preg_replace('/\?.*$/', '', $_SERVER['REQUEST_URI']), '/');
    $request = $request == '' ? '/' : '/'.$request.'/';

    $www_request = $_SERVER['HTTPS'] ? 'https://' : 'http://';
    $www_request .= $tc_fp_hostname;
    $www_request .= $request;

    // find request
    foreach($tc_fp_requests as $expr => $params) {
        if(preg_match($expr, $request)) {

            // detect request params
            $validParams = array();
            $f = false;
            foreach($params as $param => $paramExpr) {
                if(array_key_exists($param, $_GET) && is_scalar($_GET[$param]) && preg_match($paramExpr, $_GET[$param])) {
                    $validParams[$param] = $_GET[$param];
                    $www_request .= $f ? '?' : '&';
                    $www_request .= $param.'='.$_GET[$param];
                    $f = true;
                }
            }
            $filename = tc_request_to_filename($tc_fp_prefix, $tc_fp_hostname, $request, $validParams);

            if(file_exists($tc_fp_folder.$filename)) {
                $td = number_format((microtime(true)-$tc_fp_start)*1000,2,'.','');
                header('Content-Type: text/html;charset=utf-8');
                header('X-TcFpc-Time: '.$td.'ms');
                header('X-Sendfile: '.$_SERVER['DOCUMENT_ROOT'].'/'.$tc_fp_folder.$filename);
                exit;
            } else {
                tc_flag_cache_create($www_request, $filename, $tc_fp_mysql);
                return;
            }
        }
    }
}

function tc_check_dir($folder) {
    if(is_dir($folder)) {
        return true;
    }
    mkdir($folder, 0777, true);
    return is_dir($folder);
}

function tc_cron_create($config, $folder, $cron_runtime, $cron_interval) {

    if(!tc_check_dir($folder)) {
        error_log('could not find/create cache folder '.$folder);
        exit(1);
    }

    $cron_runtime*=1000; // ms
    $__s = microtime(true);

    // connect db

    $db = new PDO('mysql:host='.$config['host'].';dbname='.$config['name'], $config['user'], $config['pass'], array(
        PDO::ATTR_PERSISTENT => false,
        PDO::ERRMODE_EXCEPTION => true,
    ));
    $stm_update = $db->prepare('UPDATE `'.$config['table'].'` SET `created` = NOW() WHERE `file` = :file');
    $stm_fetch = $db->prepare('SELECT `file`,`request` FROM `'.$config['table'].'` WHERE `created` IS NULL LIMIT 1');
    $stm_remove = $db->prepare('DELETE FROM `'.$config['table'].'` WHERE `file` = :file');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'wp_tcfpc_fetch');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    do {
        $__i = microtime(true);

        $stm_fetch->execute();
        $poll_wait = true;

        while($row = $stm_fetch->fetchObject()) {
            $poll_wait = false;

            $abs_target_file = $folder.$row->file;

            curl_setopt($ch, CURLOPT_URL, $row->request);
            $ch_result = curl_exec($ch);
            $ch_code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));

            if($ch_code == 200) {

                file_put_contents($abs_target_file, $ch_result);

                $stm_update->bindValue(':file', $row->file);
                $stm_update->execute();
            } else {
                if(file_exists($abs_target_file)) {
                    @unlink($abs_target_file);
                }
                $stm_remove->bindValue(':file', $row->file);
                $stm_remove->execute();
            }
        }
        $stm_fetch->closeCursor();

        if($poll_wait) {
            $__d = intval((microtime(true)-$__i)*1000);
            if($__d < $cron_interval) {
                usleep(($cron_interval - $__d)*1000);
            }
        }

        $__e = (microtime(true)-$__s)*1000;
    } while($__e < $cron_runtime);

    curl_close($ch);

    $db = null;
}

function tc_cron_revalidate($config, $folder, $cache_maxage, $cron_runtime, $cron_interval) {

    if(!tc_check_dir($folder)) {
        error_log('could not find/create cache folder '.$folder);
        exit(1);
    }

    $cron_runtime*=1000; // ms
    $__s = microtime(true);

    // connect db

    $db = new PDO('mysql:host='.$config['host'].';dbname='.$config['name'], $config['user'], $config['pass'], array(
        PDO::ATTR_PERSISTENT => false,
        PDO::ERRMODE_EXCEPTION => true,
    ));

    $stm_update = $db->prepare('UPDATE `'.$config['table'].'` SET `created` = NOW() WHERE `file` = :file');
    $stm_fetch = $db->prepare('SELECT `file`,`request` FROM `'.$config['table'].'` WHERE DATE_ADD(`created`, interval '.$cache_maxage.' second) < NOW() ORDER BY `created` ASC');
    $stm_remove = $db->prepare('DELETE FROM `'.$config['table'].'` WHERE `file` = :file');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'wp_tcfpc_fetch');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    do {
        $__i = microtime(true);

        $stm_fetch->execute();

        $__e = (microtime(true)-$__s)*1000;
        while($__e < $cron_runtime && ($row = $stm_fetch->fetchObject())) {

            $abs_target_file = $folder.$row->file;
            clearstatcache(true, $abs_target_file);

            curl_setopt($ch, CURLOPT_URL, $row->request);
            $ch_result = curl_exec($ch);
            $ch_errno = curl_errno($ch);
            $ch_code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));

            if($ch_errno != CURLE_OK) {
                error_log('cache: error fetching '.$row->request.': '.curl_error($ch));
                continue;
            }

            if($ch_code == 200) {

                file_put_contents($abs_target_file, $ch_result);

                $stm_update->bindValue(':file', $row->file);
                $stm_update->execute();
            } else {
                if(file_exists($abs_target_file)) {
                    @unlink($abs_target_file);
                }
                $stm_remove->bindValue(':file', $row->file);
                $stm_remove->execute();
            }
        }
        $stm_fetch->closeCursor();

        $__d = intval((microtime(true)-$__i)*1000);
        if($__d < $cron_interval) {
            usleep(($cron_interval - $__d)*1000);
        }

        $__e = (microtime(true)-$__s)*1000;
    } while($__e < $cron_runtime);

    curl_close($ch);

    $db = null;
}