<?php

namespace module;

interface DiAwareInterface
{
    public function setDI($di);

    public function getDI();
}