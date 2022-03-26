<?php


namespace app\service\auth2\repositories;

//Mysql存储
class MysqlRepository
{
    //模拟mysql存储的第三方应用查询配置信息
    public function getClientConfig(string $clientId)
    {
        //查询返回
        if ($clientId && $clientId == 'clientId123') {
            return [
                'id' => 1001,
                'client_id' => $clientId,
                'redirect_uri' => 'http://jayden.cc?r=api/Auth2Client/authRedirect',
                //'redirect_uri' => 'http://jayden.cc?r=api/Auth2Server/loginView',
                'client_secret' => '',
            ];
        }
        return null;
    }

    public static function saveAuthRequest($authRequest)
    {
        $dir = APP_ROOT . DS . 'logs' . DS . 'auth2';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . DS . 'authRequest.txt';
        if (!is_dir($file)) {
            touch($file);
        }
        $json = file_get_contents($file);
        $arr = @(array)json_decode($json, true);
        //当前授权的id
        $id = uniqid();
        if (!isset($arr[$id])) {
            $data[$id] = serialize($authRequest);
            $res = file_put_contents($file, json_encode($data));
        }
        return $id;
    }

}