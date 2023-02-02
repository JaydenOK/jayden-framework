<?php
/*
*
* @ Package: PDOx - Useful Query Builder & PDO Class
* @ Author: izni burak demirtas / @izniburak <info@burakdemirtas.org>
* @ Web: http://burakdemirtas.org
* @ URL: https://github.com/izniburak/PDOx
* @ Licence: The MIT License (MIT) - Copyright (c) - http://opensource.org/licenses/MIT
*
*/

//Pdox 直接query()/fetch(), exec()执行模式，不是prepare预处理，类似于mysqli

require 'vendor/autoload.php';

// database config
$config = [
    'host' => 'localhost',
    'driver' => 'mysql',
    'database' => 'test',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8',
    'collation' => 'utf8_general_ci',
    'prefix' => ''
];

// start PDOx
$db = new \Buki\Pdox($config);

// Select Records
$records = $db->table('pages')
    ->where('age', '>', 18)
    ->orderBy('id', 'desc')
    ->limit(10)
    ->getAll();

var_dump($records);

if (1) exit;


########## insert
$data = [
    'title' => 'test',
    'content' => 'Lorem ipsum dolor sit amet...',
    'time' => '2017-05-19 19:05:00',
    'status' => 1
];

$db->table('pages')->insert($data);
# Output: "INSERT INTO test (title, content, time, status) VALUES ('test', 'Lorem ipsum dolor sit amet...', '2017-05-19 19:05:00', '1')"


########## update

$data = [
    'username' => 'izniburak',
    'password' => 'pass',
    'activation' => 1,
    'status' => 1
];

$db->table('users')->where('id', 10)->update($data);
# Output: "UPDATE users SET username='izniburak', password='pass', activation='1', status='1' WHERE id='10'"


//##########  delete

$db->table('test')->where("id", 17)->delete();
# Output: "DELETE FROM test WHERE id = '17'"

# OR

$db->table('test')->delete();
# Output: "TRUNCATE TABLE delete"


//##########  transaction

$db->transaction();

$data = [
    'title' => 'new title',
    'status' => 2
];
$db->table('test')->where('id', 10)->update($data);
$db->commit();
# OR
$db->rollBack();