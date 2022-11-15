<?php


use module\lib\DB;
use module\lib\Crud;

$db = new DB();
$persons = $db->query("SELECT * FROM persons");


$db->bind("id", "1");
$db->bind("firstname", "John");
$person = $db->query("SELECT * FROM Persons WHERE firstname = :firstname AND id = :id");

$db->bindMore(array("firstname" => "John", "id" => "1"));
$person = $db->query("SELECT * FROM Persons WHERE firstname = :firstname AND id = :id");

$person = $db->query("SELECT * FROM Persons WHERE firstname = :firstname AND id = :id", array("firstname" => "John", "id" => "1"));


$ages = $db->row("SELECT * FROM Persons WHERE  id = :id", array("id" => "1"));


$db->bind("id", "3");
$firstname = $db->single("SELECT firstname FROM Persons WHERE id = :id");

$like = $db->query("SELECT * FROM Persons WHERE Firstname LIKE :firstname ", array("firstname" => "sekit%"));

$names = $db->column("SELECT Firstname FROM Persons");

$delete = $db->query("DELETE FROM Persons WHERE Id = :id", array("id" => "1"));

$update = $db->query("UPDATE Persons SET firstname = :f WHERE Id = :id", array("f" => "Jan", "id" => "32"));

$insert = $db->query("INSERT INTO Persons(Firstname,Age) VALUES(:f,:age)", array("f" => "Vivek", "age" => "20"));

if ($insert > 0) {
    return 'Succesfully created a new person !';
}


$person_num = $db->row("SELECT * FROM Persons WHERE id = :id", array("id" => "1"), PDO::FETCH_NUM);


class person Extends Crud
{
    protected $table = 'persons';
    protected $pk = 'id';
}


$person = new \module\model\User();

$person->Firstname = "Kona";
$person->Age = "20";
$person->Sex = "F";
$created = $person->Create();

$person = new person(array("Firstname" => "Kona", "age" => "20", "sex" => "F"));
$created = $person->Create();

$person->Id = "17";
$deleted = $person->Delete();

$deleted = $person->Delete(17);

$person->Firstname = "John";
$person->Age = "20";
$person->Sex = "F";
$person->Id = "4";

$saved = $person->Save();


$person = new person(array("Firstname" => "John", "age" => "20", "sex" => "F", "Id" => "4"));
$saved = $person->Save();


$person->Id = "1";
$person->find();

echo $person->Firstname;

$person->find(1);

$persons = $person->all(); 
