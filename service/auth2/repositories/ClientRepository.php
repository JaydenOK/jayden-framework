<?php


namespace app\service\auth2\repositories;

use app\service\auth2\entities\ClientEntity;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

class ClientRepository implements ClientRepositoryInterface
{
    /**
     * @param string $clientIdentifier 第三方应用客户端id
     * @param null $grantType 授权类型
     * @param null $clientSecret 第三方应用客户端秘钥
     * @param bool $mustValidateSecret 是否必须验证秘钥
     * @return ClientEntity|\League\OAuth2\Server\Entities\ClientEntityInterface|null
     */
    public function getClientEntity($clientIdentifier, $grantType = null, $clientSecret = null, $mustValidateSecret = true)
    {
        // 获取客户端对象时调用方法，用于验证客户端
        // 需要返回 ClientEntityInterface 对象
        // $clientIdentifier 客户端唯一标识符
        // $grantType 代表授权类型，根据类型不同，验证方式也不同
        // $clientSecret 代表客户端密钥，是客户端事先在授权服务器中注册时得到的
        // $mustValidateSecret 代表是否需要验证客户端密钥
        $client = new ClientEntity();
        $client->setIdentifier($clientIdentifier);
        //从存储系统获取第三方应用配置，信息注入到ClientEntity实体
        $mysql = new MysqlRepository();
        $clientData = $mysql->getClientConfig($clientIdentifier);
        if ($clientData === null) {
            return null;
        }
        //重定向用户到登录页地址
//        $redirectUri = '';
        $client->setRedirectUri($clientData['redirect_uri']);
        return $client;
    }

    /**
     * Validate a client's secret.
     *
     * @param string $clientIdentifier The client's identifier
     * @param null|string $clientSecret The client's secret (if sent)
     * @param null|string $grantType The type of grant the client is using (if sent)
     *
     * @return bool
     */
    public function validateClient($clientIdentifier, $clientSecret, $grantType)
    {
        // TODO: Implement validateClient() method.
    }

}