<?php


/**
 * 加载配置文件
 * @param $filename
 * @return array|mixed
 */
function config($filename)
{
    $filepath = APP_ROOT . DS . 'config' . DS . rtrim($filename, '.php') . '.php';
    return file_exists($filepath) ? include($filepath) : [];
}

//######################################            php函数           ############################################
//打印变量
function p($variable, $exit = false)
{
    print_r($variable);
    if ($exit) {
        exit(0);
    }
}

function ddd($variable, $exit = false)
{
    echo '<pre style="background-color:#DFDFDF;color:#666;font-size:14px;font-weight:bold;">
';
    print_r($variable);
    echo '
</pre>';
    if ($exit) {
        exit(0);
    }
}

function br()
{
    echo '<br>---------------------------------------------------------------------------<br>';
}

// 打印时间戳的日期格式
function dt($timestamp = '')
{
    $timestamp = !empty($timestamp) ? $timestamp : time();
    ddd(date('Y-m-d H:i:s', $timestamp));
}

function put($data, $path = 'E:log.txt')
{
    file_put_contents($path, var_export($data, true) . "\r\n", FILE_APPEND);
}

//每天备份一次昨天的文件内容:$force强制执行复制
function backUpFunctionsFile($force = false)
{
    $today = strtotime(date('Y-m-d', time()));
    if ($force || !file_exists('./bak/functions_bak.php') || strtotime(date('Y-m-d', filemtime('./bak/functions_bak.php'))) < $today) {
        if (!is_dir('./bak')) mkdir('./bak');
        echo '<h6>提醒 : functions_bak.php 已更新 !</h6>';
        return copy('functions.php', './bak/functions_bak.php');
    }
    return false;
}

// 每天请求一次item.xml，保存到本地
function refreshItemXml($force = false)
{
    $today = strtotime(date('Y-m-d', time()));
    if ($force || !file_exists('E:/item.xml') || strtotime(date('Y-m-d', filemtime('E:/item.xml'))) < $today) {
        $url = 'http://manager.3dcenter.ylcq.such-game.com/mobileclient/x3dgame_branch/adminconfig/cn/item.xml?rnd=' . rand();
        $contents = file_get_contents($url);
        if (strlen($contents) > 1024) {
            //更新
            echo '<h6>提醒 : item.xml 已更新 !</h6>';
            file_put_contents('E:/item.xml', $contents);
        }
        return true;
    }
    return false;
}

function setCharset($charset = 'UTF-8')
{
    echo '<meta http-equiv="Content-Type" content="text/html; charset=' . $charset . '"/>';
}

/*
	日志测试
	$log 源日志
	$logFileName 需检查的日志文件名称
	$gameworldlogFile 匹配配置文件路径
$map = array(
	'armystorehouselog' => array(
		'[2018-03-21 09:51:33] ArmyStoreHouseLog (Info): [ArmyStoreHouseMgr::OnArmyStoreHouseGlobalExchangeAck][user[9  192] type:2 credit:498]',
		'[2018-03-21 09:51:30] ArmyStoreHouseLog (Info): [ArmyStoreHouseMgr::OnArmyStoreHouseGlobalDonateAck][user[9  192] type:1 credit:498]',
	),
);
foreach($map as $logFileName => $logArr){
	foreach($logArr as $log){
		$res = logTest($log,$logFileName);
		ddd($res);
	}
}
*/
function logTest($log, $logFileName, $gameworldlogFile = 'E:\g1\log_import\gameworldlog.php')
{
    global $dataType;
    if (!file_exists($gameworldlogFile)) {
        ddd('gameworldlog文件不存在');
        return false;
    }
    require($gameworldlogFile);
    static $conn;
    $retArr = array('result' => array('msg' => 'FAIL'), 'status' => array('fields_status' => 'FAIL', 'mysql_status' => 'FAIL',));
    //获取192日志数据库连接
    if (empty($conn)) {
        $config = array(
            'host' => '192.168.0.192',
            'port' => 5888,
            'db_name' => '192_0_log',
            'user' => 'root',
            'password' => '121212',
            'charset' => 'utf8'
        );
        $conn = @mysql_connect($config['host'] . ':' . $config['port'], $config['user'], $config['password']);
        if ($conn) {
            mysql_set_charset($config['charset'], $conn);
            mysql_select_db($config['db_name'], $conn);
        }
    }
    if (!isset($dataType[$logFileName])) {
        ddd('此日志文件未设置:' . $logFileName);
        return false;
    }
    $type_exp = $dataType[$logFileName]['type_exp'];
    $ret = preg_match($type_exp, $log, $matches);
    if (!isset($matches[1])) {
        ddd('此日志内容不匹配type_exp: ' . $type_exp . '<br>' . $log);
        return false;
    }
    $op_type = $matches[1];
    $ignore_type = $dataType[$logFileName]['ignore_type'];
    $op_type_arr = $dataType[$logFileName]['op_type'];
    if (in_array($op_type, $ignore_type)) {
        ddd('此日志类型被设置为ignore_type忽略入库: ' . $op_type);
        return false;
    }
    if (!isset($op_type_arr[$op_type])) {
        ddd($logFileName . '日志文件，op_type=' . $op_type . '的类型未设置！');
        return false;
    }
    //某个op_type类型信息:$op_type_arr[$op_type]
    $table = $op_type_arr[$op_type]['table'];
    $exp_list = $op_type_arr[$op_type]['exp_list'];
    $total_num = count($exp_list);
    $has_match_times = 0;
    for ($i = 0; $i < $total_num; $i++) {
        $list = $exp_list[$i];
        $exp = $list['exp'];
        $field_list = $list['field_list'];
        $num1 = count($field_list);
        $import = $list['import'];
        $exp_arr = array();
        $ret = preg_match($exp, $log, $matches);
        // 检查是否全部都不匹配
        if ($ret != 1) {
            $exp_arr[] = $exp;
            $has_match_times++;
            if ($has_match_times >= $total_num) {
                $retArr['result'] = array(
                    'msg' => '全都不匹配！',
                    'log' => $log,
                    'exp_arr' => $exp_arr,
                );
                break;
            }
            continue;
        }
        $num2 = count($matches) - 1;
        //检查匹配字段数目
        if ($num1 !== $num2) {
            $retArr['result'] = array(
                'msg' => '匹配字段数目与设置字段数目不一致!',
                'log' => $log,
                'match_exp' => $exp,
                'match_exp_index' => $i,
                'matched_fields_number' => $num2,
                'match_fields' => $matches,
                'set_fields_number' => $num1,
                'set_fields' => $field_list,
            );
        } else {
            $retArr['result'] = array(
                'msg' => 'OK',
                'log' => $log,
                'match_fields' => $matches,
                'match_exp_index' => $i,
            );
            $retArr['status'] = array(
                'fields_status' => 'OK',
            );
        }
        //连接192日志数据库,检查数据表情况
        if ($conn) {
            $query = mysql_query("SHOW FULL TABLES", $conn);
            $mysql_tables = array();
            while ($row = mysql_fetch_assoc($query)) {
                $mysql_tables[] = $row['Tables_in_192_0_log'];
            }
            if (!in_array($table, $mysql_tables)) {
                $retArr['status']['mysql_status'] = '表: ' . $table . ' 不存在于192数据库！';
            } else {
                $query = mysql_query("SHOW FULL COLUMNS FROM $table", $conn);
                $mysql_fields = array();
                while ($row = mysql_fetch_assoc($query)) {
                    $mysql_fields[] = $row['Field'];
                }
                if (!in_array('json_arr', $mysql_fields) || !in_array('json_arr', $import)) {
                    $retArr['status']['mysql_status'] = '请检查数据表与import是否已有的json_arr字段！';
                    $retArr['status']['import'] = $import;
                    $retArr['status']['mysql_fileds'] = $mysql_fields;
                }
                $diff = array_diff($mysql_fields, $import);
                if (count($mysql_fields) != count($import) || !empty($diff)) {
                    $retArr['status']['mysql_status'] = 'import与数据表字段名称或数目不相等';
                    $retArr['status']['import'] = $import;
                    $retArr['status']['mysql_fileds'] = $mysql_fields;
                    $retArr['status']['diff'] = $diff;
                } else {
                    $retArr['status']['mysql_status'] = 'OK';
                }
            }
        } else {
            $retArr['status']['mysql_status'] = '连接不上192日志数据库:' . mysql_error();
        }
    }
    return $retArr;
}


