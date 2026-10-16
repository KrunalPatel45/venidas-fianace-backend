<?php

use Illuminate\Support\Facades\Route;

Route::get('/{path?}', function () {
    return response()->file(public_path('index.html'));
})->where('path', '^(?!api(?:/|$)).*');