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
        ');
        $stm->bindValue(':file', $filename);
        $stm->bindValue(':request', $request);
        $stm->execute();
        $db = null;
    } catch(Exception $e) {
        error_log((string)$e);
    }
}

function tc_request_to_filename($request, $params = array()) {

    $filename = trim($request, '/');
    $filename = $filename == '' ? '_' : $filename;
    $filename = str_replace('/', '-', $filename);
    foreach($params as $param => $value) {
        $filename .= '_'.str_replace('/', '-', $param.'-'.$value);
    }
    $filename .= '.html';

    return $filename;
}

function tc_process_request($tc_fp_requests, $tc_fp_nocache_cookies, $tc_fp_nocache_params, $tc_fp_mysql, $tc_fp_folder) {
    $tc_fp_start = microtime(true);
    if($_SERVER['REQUEST_METHOD'] != 'GET') {
        return;
    }

    // test for cookies
    foreach($tc_fp_nocache_cookies as $cookie) {
        if(array_key_exists($cookie, $_COOKIE)) {
            return;
        }
    }

    // test for params
    foreach($tc_fp_nocache_params as $param) {
        if(array_key_exists($param, $_GET)) {
            return;
        }
    }

    // normalize request
    $request = trim(preg_replace('/\?.*$/', '', $_SERVER['REQUEST_URI']), '/');
    $request = $request == '' ? '/' : '/'.$request.'/';

    $www_request = $_SERVER['HTTPS'] ? 'https://' : 'http://';
    $www_request .= $_SERVER['HTTP_HOST'];
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
            $filename = tc_request_to_filename($request, $validParams);

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

function tc_cron_create($config, $folder, $cron_runtime, $cron_interval) {

    $cron_runtime*=1000; // ms
    $__s = microtime(true);

    // connect db

    $db = new PDO('mysql:host='.$config['host'].';dbname='.$config['name'], $config['user'], $config['pass'], array(
        PDO::ATTR_PERSISTENT => false,
        PDO::ERRMODE_EXCEPTION => true,
    ));
    $stm = $db->prepare('UPDATE `'.$config['table'].'` SET `created` = NOW() WHERE `file` = :file');

    do {
        $__i = microtime(true);

        $rows = $db->query('SELECT `file`,`request` FROM `'.$config['table'].'` WHERE `created` IS NULL LIMIT 1')->fetchAll(PDO::FETCH_OBJ);
        $poll_wait = true;
        if(!empty($rows)) {
            $poll_wait = false;
            $row = $rows[0];

            $abs_target_file = $folder.$row->file;

            $command = 'curl -f -k -o '.escapeshellarg($abs_target_file).' '.escapeshellarg($row->request);
            $rc = null;
            $ro = array();
            exec($command, $ro, $rc);

            $stm->bindValue(':file', $row->file);
            $stm->execute();

            // failed files must be removed
            if(file_exists($abs_target_file) && filesize($abs_target_file) == 0) {
                unlink($abs_target_file);
            }
        }

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