// 数组导入excel
// $data = array (
// 0 => array ( 0 => 'server_id', 1 => 'role_id', 2 => 'item_id', 3 => 'sum_item', ),
// 1 => array ( 0 => '1', 1 => '44', 2 => '16100', 3 => '160', ),
// 2 => array ( 0 => '1', 1 => '44', 2 => '16103', 3 => '188', ),
// )

function exportExcel($file_name, $data, $output = false)
{
    $file = (strpos($file_name, '.csv') === false) ? $file_name . '.csv' : $file_name;
    if (!$output) {
        $fp = fopen($file, "w");
    } else {
        ob_clean();
        header('Content-Type: application/vnd.ms-excel');
        header("Content-Disposition: attachment;filename = {$file}");
        header('Cache-Control: max-age=0');
        $fp = fopen('php://output', 'a');
    }
    foreach ($data as $row) {
        $fields = array();
        foreach ($row as $val) {
            $fields[] = iconv('utf-8', 'gbk', $val);
        }
        fputcsv($fp, $fields);
    }
    if (!$output) {
        fclose($fp);
    }
}


//批量复制，修改接口文件
// $ditchInfo = array(
// 'oppo_zl' => array('app_id' => '100170','cch_id' => '109','is_ios' => false),
// 'vivo_zl' => array('app_id' => '100170','cch_id' => '102','is_ios' => false),
// 'huawei_zl' => array('app_id' => '100170','cch_id' => '111','is_ios' => false),
// 'meizu_zl' => array('app_id' => '100170','cch_id' => '103','is_ios' => false),
// 'jinli_zl' => array('app_id' => '100170','cch_id' => '114','is_ios' => false),
// 'uc_zl' => array('app_id' => '100170','cch_id' => '105','is_ios' => false),
// '360_zl' => array('app_id' => '100170','cch_id' => '110','is_ios' => false),
// 'xiaomi_zl' => array('app_id' => '100170','cch_id' => '104','is_ios' => false),
// 'baidu_zl' => array('app_id' => '100170','cch_id' => '112','is_ios' => false),
// 'samsung_zl' => array('app_id' => '100170','cch_id' => '167','is_ios' => false),
// 'dangle_zl' => array('app_id' => '100170','cch_id' => '148','is_ios' => false),
// );
// ddd(createApiFile($ditchInfo));

function createApiFile($ditchArr)
{
    $charge_dir = 'E:/g1/managertool/web/api/charge/';
    $create_ticket_dir = 'E:/g1/managertool/web/api/create_ticket/';
    $charge_num = 0;
    $create_ticket_num = 0;
    foreach ($ditchArr as $ditch_name => $info) {
        //判断是否ios渠道
        if (isset($info['is_ios'])) {
            $is_ios = $info['is_ios'];
        } elseif (strpos($ditch_name, 'ios') !== false) {
            $is_ios = true;
        } else {
            $is_ios = false;
        }
        if ($is_ios) {
            $charge_file = $charge_dir . '9377_zxios.php';
            $create_ticket_file = $create_ticket_dir . '9377_zxios.php';
            $src_ditch_name = '9377_zxios';
            $src_app_id = '$data[\'app_id\'] = \'100341\';';        //$data['app_id'] = '100341';
            $src_cch_id = '$data[\'cch_id\'] = \'271\';';    //$data['cch_id'] = '271';
        } else {
            $charge_file = $charge_dir . '9377.php';
            $create_ticket_file = $create_ticket_dir . '9377.php';
            $src_ditch_name = '9377';
            $src_app_id = '$data[\'app_id\'] = \'100341\';';        //$data['app_id'] = '100341';
            $src_cch_id = '$data[\'cch_id\'] = \'270\';';    //$data['cch_id'] = '270';
        }
        if (file_exists($charge_dir . "$ditch_name.php")) {
            return '复制错误,charge文件已存在: ' . $charge_dir . "$ditch_name.php";
        }
        if (file_exists($create_ticket_dir . "$ditch_name.php")) {
            return '复制错误,create_ticket文件已存在: ' . $create_ticket_dir . "$ditch_name.php";
        }
        //复制文件
        if (!copy($charge_file, $charge_dir . "$ditch_name.php") || !copy($create_ticket_file, $create_ticket_dir . "$ditch_name.php")) {
            return "$ditch_name 渠道文件复制错误";
        }
        $content = file_get_contents($charge_dir . "$ditch_name.php");
        $text = str_replace(
            array($src_ditch_name, $src_app_id),
            array($ditch_name, '$data[\'app_id\'] = \'' . $info['app_id'] . '\';'),
            $content);
        if (file_put_contents($charge_dir . "$ditch_name.php", $text) > 0) {
            $charge_num += 1;
        }
        $content = file_get_contents($create_ticket_dir . "$ditch_name.php");
        $text = str_replace(
            array($src_ditch_name, $src_app_id, $src_cch_id),
            array($ditch_name, '$data[\'app_id\'] = \'' . $info['app_id'] . '\';', '$data[\'cch_id\'] = \'' . $info['cch_id'] . '\';'),
            $content);
        if (file_put_contents($create_ticket_dir . "$ditch_name.php", $text) > 0) {
            $create_ticket_num += 1;
        }
    }
    return '处理成功文件数:charge文件' . $charge_num . '个,' . 'create_ticket文件=' . $create_ticket_num . '个';
}


