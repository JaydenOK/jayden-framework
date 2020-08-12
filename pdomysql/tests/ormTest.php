<?php 

use model\User;

require '../bootstrap.php';

$user = new User();
$user->username = '新的';
$user->guid = 'asfaefawefawefaewfawasfasf';
$creation = $user->Create();