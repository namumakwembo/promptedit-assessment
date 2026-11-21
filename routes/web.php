<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::get('/dashboard', DashboardController::class)
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::post('/generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:30,1')->name('generations.estimate');
    Route::post('/generations', [GenerationController::class, 'store'])
        ->middleware('throttle:10,1')->name('generations.store');
    Route::post('/generations/{generation}/retry', [GenerationController::class, 'retry'])
        ->middleware('throttle:10,1')->name('generations.retry');
});

require __DIR__.'/settings.php';
