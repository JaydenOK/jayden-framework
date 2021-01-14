<?php

namespace module;

interface BackendInterface
{
    public function find($key);

    public function save($key, $value, $lifetime);

    public function delete($key);
}