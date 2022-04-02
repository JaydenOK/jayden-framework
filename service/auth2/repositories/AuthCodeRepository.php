<?php


namespace app\service\auth2\repositories;

use app\service\auth2\entities\AuthCodeEntity;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    /**
     * @return AuthCodeEntity|AuthCodeEntityInterface
     */
    public function getNewAuthCode()
    {
        // 创建新授权码时调用方法
        // 需要返回 AuthCodeEntityInterface 对象
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity)
    {
        // 创建新授权码时调用此方法
        // 可以用于持久化存储授权码，持久化数据库自行选择
        // 可以使用参数中的 AuthCodeEntityInterface 对象，获得有价值的信息：
//         $authCodeEntity->getIdentifier(); // 获得授权码唯一标识符
//         $authCodeEntity->getExpiryDateTime(); // 获得授权码过期时间
//         $authCodeEntity->getUserIdentifier(); // 获得用户标识符
//         $authCodeEntity->getScopes(); // 获得权限范围
//         $authCodeEntity->getClient()->getIdentifier(); // 获得客户端标识符

        //@todo 持久化数据库自行选择
        $codeTable = 'auth2_code_log';
        $data = [
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'lgid' => $authCodeEntity->getUserIdentifier(),
            'code' => $authCodeEntity->getIdentifier(),
            'scopes' => $authCodeEntity->getScopes(),
            'expire_time' => $authCodeEntity->getExpiryDateTime(),
        ];
        $key = "{$data['client_id']}:{$data['lgid']}";
        MysqlRepository::set($codeTable, $key, $data);
    }

    public function revokeAuthCode($codeId)
    {
        // 当使用授权码获取访问令牌时调用此方法
        // 可以在此时将授权码从持久化数据库中删除
        // 参数为授权码唯一标识符
    }

    public function isAuthCodeRevoked($codeId)
    {
        // 当使用授权码获取访问令牌时调用此方法
        // 用于验证授权码是否已被删除
        // return true 已删除，false 未删除

        return false;
    }

}