//处理& =连接的数据为数组
//如：transid=32281801081436191507&sign=VIRZttrfhrthtrhhPg3wjNU=&signtype=RSA
function dealParams($str)
{
    $arr = array_map(create_function('$v', 'return explode("=", $v);'), explode('&', $str));
    return $arr;
}

//读取excel数据到数组
function readExcel($filePath)
{
    require_once 'PHPExcel/PHPExcel.php';
    $info = pathinfo($filePath);
    $file_type = $info['extension'];
    if ($file_type == 'xls') {
        $reader = @PHPExcel_IOFactory::createReader('Excel5'); //设置以Excel5格式(Excel97-2003工作簿)
    }
    if ($file_type == 'xlsx') {
        $reader = new PHPExcel_Reader_Excel2007();
    }

    //读excel文件
    $PHPExcel = $reader->load($filePath, 'utf-8'); // 载入excel文件
    $sheet = $PHPExcel->getSheet(0); // 读取第一個工作表
    $highestRow = $sheet->getHighestRow(); // 取得总行数
    $highestColumm = $sheet->getHighestColumn(); // 取得总列数

    //把Excel数据保存数组中
    $data = array();
    for ($rowIndex = 1; $rowIndex <= $highestRow; $rowIndex++) {        //循环读取每个单元格的内容。注意行从1开始，列从A开始
        for ($colIndex = 'A'; $colIndex <= $highestColumm; $colIndex++) {
            $addr = $colIndex . $rowIndex;
            $cell = $sheet->getCell($addr)->getValue();
            if ($cell instanceof PHPExcel_RichText) { //富文本转换字符串
                $cell = $cell->__toString();
            }
            $data[$rowIndex][$colIndex] = $cell;
        }
    }
    return $data;
}

// 处理充值订单日志文件,默认限制5M
function deal_log_file($file_name, $limit_size = 5242880)
{
    $log_file = "$file_name.html";
    $now_time = intval(date('Hi'));
    if (!file_exists($log_file)) {
        file_put_contents($log_file, '');
        chmod($log_file, 0777);
    } else if ($now_time > 1000 && $now_time < 1200) {
        if (filesize($log_file) > $limit_size) {
            is_dir('./log') || mkdir('./log', 0777);
            for ($i = 1; $i < 9999; $i++) {
                $old_name = './log/' . $file_name . "_$i.html";
                if (!file_exists($old_name)) {
                    rename($log_file, $old_name);
                    file_put_contents($log_file, '');
                    chmod($log_file, 0777);
                    break;
                }
            }
        }
    }
    return true;
}


function get_log($url, $save_name = '')
{
    if (empty($save_name)) {
        $save_name = substr($url, strrpos($url, '/') + 1);
        $save_name = 'C:/Users/Administrator/Desktop/' . $save_name;
    }
    return file_put_contents($save_name, file_get_contents($url));
}


//json/json unicode低版本php兼容 : JSON_UNESCAPED_UNICODE
function my_json_encode_unicode($arr)
{
    if (version_compare(PHP_VERSION, '5.4.0', '<')) {
        $str = json_encode($arr);
        //正则匹配：在每次需要替换时调用，调用时函数得到的参数是从str中匹配到的结果
        $str = preg_replace_callback("#\\\u([0-9a-f]{4})#i", "charset_convert", $str);
        return $str;
    } else {
        return json_encode($arr, JSON_UNESCAPED_UNICODE);
    }
}

function charset_convert($arr)
{
    //$arr[1],子匹配去除\u，如7b26做处理
    return iconv('UCS-2BE', 'UTF-8', pack('H4', $arr[1]));
}

function sample_curl($url, $post_arr = array(), $timeout = 10)
{
    $curl = curl_init($url);
    //模拟ip
    // curl_setopt($curl, CURLOPT_HTTPHEADER, array('CLIENT-IP: 203.195.168.193','X-FORWARDED-FOR: 203.195.168.193'));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $post_arr);
    curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
    $content = curl_exec($curl);
    curl_close($curl);

    return $content;
}

function https_curl($url, $post_arr = array(), $timeout = 10)
{
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $post_arr);
    curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
    $content = curl_exec($curl);
    curl_close($curl);

    return $content;
}

//将查询请求串转为数组
function request2Array($request = '')
{
    $retArr = array();
    if (empty($request)) $request = $_SERVER['REQUEST_URI'];
    $parseUrl = parse_url($request);
    if (!empty($parseUrl) && isset($parseUrl['query'])) {
        $query = urldecode($parseUrl['query']);
        $temp = explode('&', $query);
        if (is_array($temp) && !empty($temp)) {
            foreach ($temp as $str) {
                $t = explode('=', $str);
                if (isset($t[0], $t[1])) {
                    $retArr[$t[0]] = $t[1];
                }
            }
        }
    }
    return $retArr;
}

//不使用urlencode加密将数组转字符串(除去$except键)，区别于http_build_query()加密
function build_query($arr, $except = 'sign')
{
    $retArr = array();
    if (is_array($arr) && !empty($arr)) {
        foreach ($arr as $k => $v) {
            if ($k !== $except) $retArr[] = $k . '=' . $v;
        }
    }
    return implode('&', $retArr);
}

//键值串转数组
function query2Array($query)
{
    $retArr = array();
    if (is_string($query) && !empty($query)) {
        $temp = explode('&', $query);
        foreach ($temp as $str) {
            $t = explode('=', $str);
            if (isset($t[0], $t[1])) {
                $retArr[$t[0]] = $t[1];
            }
        }
    }
    return $retArr;
}


//###### sql的自拼接,多行变一行 (查后台名)
// SELECT CONCAT(  '\'', GROUP_CONCAT( `plat_cname` SEPARATOR  '\',\'' ) ,  '\'' ) FROM  `game_plat_info`
// Array
// (
// [3dcenter] => 103.235.222.34
// [yybtest] => 106.75.103.106
// [qdhf] => 106.75.103.106
// [yuenan] => 103.216.123.138
// [ouwan] => 106.75.103.247
// [diyibo] => 106.75.77.146
// [huiyao] => 106.75.30.176
// [9377] => 106.75.30.176
// [xiangwan] => 106.75.77.146
// [c1wan] => 106.75.103.247
// [921] => 106.75.28.46
// [duoqu] => 106.75.28.46
// [mofang] => 47.75.59.13
// [mfcenter] => 47.75.59.13
// [zsy] => 106.75.77.146
// [yncenter] => 103.216.123.138
// [youma] => 106.75.103.247
// )

