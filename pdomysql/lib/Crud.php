<?php

namespace module\lib;

class Crud
{
    private $db;
    public $variables;

    public function __construct($data = array())
    {
        $this->db = new DB();
        $this->variables = $data;
    }

    public function __set($name, $value)
    {
        if (strtolower($name) === $this->pk) {
            $this->variables[$this->pk] = $value;
        } else {
            $this->variables[$name] = $value;
        }
    }

    public function __get($name)
    {
        if (is_array($this->variables)) {
            if (array_key_exists($name, $this->variables)) {
                return $this->variables[$name];
            }
        }
        return null;
    }

    public function save($id = "0")
    {
        $this->variables[$this->pk] = (empty($this->variables[$this->pk])) ? $id : $this->variables[$this->pk];
        $fieldsValues = '';
        $columns = array_keys($this->variables);
        foreach ($columns as $column) {
            if ($column !== $this->pk)
                $fieldsValues .= $column . " = :" . $column . ",";
        }
        $fieldsValues = substr_replace($fieldsValues, '', -1);
        if (count($columns) > 1) {
            $sql = "UPDATE " . $this->table . " SET " . $fieldsValues . " WHERE " . $this->pk . "= :" . $this->pk;
            if ($id === "0" && $this->variables[$this->pk] === "0") {
                unset($this->variables[$this->pk]);
                $sql = "UPDATE " . $this->table . " SET " . $fieldsValues;
            }
            return $this->exec($sql);
        }
        return null;
    }

    public function create()
    {
        $bindings = $this->variables;
        if (!empty($bindings)) {
            $fields = array_keys($bindings);
            $fieldsValues = array(implode(",", $fields), ":" . implode(",:", $fields));
            $sql = "INSERT INTO " . $this->table . " (" . $fieldsValues[0] . ") VALUES (" . $fieldsValues[1] . ")";
        } else {
            $sql = "INSERT INTO " . $this->table . " () VALUES ()";
        }
        return $this->exec($sql);
    }

    public function delete($id = "")
    {
        $sql = '';
        $id = (empty($this->variables[$this->pk])) ? $id : $this->variables[$this->pk];
        if (!empty($id)) {
            $sql = "DELETE FROM " . $this->table . " WHERE " . $this->pk . "= :" . $this->pk . " LIMIT 1";
        }
        return $this->exec($sql, array($this->pk => $id));
    }

    public function find($id = "")
    {
        $id = (empty($this->variables[$this->pk])) ? $id : $this->variables[$this->pk];
        if (!empty($id)) {
            $sql = "SELECT * FROM " . $this->table . " WHERE " . $this->pk . "= :" . $this->pk . " LIMIT 1";
            $result = $this->db->row($sql, array($this->pk => $id));
            $this->variables = ($result != false) ? $result : null;
        }
    }

    public function search($fields = array(), $sort = array())
    {
        $bindings = empty($fields) ? $this->variables : $fields;
        $sql = "SELECT * FROM " . $this->table;
        if (!empty($bindings)) {
            $fieldsValues = array();
            $columns = array_keys($bindings);
            foreach ($columns as $column) {
                $fieldsValues [] = $column . " = :" . $column;
            }
            $sql .= " WHERE " . implode(" AND ", $fieldsValues);
        }
        if (!empty($sort)) {
            $sortValues = array();
            foreach ($sort as $key => $value) {
                $sortValues[] = $key . " " . $value;
            }
            $sql .= " ORDER BY " . implode(", ", $sortValues);
        }
        return $this->exec($sql);
    }

    public function all()
    {
        return $this->db->query("SELECT * FROM " . $this->table);
    }

    public function min($field)
    {
        return $this->db->single("SELECT min(" . $field . ")" . " FROM " . $this->table);
    }

    public function max($field)
    {
        return $this->db->single("SELECT max(" . $field . ")" . " FROM " . $this->table);
    }

    public function avg($field)
    {
        return $this->db->single("SELECT avg(" . $field . ")" . " FROM " . $this->table);
    }

    public function sum($field)
    {
        return $this->db->single("SELECT sum(" . $field . ")" . " FROM " . $this->table);
    }

    public function count($field)
    {
        return $this->db->single("SELECT count(" . $field . ")" . " FROM " . $this->table);
    }

    private function exec($sql, $array = null)
    {
        if ($array !== null) {
            $result = $this->db->query($sql, $array);
        } else {
            $result = $this->db->query($sql, $this->variables);
        }
        $this->variables = array();
        return $result;
    }

}