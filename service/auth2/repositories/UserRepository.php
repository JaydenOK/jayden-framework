<?php


namespace app\service\auth2\repositories;


use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;

class UserRepository implements UserRepositoryInterface
{
    /**
     * @param string $username
     * @param string $password
     * @param string $grantType
     * @param ClientEntityInterface $clientEntity
     * @return UserEntity|\League\OAuth2\Server\Entities\UserEntityInterface|null
     */
    public function getUserEntityByUserCredentials($username, $password, $grantType, ClientEntityInterface $clientEntity)
    {
        // 验证用户时调用此方法
        // 用于验证用户信息是否符合
        // 可以验证是否为用户可使用的授权类型($grantType)与客户端($clientEntity)
        // 验证成功返回 UserEntityInterface 对象
        $user = new UserEntity();
        $user->setIdentifier(1);

        return $user;
    }
}