<?php

declare(strict_types=1);

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);
Route::post('/ping', PingController::class);
Route::get('/about', 'App\\Http\\Controllers\\HomeController@about');

Route::middleware('auth')->prefix('articles')->group(function () {
    Route::resource('articles', ArticleController::class);
});
