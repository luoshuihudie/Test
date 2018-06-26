<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TestController extends Controller
{
    //
    public function Test(){
        echo "Test";
    }
    public function Test2(){
        if(true){
            echo "Test2";
        }
    }
}
