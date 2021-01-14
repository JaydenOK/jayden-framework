<?php

namespace app\service\simpledi;

use Cekta\DI\ProviderExceptionInterface;
use Cekta\DI\ProviderInterface;

class Redis
{

    public function get($name)
    {
        return 'redis get ' . $name;
    }
}