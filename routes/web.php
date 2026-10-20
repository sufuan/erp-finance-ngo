<?php

use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Settings\BranchController;
use App\Http\Controllers\Settings\BusinessDayController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::post('/locale/{locale}', [LocaleController::class, 'switch'])
    ->whereIn('locale', ['en', 'bn'])
    ->name('locale.switch');

Route::post('/branch/switch', [BranchSwitchController::class, 'switch'])->name('branch.switch');

// Private files: authenticated controller only, never public links.
Route::get('/files/{path}', [FileController::class, 'show'])
    ->where('path', '.*')
    ->name('files.show');

Route::prefix('settings')->name('settings.')->group(function () {
    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');

    Route::get('/business-day', [BusinessDayController::class, 'index'])->name('business-day.index');
    Route::post('/business-day/close', [BusinessDayController::class, 'close'])->name('business-day.close');
});
