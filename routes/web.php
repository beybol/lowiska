<?php

use App\Http\Middleware\TwoFactorMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SocialAuthController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('auth/{provider}', [SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
Route::get('auth/{provider}/callback', [
    SocialAuthController::class,
    'callback'
])
    ->name('social.callback');

Route::get('2fa-test', function () {
    return 'ok';
})->middleware(['auth', TwoFactorMiddleware::class]);
