<?php

use Illuminate\Support\Facades\Route;


Route::get('/', fn() => response()->json(['message' => 'Laravel is working!']));

Route::get('/', function () {
    return view('welcome');
});