//得到管理后台ip
function get_manager_db_host()
{
    $arr = $arrMap = array();
    $plat_arr = array('g1', 'h1', 'g2');
    $domain = 'manager.PLAT_NAME.ylcq.such-game.com';    // g1
    $domain2 = 'manager.PLAT_NAME.h1.such-game.com';    // h1
    $domain3 = 'manager.PLAT_NAME.g2.such-game.com';    // g2
    $plat_names = array('t1center', '3dcenter', 'yybtest', 'ttgw', 'qdhf', 'ylcqsh', 'yuenan', 'ouwan', 'diyibo', 'huiyao', '9377', 'xiangwan', 'c1wan', '921', 'duoqu', 'mofang', 'mfcenter', 'zsy', 'h1center', 'g2center', 'yncenter', 'renwant', 'huiyao', 'fengling', 'youma', 'youximao');
    foreach ($plat_names as $plat_name) {
        $plat_domain = str_replace('PLAT_NAME', $plat_name, $domain);
        // $plat_names[$plat_name] = gethostbyname($plat_domain);
        $host = gethostbyname($plat_domain);
        if (false === filter_var($host, FILTER_VALIDATE_IP)) {
            $plat_domain = str_replace('PLAT_NAME', $plat_name, $domain2);
            $host = gethostbyname($plat_domain);
            if (false === filter_var($host, FILTER_VALIDATE_IP)) {
                $plat_domain = str_replace('PLAT_NAME', $plat_name, $domain3);
                $host = gethostbyname($plat_domain);
                if (false === filter_var($host, FILTER_VALIDATE_IP)) {
                    ddd('未找到:' . $plat_domain);
                    continue;
                }
            }
        }
        $arr[$plat_name] = $host;
        $arrMap[$plat_name] = $plat_domain . "\t\t" . $host;
    }
    ddd($arrMap);
}


function discuzAuthcode($string, $operation = 'DECODE', $key = '', $expiry = 0, $len = 128)
{
    $_defuat_key = '';
    $ckey_length = 4;
    $key = md5($key ? $key : $_defuat_key);
    $keya = md5(substr($key, 0, 16));
    $keyb = md5(substr($key, 16, 16));
    $keyc = $ckey_length ? ($operation == 'DECODE' ?
        substr($string, 0, $ckey_length) : substr(md5(microtime()), -$ckey_length)) : '';
    $cryptkey = $keya . md5($keya . $keyc);
    $key_length = strlen($cryptkey);

    $string = $operation == 'DECODE'
        ? base64_decode(substr($string, $ckey_length))
        : sprintf(
            '%010d',
            $expiry ? $expiry + time() : 0
        ) . substr(md5($string . $keyb), 0, 16) . $string;

    $string_length = strlen($string);
    $result = '';
    $box = range(0, $len - 1);
    $rndkey = array();
    for ($i = 0; $i <= $len - 1; $i++) {
        $rndkey[$i] = ord($cryptkey[$i % $key_length]);
    }
    for ($j = $i = 0; $i < $len; $i++) {
        $j = ($j + $box[$i] + $rndkey[$i]) % $len;
        $tmp = $box[$i];
        $box[$i] = $box[$j];
        $box[$j] = $tmp;
    }
    for ($a = $j = $i = 0; $i < $string_length; $i++) {
        $a = ($a + 1) % $len;
        $j = ($j + $box[$a]) % $len;
        $tmp = $box[$a];
        $box[$a] = $box[$j];
        $box[$j] = $tmp;
        $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % $len]));
    }
    if ($operation == 'DECODE') {
        if ((substr($result, 0, 10) == 0 || substr($result, 0, 10) - time() > 0)
            && substr($result, 10, 16) == substr(
                md5(substr($result, 26) . $keyb),
                0,
                16
            )
        ) {
            return substr($result, 26);
        } else {
            return '';
        }
    } else {
        return $keyc . str_replace('=', '', base64_encode($result));
    }
}


/**
 * 简单对称加密算法之加密
 * 返回按顺序在64加密单字符追加一个密钥字符
 * @param String $string 需要加密的字串
 * @param String $skey 加密EKY
 *
 * @return String
 */
function Simplesec_encode($string = '', $skey = 'huosdk')
{
    $strArr = str_split(base64_encode($string));        //分隔成单个字符
    $strCount = count($strArr);
    foreach (str_split($skey) as $key => $value) {
        $key < $strCount && $strArr[$key] .= $value;
    }
    return str_replace(array('=', '+', '/'), array('O0O0O', 'o000o', 'oo00o'), join('', $strArr)
    );
}


//简单对称加密算法之解密
function Simplesec_decode($string = '', $skey = 'huosdk')
{
    $strArr = str_split(str_replace(array('O0O0O', 'o000o', 'oo00o'), array('=', '+', '/'), $string), 2);
    $strCount = count($strArr);
    foreach (str_split($skey) as $key => $value) {
        $key <= $strCount && isset($strArr[$key]) && $strArr[$key][1] === $value
        && $strArr[$key] = $strArr[$key][0];
    }
    return base64_decode(join('', $strArr));
}


function get_create_conf($game_id = 2, $plat_cname = 'mofang', $ac = 'get_game_server_list')
{

    $ac_arr = array(
        'api_get_game_all_conf',
        'get_game_server_list',
    );
    if (!in_array($ac, $ac_arr)) {
        exit('error ac:' . $ac);
    }
    $post_arr = array('game_id' => $game_id,);
    $ac == 'api_get_game_all_conf' ? $post_arr['time'] = time() : $post_arr['plat_cname'] = $plat_cname;
    $sign = 'shangqu@0903';
    ksort($post_arr);
    foreach ($post_arr as $key => $val) {
        if ($key != '' && $val != '') {
            $sign .= $key . $val;
        }
    }
    $sign = strtoupper(md5($sign));
    $source_url = "http://center.such-game.com/api/game_info.php?ac={$ac}&sign=$sign&api_data=" . urlencode(serialize($post_arr));
    echo file_get_contents($source_url);
}


//if the parameter format is wrong, it will return IPAddress_Invalid error : 6
// sdk
function encrypt($data, $key_path)
{
    $key = file_get_contents($key_path);
    $encryptedList = array();
    $step = 117;
    $encryptedData = '';
    $len = strlen($data);
    for ($i = 0; $i < $len; $i += $step) {
        $tmpData = substr($data, $i, $step);
        $encrypted = '';
        openssl_public_encrypt($tmpData, $encrypted, $key, OPENSSL_PKCS1_PADDING);
        $encryptedList[] = ($encrypted);
    }
    $encryptedData = base64_encode(join('', $encryptedList));
    return $encryptedData;
}

// 从excel换行格式得到sql_in()格式
function get_format_txt_sql($file_path = 'format.txt')
{
    $info = file_get_contents($file_path);
    $arr = explode("\r\n", $info);
    $sql_in = '(\'' . implode('\',\'', $arr) . '\')';
    file_put_contents('format.sql.txt', '[' . date('Y-m-d H:i:s') . ']' . "\r\n" . $sql_in . "\r\n");
    return $sql_in;
}


/**
 * tp 获取商品一二三级分类
 * @return type
 */
