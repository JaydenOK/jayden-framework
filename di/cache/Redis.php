<?php

namespace module\cache;

use module\BackendInterface;

class Redis implements BackendInterface
{
    public function find($key)
    {
        return '{Redis find}';
    }

    public function save($key, $value, $lifetime)
    {
        return '{Redis save}';
    }

    public function delete($key)
    {
        return '{Redis delete}';
    }
}