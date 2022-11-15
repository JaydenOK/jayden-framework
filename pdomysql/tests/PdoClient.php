<?php

namespace module\tests;

use module\FluentPDO\Query;

class PdoClient
{

    protected function database()
    {
        $config = [
            'host' => '192.168.92.209',
            'user' => 'xxx',
            'password' => 'yibai#2022',
            'dbname' => 'yibai_account_system',
            'port' => '3306',
            'charset' => 'utf8',
        ];
        return $config;
    }

    public function getQuery()
    {
        $config = $this->database();
        $pdo = new \PDO("mysql:dbname={$config['dbname']};host={$config['host']};charset={$config['charset']}", "{$config['user']}", "{$config['password']}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_WARNING);
        $pdo->setAttribute(\PDO::ATTR_CASE, \PDO::CASE_LOWER);
        $fluent = new Query($pdo);
        return $fluent;
    }

}