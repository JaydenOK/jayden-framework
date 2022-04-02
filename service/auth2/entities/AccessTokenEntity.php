<?php


namespace app\service\auth2\entities;


use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

class AccessTokenEntity implements AccessTokenEntityInterface
{
    use AccessTokenTrait, TokenEntityTrait, EntityTrait;

    /**
     * AccessTokenTrait有问题__toString()
     * __toString() 不能有异常
     * Generate a string representation from the access token
     */
    public function __toString()
    {
        $tokenString = $this->getIdentifier();
        return $tokenString;
    }
}