function get_goods_category_tree($cat_list = null)
{
    $tree = $arr = $result = array();
    if ($cat_list) {
        foreach ($cat_list as $val) {
            if ($val['level'] == 2) {
                $arr[$val['parent_id']][] = $val;
            }
            if ($val['level'] == 3) {
                $crr[$val['parent_id']][] = $val;
            }
            if ($val['level'] == 1) {
                $tree[] = $val;
            }
        }

        // 处理2,3级关系
        foreach ($arr as $k => $v) {
            foreach ($v as $kk => $vv) {
                $arr[$k][$kk]['sub_menu'] = empty($crr[$vv['id']]) ? array() : $crr[$vv['id']];
            }
        }

        foreach ($tree as $val) {
            $val['tmenu'] = empty($arr[$val['id']]) ? array() : $arr[$val['id']];
            $result[$val['id']] = $val;
        }
    }
    return $result;
}

/**
 * 传入当前分类 如果当前是 2级 找一级
 * 如果当前是 3级 找2 级 和 一级
 * @param  $goodsCate
 */
function get_goods_cate(&$goodsCate)
{
    if (empty($goodsCate)) return array();
    $cateAll = get_goods_category_tree();
    if ($goodsCate['level'] == 1) {
        $cateArr = $cateAll[$goodsCate['id']]['tmenu'];
        $goodsCate['parent_name'] = $goodsCate['name'];
        $goodsCate['select_id'] = 0;
    } elseif ($goodsCate['level'] == 2) {
        $cateArr = $cateAll[$goodsCate['parent_id']]['tmenu'];
        $goodsCate['parent_name'] = $cateAll[$goodsCate['parent_id']]['name'];//顶级分类名称
        $goodsCate['open_id'] = $goodsCate['id'];//默认展开分类
        $goodsCate['select_id'] = 0;
    } else {
        //3级找2级
        $parent = M('GoodsCategory')->where("id", $goodsCate['parent_id'])->order('`sort_order` desc')->find();//父类
        $cateArr = $cateAll[$parent['parent_id']]['tmenu'];
        $goodsCate['parent_name'] = $cateAll[$parent['parent_id']]['name'];//顶级分类名称
        $goodsCate['open_id'] = $parent['id'];
        $goodsCate['select_id'] = $goodsCate['id'];//默认选中分类
    }
    return $cateArr;
}


//获取天拓数据签名
function get_tt_sign($data, $key)
{
    ksort($data);
    $str = '';
    foreach ($data as $k => $v) {
        if ($k == 'sign' || $k == 'sign_type' || $v === '') {
            continue;
        }
        $str .= $k . '=' . urlencode($v) . '&';
    }
    $str = rtrim($str, '&');
    if (get_magic_quotes_gpc()) {
        $str = stripslashes($str);
    }
    return md5($str . $key);
}

//获取天拓海外数据签名
function get_tt_abroad_sign($data, $key)
{
    ksort($data);
    $str = '';
    foreach ($data as $k => $v) {
        if ($k == 'sign' || $v === '') continue;
        $str .= $k . '=' . urldecode($v) . '&';
    }
    if (get_magic_quotes_gpc()) {
        $str = stripslashes($str);
    }
    return md5($str . $key);
}

function get_renwan_sign(array $params, $secret)
{
    $data = [];
    ksort($params);
    foreach ($params as $k => $v) {
        if ($k == 'sign') continue;
        $data[] = $k . '=' . $v;
    }
    $signString = implode('&', $data);
    return md5(md5($signString) . $secret);
}

function getHead($sUrl, $data)
{
    $ch = curl_init();
    // 设置请求头, 有时候需要,有时候不用,看请求网址是否有对应的要求
    $header[] = "Content-type: application/x-www-form-urlencoded";
    $user_agent = "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/33.0.1750.146 Safari/537.36";
    curl_setopt($ch, CURLOPT_URL, $sUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    // 返回 response_header, 该选项非常重要,如果不为 true, 只会获得响应的正文
    curl_setopt($ch, CURLOPT_HEADER, true);
    // 是否不需要响应的正文,为了节省带宽及时间,在只需要响应头的情况下可以不要正文
    curl_setopt($ch, CURLOPT_NOBODY, true);
    // 使用上面定义的 ua
    curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    // 不用 POST 方式请求, 意思就是通过 GET 请求
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $sContent = curl_exec($ch);
    // 获得响应结果里的：头大小
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    // 根据头大小去获取头信息内容
    $header = substr($sContent, 0, $headerSize);
    curl_close($ch);
    return $header;
}

/**
 * 使用curl进行GET请求 （支持https）
 * @param $url
 * @param int $timeout
 * @param array $header
 * @return bool|mixed
 */
function curlGet($url, $timeout = 10, $header = array(), $cookie = '')
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    if (!empty($header)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    }
    $https = substr($url, 0, 8) == "https://" ? true : false;
    if ($https) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }
    if (!empty($cookie)) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($res !== false && $status >= 400) {
        $res = false;
    }
    curl_close($ch);
    return $res;
}

/**
 * 使用curl进行POST请求 （支持https）
 * json头 :
 * $header = array(
 * 'Content-Type: application/json; charset=utf-8',
 * 'Content-Length: ' . strlen($json)
 * );
 * urlencoded头
 * $header = array('Content-Type: application/x-www-form-urlencoded; charset=utf-8',);
 * @param $url
 * @param array $data
 * @param int $timeout
 * @param array $header
 * @return bool|mixed
 */
function curlPost($url, $data = array(), $timeout = 10, $header = array(), $cookie = "")
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    if (!empty($header)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    }
    $https = substr($url, 0, 8) == "https://" ? true : false;
    if ($https) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }
    if (!empty($cookie)) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($res !== false && $status >= 400) {
        $res = false;
    }
    curl_close($ch);
    return $res;
}

//检查初始化状态: plat_id=>array(server_id,)
function check_init_server()
{
    $plat_server_arr = array("72" => array("26057", "26060"));
    $game_id = 2;
    $server_host = 'center.such-game.com';
    $plat_server = serialize($plat_server_arr);
    $token = md5('Ln18HqPq5V4oAtgL' . $game_id . $plat_server);
    $plat_server = urlencode($plat_server);
    //curl "http://center.such-game.com/control/server_run_manager.php?ac=check_init_server&game_id=2&plat_server=a%3A1%3A%7Bi%3A72%3Ba%3A2%3A%7Bi%3A0%3Bs%3A5%3A%2226057%22%3Bi%3A1%3Bs%3A5%3A%2226060%22%3B%7D%7D&token=f6dac2b05f272607be5f00b939c65885";
    $remote_cmd = "http://{$server_host}/control/server_run_manager.php?ac=check_init_server&game_id={$game_id}&plat_server={$plat_server}&token={$token}";
    ddd($remote_cmd);
    $ret = file_get_contents($remote_cmd);
    ddd($ret);
}


