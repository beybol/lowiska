<?php

use App\Http\Middleware\TwoFactorMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\TwoFactorController;
use Illuminate\Auth\Events\Registered;
use App\Models\User;
use Illuminate\Foundation\Auth\EmailVerificationRequest;

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

Route::middleware(['auth'])->group(function () {
    Route::get('/verify', [TwoFactorController::class, 'index'])->name('verify.index');
    Route::post('/verify', [TwoFactorController::class, 'store'])->name('verify.store');
    Route::post('/verify/resend', [TwoFactorController::class, 'resend'])->name('verify.resend');
});
