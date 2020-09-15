<?php

use Rakit\Validation\Helper;
use Rakit\Validation\Validator;

class Validate
{
    public function run($body)
    {
        $arr = [
            'a' => [
                'aa' => [
                    'aaa' => '333',
                    'aaaaa' => '33ss3',
                ],
                'bb' => '111'
            ],
            'v' => [
                'aaaa'
            ]
        ];
        //print_r($body);exit;
        $validator = new Validator();
        //先make再validate() 或 直接$validator->validate() , 包含了make操作
        // make it
        $validation = $validator->make($_POST + $_FILES, [
            'name' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:6',
            'confirm_password' => 'required|same:password',
            'avatar' => 'required|uploaded_file:0,500K,png,jpeg',
            'skills' => 'array',
            'skills.*.id' => 'required|numeric',
            'skills.*.percentage' => 'required|numeric'
        ]);

        // then validate
        $validation->validate();

        if ($validation->fails()) {
            // handling errors
            $errors = $validation->errors();
            echo "<pre>";
            print_r($errors->firstOfAll());
            echo "</pre>";
            exit;
        } else {
            // validation passes
            echo "Success!";
        }

        $res = $validation->passes();

        print_r($res);

    }
}