function mobile_test($game_id = '2', $plat_cname = 'xiangwan2', $ditch_name = 'xiangw2', $res_version = '1000', $sdk_version = 'ios_v22')
{
    $pattern = 'http://mobileclient.PLAT_CNAME.GAME_NAME.such-game.com/mobile_config_api/mobile_config_3d.php?ditch_name=DITCH_NAME&os_type=android&local_res_version=RES_VERSION&level=40&sdk_version=SDK_VERSION&channel_id=';
    switch ($game_id) {
        case '2' :
            $game_name = 'ylcq';
            break;
        case '3' :
            $game_name = 'xmj';
            break;
        case '4' :
            $game_name = 'xyz';
            break;
        case '5' :
            $game_name = 'zjz';
            break;
        default :
            ;
    }
    $mobile_url = str_replace(
        array('PLAT_CNAME', 'GAME_NAME', 'DITCH_NAME', 'RES_VERSION', 'SDK_VERSION'),
        array($plat_cname, $game_name, $ditch_name, $res_version, $sdk_version),
        $pattern
    );

    ddd($mobile_url);
    print(file_get_contents($mobile_url));
}

//生成随机字母以及数字字符
function get_random_str($length = 32)
{
    $ret = '';
    $str = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz";
    $max = strlen($str) - 1;
    for ($i = 0; $i < $length; $i++) {
        $ret .= $str[mt_rand(0, $max)];
    }
    return $ret;
}

//url base64编码
function urlsafe_b64encode($string)
{
    $data = base64_encode($string);
    $data = str_replace(array('+', '/', '='), array('-', '_', ''), $data);
    return $data;
}

//url base64解码
function urlsafe_b64decode($string)
{
    $data = str_replace(array('-', '_'), array('+', '/'), $string);
    $mod4 = strlen($data) % 4;
    if ($mod4) {
        $data .= substr('====', $mod4);
    }
    return base64_decode($data);
}


function getHourMinuteBySecond($second)
{
    $count = (int)($second / 60);
    $h = (int)($count / 60);
    $m = (int)($count % 60);
    return $h . ':' . $m;
}


/**
 * 获取随机字符串
 * @param integer $length 要多少位
 * @param integer $numeric 是否只要数字
 * @return string 随机产生的字符串
 */
function getRandom($length, $numeric = 0)
{
    //base_convert(number,frombase,tobase); 函数在任意进制之间转换数字。tobase	必需。规定要转换的进制。介于 2 和 36 之间（包括 2 和 36）。高于十进制的数字用字母 a-z 表示，例如 a 表示 10，b 表示 11 以及 z 表示 35。
    $seed = base_convert(md5(microtime()), 16, $numeric ? 10 : 35);
    $seed = $numeric ? (str_replace('0', '', $seed) . '012340567890') : ($seed . 'zZ' . strtoupper($seed));
    $hash = '';
    $max = strlen($seed) - 1;
    for ($index = 0; $index < $length; $index++) {
        $i = mt_rand(0, $max);
        $hash .= $seed[$i];
    }
    return $hash;
}


function requireParamToJson($requireParam)
{
    //复制输出
    foreach ($requireParam as $param) {
        $arr[$param] = "";
    }
    exit(json_encode($arr));
}


function refreshToken()
{
    $appid = 'wx479f23c0d6247099';
    $url = 'http://service-mp.xcxzhan.com/v4/token/update?appid=%s';
    $data = ['appid' => $appid];
    $resp = curlPost($url, $data);
    ddd($resp);
}


//生成主题回调的sign，timestamp测试get参数，验证参数拼在url,业务参数POST方式
//发送手机验证码验证生成
function getCallbackQuerySign($param = [])
{
    //请使用正确的 API_KEY
    $platform = isset($param['platform']) ? $param['platform'] : '';
    if ($platform == 'android') {
        $apiKey = '';
    } else if ($platform == 'ios') {
        $apiKey = '';
    } else if ($platform == 'web') {
        $apiKey = 'c3a39e4eeacf4542d6a488e19037fa45';
    } else if ($platform == 'pc') {
        $apiKey = '';
    } else if ($platform == 'ibos') {
        $apiKey = '';
    } else {
        $apiKey = 'f1027a17a24df7f245e9cbd7dc9c7205';
    }

    //获取一年不过期的时间戳 (原本36000=10小时才过期)
    $param['timestamp'] = time() + 86400 * 365 - 36000;
    //排序
    ksort($param);
    reset($param);
    //把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
    $arg = "";
    while (list ($key, $val) = each($param)) {
        //去掉空值与签名参数后的新签名参数组
        if ($key == 'sign' || $key == 'method' || $val == "") {
            continue;
        }
        $arg .= $key . "=" . $val . "&";
    }
    //去掉最后一个&字符
    $arg = rtrim($arg, '&');
    //如果存在转义字符，那么去掉转义
    if (get_magic_quotes_gpc()) {
        $arg = stripslashes($arg);
    }
    //生成sign
    $prestr = $arg . $apiKey;
    $param['sign'] = md5($prestr);
    return http_build_query($param);
}

//源
function isSignValid()
{
    $params = yii::$app->request->getQueryParams();
    if (!empty($params) && isset($params['sign']) && isset($params['timestamp']) && isset($params['platform'])) {
        if (NOW - $params['timestamp'] > 36000) {
            return false;
        }
        if ($params['platform'] == 'android') {
            $key = Env::ANDROID_KEY;
        } else if ($params['platform'] == 'ios') {
            $key = Env::IOS_KEY;
        } else if ($params['platform'] == 'web') {
            $key = Env::WEB_KEY;
        } else if ($params['platform'] == 'pc') {
            $key = Env::PC_KEY;
        } else if ($params['platform'] == 'ibos') {
            $key = Env::IBOS_KEY;
        } else {
            $key = Env::API_KEY;
        }
        $signMethod = new SignMethod();
        $signMethod->setAuthKey($key);
        return $signMethod->verify($params);
    } else {
        return false;
    }
}

//异步请求
function sendRequestAsync($url, $post_data = array(), $cookie = array())
{
    $url_arr = parse_url($url);
    $port = isset($url_arr['port']) ? $url_arr['port'] : 80;
    if ($url_arr['scheme'] == 'https') {
        $url_arr['host'] = 'ssl://' . $url_arr['host'];
    }
    $fp = fsockopen($url_arr['host'], $port, $errno, $errstr, 30);
    if (!$fp) return false;
    $getPath = isset($url_arr['path']) ? $url_arr['path'] : '/index.php';
    $getPath .= isset($url_arr['query']) ? '?' . $url_arr['query'] : '';
    $method = 'GET';  //默认get方式
    if (!empty($post_data)) $method = 'POST';
    $header = "$method  $getPath  HTTP/1.1\r\n";
    $header .= "Host: " . $url_arr['host'] . "\r\n";
    if (!empty($cookie)) {  //传递cookie信息
        $_cookie = strval(NULL);
        foreach ($cookie AS $k => $v) {
            $_cookie .= $k . "=" . $v . ";";
        }
        $cookie_str = "Cookie:" . base64_encode($_cookie) . "\r\n";
        $header .= $cookie_str;
    }
    if (!empty($post_data)) {  //传递post数据
        $_post = array();
        foreach ($post_data AS $_k => $_v) {
            $_post[] = $_k . "=" . urlencode($_v);
        }
        $_post = implode('&', $_post);
        $post_str = "Content-Type:application/x-www-form-urlencoded; charset=UTF-8\r\n";
        $post_str .= "Content-Length: " . strlen($_post) . "\r\n";  //数据长度
        $post_str .= "Connection:Close\r\n\r\n";
        $post_str .= $_post;  //传递post数据
        $header .= $post_str;
    } else {
        $header .= "Connection:Close\r\n\r\n";
    }
    fwrite($fp, $header);
    //echo fread($fp,1024);
    usleep(1000); // 这一句也是关键，如果没有这延时，可能在nginx服务器上就无法执行成功
    fclose($fp);
    return true;
}


