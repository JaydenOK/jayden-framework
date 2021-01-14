<?php

namespace module\cache;


use module\BackendInterface;

class File implements BackendInterface
{
    public function find($key)
    {
        // TODO: Implement find() method.
        return '{File find}';
    }

    public function save($key, $value, $lifetime)
    {
        // TODO: Implement save() method.
        return '{File save}';
    }

    public function delete($key)
    {
        // TODO: Implement delete() method.
        return '{File delete}';
    }
}