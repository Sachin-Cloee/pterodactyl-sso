<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PaymenterSso\Http\Controllers\LoginController;

/*
|--------------------------------------------------------------------------
| Paymenter SSO routes
|--------------------------------------------------------------------------
|
| Mounted by the provider at /extensions/paymenter-sso.
|
*/

Route::get('/login', LoginController::class)
    ->middleware('throttle:60,1')
    ->name('login');
