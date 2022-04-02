<?php


namespace app\service\auth2\repositories;

use app\service\auth2\entities\AccessTokenEntity;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    /**
     * @param ClientEntityInterface $clientEntity
     * @param array $scopes
     * @param null $userIdentifier
     * @return AccessTokenEntity|AccessTokenEntityInterface
     */
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null)
    {
        // 创建新访问令牌时调用方法
        // 需要返回 AccessTokenEntityInterface 对象
        // 需要在返回前，向 AccessTokenEntity 传入参数中对应属性
        // 示例代码：
        $accessToken = new AccessTokenEntity();
        $accessToken->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $accessToken->addScope($scope);
        }
        $accessToken->setUserIdentifier($userIdentifier);

        return $accessToken;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity)
    {
        // 创建新访问令牌时调用此方法
        // 可以用于持久化存储访问令牌，持久化数据库自行选择
        // 可以使用参数中的 AccessTokenEntityInterface 对象，获得有价值的信息：
        $accessToken = $accessTokenEntity->getIdentifier(); // 获得令牌唯一标识符
        $expireTime = $accessTokenEntity->getExpiryDateTime(); // 获得令牌过期时间
        $userId = $accessTokenEntity->getUserIdentifier(); // 获得用户标识符
        $scope = $accessTokenEntity->getScopes(); // 获得权限范围
        $clientId = $accessTokenEntity->getClient()->getIdentifier(); // 获得客户端标识符

        $table = 'access_token_log';
        $key = "{$clientId}:{$userId}";
        $data = [
            'client_id' => $clientId,
            'user_id' => $userId,
            'access_token' => $accessToken,
            'expire_time' => $expireTime,
            'scope' => $scope,
        ];
        MysqlRepository::set($table, $key, $data);
    }

    public function revokeAccessToken($tokenId)
    {
        // 使用刷新令牌创建新的访问令牌时调用此方法
        // 参数为原访问令牌的唯一标识符
        // 可将其在持久化存储中过期
    }

    public function isAccessTokenRevoked($tokenId)
    {
        // 资源服务器验证访问令牌时将调用此方法
        // 用于验证访问令牌是否已被删除
        // return true 已删除，false 未删除
        return false;
    }


}