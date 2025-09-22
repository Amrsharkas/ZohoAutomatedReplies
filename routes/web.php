<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZohoOAuthController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/zoho/connect', [ZohoOAuthController::class, 'connect'])->name('zoho.connect');
Route::get('/zoho/callback', [ZohoOAuthController::class, 'callback'])->name('zoho.callback');
