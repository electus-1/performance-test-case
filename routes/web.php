<?php

use App\Http\Controllers\PerformanceTestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/performance-test', [PerformanceTestController::class, 'index']);
