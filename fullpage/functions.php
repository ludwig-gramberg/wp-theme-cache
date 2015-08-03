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

function tc_request_to_filename($prefix, $request, $params = array()) {

    $filename = trim($request, '/');
    $filename = $filename == '' ? '_' : $filename;
    $filename = str_replace('/', '-', $filename);
    foreach($params as $param => $value) {
        $filename .= '_'.str_replace('/', '-', $param.'-'.$value);
    }
    $filename .= '.html';

    return $prefix.$filename;
}

function tc_process_request($tc_fp_requests, $tc_fp_mysql, $tc_fp_folder, $tc_fp_prefix, $tc_fp_hostname) {
    $tc_fp_start = microtime(true);
    if($_SERVER['REQUEST_METHOD'] != 'GET') {
        return;
    }

    if($_SERVER['HTTP_USER_AGENT'] == 'wp_tcfpc_fetch') {
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
            $filename = tc_request_to_filename($tc_fp_prefix, $request, $validParams);

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

    do {
        $__i = microtime(true);

        $stm_fetch->execute();
        $poll_wait = true;

        while($row = $stm_fetch->fetchObject()) {
            $poll_wait = false;

            $abs_target_file = $folder.$row->file;
            $abs_target_tmp_file = $folder.$row->file.'.tmp';

            clearstatcache(true, $abs_target_file);
            clearstatcache(true, $abs_target_tmp_file);

            // -s silent
            // -f dont download if http error
            // -k dont check ssl validity
            $command = 'curl -A '.escapeshellarg('wp_tcfpc_fetch').' -s -f -k -o '.escapeshellarg($abs_target_tmp_file).' --write-out "%{http_code}" '.escapeshellarg($row->request);
            $rc = null;
            $ro = array();
            exec($command, $ro, $rc);
            $http_code = trim($ro[0]);

            // clean up if exists
            if(file_exists($abs_target_file)) {
                unlink($abs_target_file);
            }
            if($http_code == '200' && filesize($abs_target_tmp_file) > 0 && $rc == 0) {
                rename($abs_target_tmp_file, $abs_target_file);
                $stm_update->bindValue(':file', $row->file);
                $stm_update->execute();
            } else {
                if(file_exists($abs_target_tmp_file)) {
                    unlink($abs_target_tmp_file);
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

    do {
        $__i = microtime(true);

        $stm_fetch->execute();

        $__e = (microtime(true)-$__s)*1000;
        while($__e < $cron_runtime && ($row = $stm_fetch->fetchObject())) {

            $abs_target_file = $folder.$row->file;
            $abs_target_tmp_file = $folder.$row->file.'.tmp';

            clearstatcache(true, $abs_target_tmp_file);
            clearstatcache(true, $abs_target_file);

            // -s silent
            // -f dont download if http error
            // -k dont check ssl validity
            $command = 'curl -A '.escapeshellarg('wp_tcfpc_fetch').' -s -f -k -o '.escapeshellarg($abs_target_tmp_file).' --write-out "%{http_code}" '.escapeshellarg($row->request);
            $rc = null;
            $ro = array();
            exec($command, $ro, $rc);
            $http_code = trim($ro[0]);

            if($http_code == '200' && filesize($abs_target_tmp_file) > 0 && $rc == 0) {
                if(!file_exists($abs_target_file) || sha1_file($abs_target_file) != sha1_file($abs_target_tmp_file)) {
                    rename($abs_target_tmp_file, $abs_target_file);
                } else {
                    unlink($abs_target_tmp_file);
                }
                $stm_update->bindValue(':file', $row->file);
                $stm_update->execute();
            } else {
                if(file_exists($abs_target_file)) {
                    unlink($abs_target_file);
                }
                if(file_exists($abs_target_tmp_file)) {
                    unlink($abs_target_tmp_file);
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

    $db = null;
}