<?php

use Rakit\Validation\Validator;

class Validate
{
    public function run($body)
    {
        //print_r($body);exit;
        $validator = new Validator();
        $validation = $validator->validate([
            'number' => '1.2345'
        ], [
            'number' => 'numeric|max:2',
        ]);

        $res = $validation->passes();

        var_dump($res);

    }
}