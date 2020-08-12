<?php

namespace module\model;

use module\lib\Crud;

class User extends Crud
{
    protected $table = 'user';
    protected $pk = 'uid';
}

?>