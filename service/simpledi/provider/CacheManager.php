<?php

namespace app\service\simpledi\provider;

use Cekta\DI\ProviderExceptionInterface;
use Cekta\DI\ProviderInterface;

class CacheManager implements ProviderInterface
{
    /**
     * @param string $id
     * @return mixed
     * @throws ProviderExceptionInterface
     */
    public function provide(string $id)
    {
        // TODO: Implement provide() method.
        return new Redis();
    }

    public function canProvide(string $id): bool
    {
        // TODO: Implement canProvide() method.
        return true;
    }
}