/**
 * 微信文本检测违规 token 在wxtoken找
 */
function wxMsgSecCheck($msg, $token)
{
    $url = sprintf('https://api.weixin.qq.com/wxa/msg_sec_check?access_token=%s', $token);
    $content = preg_replace('/\w+|[":,\/\\\[\]\{\}\-\.\:]/', '', json_encode($msg, JSON_UNESCAPED_UNICODE));
    $data = '{"content":"' . $content . '"}';
    ddd($data);
    $result = curlPost($url, $data, 'post');
    echo $result;
}

function getStringValueByArray($array)
{
    static $string = '';
    if (is_string($array)) {
        $string .= $array;
    } else if (is_array($array)) {
        foreach ($array as $value) {
            getStringValueByArray($value);
        }
    }
    return $string;
}


function decodeContent($content)
{
    //stripslashes() 函数删除由 addslashes() 函数添加的反斜杠。
    //Model::getAllowField();添加数据使用的函数
    ddd(json_decode(stripslashes($content), true));
}

/**
 * php命令行确认执行
 * if ($this->confirm('aaaa')) {
 * //TODO...
 * }
 */
function confirm($message = "")
{
    while (true) {
        if (!empty($message)) {
            fwrite(STDOUT, $message . "\n");
        }
        fwrite(STDOUT, "继续执行[yes/no]：");
        $input = trim(fgets(STDIN));
        if (strtolower($input) === 'yes') {
            return true;
        } else if (strtolower($input) === 'no') {
            return false;
        }
    }
    return false;
}


function flushMsg()
{
    //php 默认配置缓存4996bytes，当缓存达到时自动执行 ob_flush();ob_flush()是把当前缓存空间输出到上级缓存空间
    //ob_start()这个函数，这个函数的作用就是开启一个新的php缓存
    ob_start();
    echo '
	<script>
	function scrollBottom(){
		var h = document.documentElement.scrollHeight || document.body.scrollHeight;
		window.scrollTo(h,h);
	}
	</script>
	';
    ob_flush();

    for ($i = 1; $i < 1000; $i++) {
        //加上外部请求，不能及时输出缓存??
        //$a = file_get_contents('https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=APPID&secret=APPSECRET');
        echo $i . '<br>';
        echo str_repeat(" ", 4096);
        echo '<script>scrollBottom();</script>';
        ob_flush();
    }
}


/**
 * 根据预设宽度让文字自动换行
 * @param int $fontsize 字体大小
 * @param string $ttfpath 字体名称
 * @param string $str 字符串
 * @param int $width 预设宽度
 * @param int $fontangle 角度
 * @param string $charset 编码
 * @return string $_string  字符串
 */
function autoWrap($fontsize, $ttfpath, $str, $width, $fontangle = 0, $charset = 'utf-8')
{
    $_string = "";
    $_width = 0;
    $temp = chararray($str, $charset);
    foreach ($temp[0] as $v) {
        $w = charWidth($fontsize, $fontangle, $v, $ttfpath);
        $_width += intval($w);
        if (($_width > $width) && ($v !== "")) {
            $_string .= PHP_EOL;
            $_width = 0;
        }
        $_string .= $v;
    }
    return $_string;
}

/**
 * 返回一个字符的数组
 *
 * @param string $str 文字
 * @param string $charset 字符编码
 * @return array $match   返回一个字符的数组
 */
function charArray($str, $charset = "utf-8")
{
    $re['utf-8'] = "/[\x01-\x7f]|[\xc2-\xdf][\x80-\xbf]|[\xe0-\xef][\x80-\xbf]{2}|[\xf0-\xff][\x80-\xbf]{3}/";
    $re['gb2312'] = "/[\x01-\x7f]|[\xb0-\xf7][\xa0-\xfe]/";
    $re['gbk'] = "/[\x01-\x7f]|[\x81-\xfe][\x40-\xfe]/";
    $re['big5'] = "/[\x01-\x7f]|[\x81-\xfe]([\x40-\x7e]|\xa1-\xfe])/";
    preg_match_all($re[$charset], $str, $match);
    return $match;
}


/**
 * 返回一个字符串在图片中所占的宽度
 * @param int $fontsize 字体大小
 * @param int $fontangle 角度
 * @param string $ttfpath 字体文件
 * @param string $char 字符
 * @return int $width
 */
function charWidth($fontsize, $fontangle, $char, $ttfpath)
{
    $box = @imagettfbbox($fontsize, $fontangle, $ttfpath, $char);
    $width = max($box[2], $box[4]) - min($box[0], $box[6]);
    return $width;
}


function checkAuditStatus($str)
{
    $arr = explode("\n", $str);
    foreach ($arr as $appid) {
        echo $appid, "\n";
        $url = 'https://api.ibos.cn/callback/site/checkauditstatus?timestamp=1579213394&sign=d86fc574b200a37530979e2344f22ac1';
        $json = json_encode(['appid' => $appid]);
        $header = array(
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen($json)
        );
        $resp = curlPost($url, $json, 10, $header);
        echo $resp, "\n";
    }
}

function getShareStr($param = [])
{
    if (empty($param)) {
        $param = [
            'cid' => '279798',
            'app' => 'site',
            'appid' => '',
            'fromuid' => '',
        ];
    }
    $paramStr = base64_encode(urlencode(json_encode($param, JSON_UNESCAPED_UNICODE)));
    return $paramStr;
}


function getHeaderToken()
{
    // $key = '46c71b66d226e3842682b3f5f69296e4';
    // $data['platform'] = 'pc';
    $key = 'c3a39e4eeacf4542d6a488e19037fa45';
    $data['createtime'] = time() + 86400 * 365 - 36000;
    $data['platform'] = 'web';
    $data['token'] = md5($key . $data['createtime']);
    return $data;
}


/**
 * 配置对象属性
 * Configures an object with the initial property values.
 * @param object $object the object to be configured
 * @param array $properties the property initial values given in terms of name-value pairs.
 * @return object the object itself
 */
function configure($object, $properties)
{
    foreach ($properties as $name => $value) {
        $object->$name = $value;
    }
    return $object;
}

/**
 *  获取请求头信息
 * @return array|false
 */
