<?php


namespace app\service\auth2\repositories;

//Mysql存储
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;

class MysqlRepository
{

    //模拟mysql存储的第三方应用配置信息
    public static function set($table, $key, $value)
    {
        $tableFile = self::initTable($table);
        $json = file_get_contents($tableFile);
        $lists = json_decode($json, true);
        $lists[$key] = $value;
        $json = json_encode($lists, JSON_UNESCAPED_UNICODE);
        return file_put_contents($tableFile, $json);
    }

    public static function get($table, $key)
    {
        $tableFile = self::initTable($table);
        $json = file_get_contents($tableFile);
        $lists = json_decode($json, true);
        return isset($lists[$key]) ? $lists[$key] : null;
    }

    private static function initTable($tableName)
    {
        $dir = APP_ROOT . DS . 'logs' . DS . 'auth2';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . DS . $tableName . '.txt';
        if (!file_exists($file)) {
            touch($file);
        }
        return $file;
    }

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

    public static function saveAuthRequest(AuthorizationRequest $authRequest)
    {
        $table = 'authRequest';
        $id = $authRequest->getUser()->getIdentifier();
        self::set($table, $id, serialize($authRequest));
        return $id;
    }


    /**
     * @param $id
     * @return AuthorizationRequest|null
     */
    public static function getAuthRequest($id)
    {
        $table = 'authRequest';
        $value = self::get($table, $id);
        return $value ? unserialize($value) : null;
    }

}