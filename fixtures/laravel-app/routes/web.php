<?php

declare(strict_types=1);

use App\Http\Controllers\HelloController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HelloController::class, 'home'])->name('home');
Route::get('/hello/{name}', [HelloController::class, 'hello'])->name('hello');
