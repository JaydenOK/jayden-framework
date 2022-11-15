<?php

namespace module\lib;

use PDO;
use PDOException;
use PDOStatement;

/**
 * 查询：
 * $person = $db->query("SELECT * FROM Persons WHERE firstname = :firstname AND id = :id", array("firstname" => "John", "id" => "1"));
 *
 * 新增
 * class User Extends Crud
 * {
 *     protected $table = 'user';
 *     protected $pk = 'id';
 * }
 * $user = new User();
 * $user->username = '新人';
 * $user->guid = 'xxxx';
 * $creation = $user->Create();
 *
 * $saved = $person->save();    //更新
 *
 * $saved = $person->delete();  //删除
 *
 * Class DB
 * @package module\lib
 */
class DB
{
    /**
     * @var PDO
     */
    private $pdo;
    /**
     * @var PDOStatement
     */
    private $sQuery;
    private $settings;
    private $bConnected = false;
    private $log;
    private $parameters;

    public function __construct($dbConfig = [])
    {
        $this->log = new Log();
        $this->connect($dbConfig);
        $this->parameters = array();
    }

    private function connect($dbConfig = [])
    {
        if (!empty($dbConfig)) {
            $this->settings = $dbConfig;
        } else {
            $this->settings = include dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        }
        $dsn = "mysql:host={$this->settings['host']};port={$this->settings['port']};dbname={$this->settings['dbname']}";
        try {
            $this->pdo = new PDO($dsn, $this->settings["user"], $this->settings["password"], array(
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->settings["charset"]}"
            ));
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->bConnected = true;
        } catch (PDOException $e) {
            echo $this->exceptionLog($e->getMessage());
            die();
        }
    }

    public function closeConnection()
    {
        $this->pdo = null;
    }

    private function init($query, $parameters = "")
    {
        if (!$this->bConnected) {
            $this->connect();
        }
        try {
            $this->sQuery = $this->pdo->prepare($query);
            $this->bindMore($parameters);
            if (!empty($this->parameters)) {
                foreach ($this->parameters as $param => $value) {
                    if (is_int($value[1])) {
                        $type = PDO::PARAM_INT;
                    } else if (is_bool($value[1])) {
                        $type = PDO::PARAM_BOOL;
                    } else if (is_null($value[1])) {
                        $type = PDO::PARAM_NULL;
                    } else {
                        $type = PDO::PARAM_STR;
                    }
                    $this->sQuery->bindValue($value[0], $value[1], $type);
                }
            }
            $this->sQuery->execute();
        } catch (PDOException $e) {
            echo $this->exceptionLog($e->getMessage(), $query);
            die();
        }
        $this->parameters = array();
    }

    public function bind($para, $value)
    {
        $this->parameters[sizeof($this->parameters)] = [":" . $para, $value];
    }

    public function bindMore($parray)
    {
        if (empty($this->parameters) && is_array($parray)) {
            $columns = array_keys($parray);
            foreach ($columns as $i => &$column) {
                $this->bind($column, $parray[$column]);
            }
        }
    }

    public function query($query, $params = null, $fetchmode = PDO::FETCH_ASSOC)
    {
        $query = trim(str_replace("\r", " ", $query));
        $this->init($query, $params);
        $rawStatement = explode(" ", preg_replace("/\s+|\t+|\n+/", " ", $query));
        $statement = strtolower($rawStatement[0]);
        if ($statement === 'select' || $statement === 'show') {
            return $this->sQuery->fetchAll($fetchmode);
        } elseif ($statement === 'insert' || $statement === 'update' || $statement === 'delete') {
            return $this->sQuery->rowCount();
        } else {
            return NULL;
        }
    }

    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }

    public function beginTransaction()
    {
        return $this->pdo->beginTransaction();
    }

    public function executeTransaction()
    {
        return $this->pdo->commit();
    }

    public function rollBack()
    {
        return $this->pdo->rollBack();
    }

    public function column($query, $params = null)
    {
        $this->init($query, $params);
        $Columns = $this->sQuery->fetchAll(PDO::FETCH_NUM);
        $column = null;
        foreach ($Columns as $cells) {
            $column[] = $cells[0];
        }
        return $column;
    }

    public function fetchAll($query, $params = null)
    {
        $this->init($query, $params);
        return $this->sQuery->fetchAll(PDO::FETCH_ASSOC);
    }

    public function row($query, $params = null, $fetchmode = PDO::FETCH_ASSOC)
    {
        $this->init($query, $params);
        $result = $this->sQuery->fetch($fetchmode);
        $this->sQuery->closeCursor();
        return $result;
    }

    public function single($query, $params = null)
    {
        $this->init($query, $params);
        $result = $this->sQuery->fetchColumn();
        $this->sQuery->closeCursor();
        return $result;
    }

    private function exceptionLog($message, $sql = "")
    {
        $exception = 'Unhandled Exception. <br />';
        $exception .= $message;
        $exception .= "<br /> You can find the error back in the log.";
        if (!empty($sql)) {
            $message .= "\r\nRaw SQL : " . $sql;
        }
        $this->log->write($message);
        return $exception;
    }

}

