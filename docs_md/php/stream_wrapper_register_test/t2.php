<?php

class t2
{

    public function __construct()
    {
        echo 't2::__construct' . PHP_EOL;
    }

    public static function show()
    {
        echo 't2::show' . PHP_EOL;
    }
}

t2::show();