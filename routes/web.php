<?php

use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::post('/locale/{locale}', [LocaleController::class, 'switch'])
    ->whereIn('locale', ['en', 'bn'])
    ->name('locale.switch');
