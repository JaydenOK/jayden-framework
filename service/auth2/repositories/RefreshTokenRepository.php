<?php


namespace app\service\auth2\repositories;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    /**
     * @return RefreshTokenEntity|RefreshTokenEntityInterface|null
     */
    public function getNewRefreshToken()
    {
        // 创建新授权码时调用方法
        // 需要返回 RefreshTokenEntityInterface 对象
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity)
    {
        // 创建新刷新令牌时调用此方法
        // 用于持久化存储授刷新令牌
        // 可以使用参数中的 RefreshTokenEntityInterface 对象，获得有价值的信息：
        // $refreshTokenEntity->getIdentifier(); // 获得刷新令牌唯一标识符
        // $refreshTokenEntity->getExpiryDateTime(); // 获得刷新令牌过期时间
        // $refreshTokenEntity->getAccessToken()->getIdentifier(); // 获得访问令牌标识符
    }

    public function revokeRefreshToken($tokenId)
    {
        // 当使用刷新令牌获取访问令牌时调用此方法
        // 原刷新令牌将删除，创建新的刷新令牌
        // 参数为原刷新令牌唯一标识
        // 可在此删除原刷新令牌
    }

    public function isRefreshTokenRevoked($tokenId)
    {
        // 当使用刷新令牌获取访问令牌时调用此方法
        // 用于验证刷新令牌是否已被删除
        // return true 已删除，false 未删除
        return false;
    }


}