function getHeaders()
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } elseif (function_exists('http_get_request_headers')) {
        $headers = http_get_request_headers();
    } else {
        foreach ($_SERVER as $name => $value) {
            if (strncmp($name, 'HTTP_', 5) === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$name] = $value;
            }
        }
    }
    return $headers;
}

// 对象public属性转为数组
trait Object2Arr
{
    /**
     * 返回当前对象 public 类型的属性
     *
     * @return array
     */
    public function toArray()
    {
        $object = new ReflectionObject($this);
        /** @var ReflectionProperty[] $propertys */
        $propertys = $object->getProperties(ReflectionProperty::IS_PUBLIC);

        $arr = [];
        foreach ($propertys as $property) {
            $propertyName = $property->getName();
            $arr[$propertyName] = $this->{$propertyName};
        }

        return $arr;
    }
}


function xmlToArray($xml)
{
    $arr = json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA));
    return $arr;
}


//字符串转Unicode编码
function unicodeEncode($strLong)
{
    $strArr = preg_split('/(?<!^)(?!$)/u', $strLong);//拆分字符串为数组(含中文字符)
    $resUnicode = '';
    foreach ($strArr as $str) {
        $bin_str = '';
        $arr = is_array($str) ? $str : str_split($str);//获取字符内部数组表示,此时$arr应类似array(228, 189, 160)
        foreach ($arr as $value) {
            $bin_str .= decbin(ord($value));//转成数字再转成二进制字符串,$bin_str应类似111001001011110110100000,如果是汉字"你"
        }
        $bin_str = preg_replace('/^.{4}(.{4}).{2}(.{6}).{2}(.{6})$/', '$1$2$3', $bin_str);//正则截取, $bin_str应类似0100111101100000,如果是汉字"你"
        $unicode = dechex(bindec($bin_str));//返回unicode十六进制
        $_sup = '';
        for ($i = 0; $i < 4 - strlen($unicode); $i++) {
            $_sup .= '0';//补位高字节 0
        }
        $str = '\\u' . $_sup . $unicode; //加上 \u  返回
        $resUnicode .= $str;
    }
    return $resUnicode;
}

//Unicode编码转字符串方法1
function unicodDecode($name)
{
    // 转换编码，将Unicode编码转换成可以浏览的utf-8编码
    $pattern = '/([\w]+)|(\\\u([\w]{4}))/i';
    preg_match_all($pattern, $name, $matches);
    if (!empty($matches)) {
        $name = '';
        for ($j = 0; $j < count($matches[0]); $j++) {
            $str = $matches[0][$j];
            if (strpos($str, '\\u') === 0) {
                $code = base_convert(substr($str, 2, 2), 16, 10);
                $code2 = base_convert(substr($str, 4), 16, 10);
                $c = chr($code) . chr($code2);
                $c = iconv('UCS-2', 'UTF-8', $c);
                $name .= $c;
            } else {
                $name .= $str;
            }
        }
    }
    return $name;
}

//Unicode编码转字符串
function unicodeDecode2($str)
{
    $json = '{"str":"' . $str . '"}';
    $arr = json_decode($json, true);
    if (empty($arr)) return '';
    return $arr['str'];
}


/**
 * 将字符串分割为数组，包括中文
 */
function mbstrSplit($str)
{
    return preg_split('/(?<!^)(?!$)/u', $str);
}


/**
 * 手机号或电话加掩码
 */
function mobileMask($mobile, $maskNum = 6)
{
    $length = strlen($mobile);
    $startLength = ceil(($length - $maskNum) / 2);
    return !empty($mobile) ? substr($mobile, 0, $startLength) . str_repeat('*', $maskNum) . substr($mobile, -($length - $maskNum - $startLength)) : '';
}


function windowLogin()
{
    header("Content-type: text/html; charset=utf-8");
    if (!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        http_response_code(401);
        header('WWW-Authenticate:Basic realm="验证权限"');
        echo '需要用户名和密码才能继续访问';
        exit;
    }
    print_r($_SERVER);
}


/**
 * 获取数组中某个相同键的所有值，以相同键为索引组成新数组
 * @param $array
 * @param string $field 查找相同键
 * @param string $column 获取数据列，空为整个数组信息
 * @param bool $isMultiple 是否多个
 * @return array
 */
function getColumnGroupByField($array, $field, $column = '', $isMultiple = false)
{
    $data = [];
    foreach ($array as $item) {
        $k = $item[$field];
        if (!isset($data[$k])) {
            $data[$k] = [];
        }
        $datum = !empty($column) ? $item[$column] : $item;
        if ($isMultiple) {
            $data[$k][] = $datum;
        } else {
            $data[$k] = $item;
        }
    }
    arsort($data);
    return $data;
}

/**
 * 发送异步请求，socket方式 (php.ini开启allow_url_fopen=on)
 * @param $url string 请求地址，不包含http(s)信息
 * @param mixed $data get或post请求数据
 * @param string $method 请求方式 GET|POST
 * @param bool $isGetResponse true转换为同步方式，获取响应数据
 * @return bool|string 异步返回true，同步则返回带header,body的响应信息（可通过\r\n\r\n分割获取到body）
 * @throws Exception
 */
function asyncRequest($url, $data = [], $method = 'GET', $isGetResponse = false)
{
    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST'])) {
        throw new Exception('Invalid Method:' . $method);
    }
    $target = parse_url($url);
    $port = isset($target['port']) ? $target['port'] : 80;
    if (!$fp = @fsockopen($target['host'], $port, $errNo, $errStr, 5)) {
        throw new Exception('Fail To Connect ' . $url . ": $errStr [$errNo]");
    }
    stream_set_blocking($fp, false);
    $path = isset($target['path']) ? $target['path'] : $url;
    if (!empty($target['query'])) {
        $path .= '?' . $target['query'];
    }
    if (is_array($data)) {
        $data = http_build_query($data);
    }
    if ($method == 'POST') {
        fputs($fp, "{$method} " . $path . " HTTP/1.0\r\n");
        fputs($fp, "Content-type: application/x-www-form-urlencoded\r\n");
        fputs($fp, 'Content-length: ' . strlen($data) . "\r\n");
        fputs($fp, "Connection: close\r\n\r\n");
        fputs($fp, $data);
    } else {
        if (!empty($data)) {
            $path .= (strpos($path, '?') !== false) ? '&' . $data : '?' . $data;
        }
        fputs($fp, "{$method} " . $path . " HTTP/1.0\r\n");
        fputs($fp, "Connection: close\r\n\r\n");
    }
    $responseData = true;
    if ($isGetResponse) {
        $responseData = '';
        while (!feof($fp)) {
            $responseData .= fgets($fp, 1024);
        }
    }
    @fclose($fp);
    return $responseData;
}

//获取当前毫秒时间戳
function getMillisecond()
{
    return intval(array_sum(array_reverse(explode(' ', microtime()))) * 1000);
}