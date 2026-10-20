<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\TotpController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Settings\BranchController;
use App\Http\Controllers\Settings\BusinessDayController;
use App\Http\Controllers\Settings\PermissionController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Settings\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// --- Public: locale + login (Step 2) -------------------------------------
Route::post('/locale/{locale}', [LocaleController::class, 'switch'])
    ->whereIn('locale', ['en', 'bn'])
    ->name('locale.switch');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

// --- Authenticated: first-login gates exempt themselves inside the middleware
Route::middleware('auth')->group(function () {
    Route::post('/logout', LogoutController::class)->name('logout');

    Route::get('/password/change', [PasswordController::class, 'edit'])->name('password.change');
    Route::post('/password/change', [PasswordController::class, 'update'])->name('password.change.update');

    Route::get('/totp/setup', [TotpController::class, 'setup'])->name('totp.setup');
    Route::post('/totp/setup', [TotpController::class, 'confirmSetup'])->name('totp.setup.confirm');
    Route::get('/totp/challenge', [TotpController::class, 'challenge'])->name('totp.challenge');
    Route::post('/totp/challenge', [TotpController::class, 'verifyChallenge'])->name('totp.challenge.verify');
});

// --- The application: login + password changed + TOTP passed -------------
Route::middleware(['auth', 'password.changed', 'totp'])->group(function () {
    Route::get('/', function () {
        return Inertia::render('Welcome');
    })->name('home');

    Route::post('/branch/switch', [BranchSwitchController::class, 'switch'])->name('branch.switch');

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
        Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
        Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');

        Route::get('/business-day', [BusinessDayController::class, 'index'])->name('business-day.index');
        Route::post('/business-day/close', [BusinessDayController::class, 'close'])->name('business-day.close');

        // RBAC administration (Step 2), guarded by Spatie permissions.
        Route::middleware('can:settings.users-manage')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
            Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
            Route::get('/users/{user}/login-history', [UserController::class, 'loginHistory'])->name('users.login-history');
        });

        Route::middleware('can:settings.roles-manage')->group(function () {
            Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
            Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
            Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        Route::get('/permissions', [PermissionController::class, 'index'])
            ->middleware('can:settings.view')
            ->name('permissions.index');
    });
});

// Private files: the controller itself requires an authenticated user.
Route::get('/files/{path}', [FileController::class, 'show'])
    ->where('path', '.*')
    ->name('files.show');
