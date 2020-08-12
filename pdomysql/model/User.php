<?php

namespace model;

use lib\Crud;

class User extends Crud
{
    protected $table = 'user';
    protected $pk = 'uid';
}

?>