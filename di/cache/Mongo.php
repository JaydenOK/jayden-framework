<?php

namespace module\cache;

use module\BackendInterface;

class Mongo implements BackendInterface
{

    public function find($key)
    {
        // TODO: Implement find() method.
        return '{Mongo find}';
    }

    public function save($key, $value, $lifetime)
    {
        // TODO: Implement save() method.
        return '{Mongo save}';
    }

    public function delete($key)
    {
        // TODO: Implement delete() method.
        return '{Mongo delete}';
    }
}