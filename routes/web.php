<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\TotpController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Hrm\MasterController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Members\MemberController;
use App\Http\Controllers\Members\MemberLifecycleController;
use App\Http\Controllers\Settings\BranchController;
use App\Http\Controllers\Settings\BusinessDayController;
use App\Http\Controllers\Settings\GeneralSettingsController;
use App\Http\Controllers\Settings\GeoController;
use App\Http\Controllers\Settings\PermissionController;
use App\Http\Controllers\Settings\RegisterController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Settings\SamityController;
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

        Route::middleware('can:settings.general')->group(function () {
            Route::get('/general', [GeneralSettingsController::class, 'index'])->name('general.index');
            Route::post('/general', [GeneralSettingsController::class, 'update'])->name('general.update');
        });

        Route::middleware('can:settings.geo')->group(function () {
            Route::get('/geo/{entity}', [GeoController::class, 'index'])->name('geo.index');
            Route::post('/geo/{entity}', [GeoController::class, 'store'])->name('geo.store');
            Route::put('/geo/{entity}/{id}', [GeoController::class, 'update'])->name('geo.update');
            Route::delete('/geo/{entity}/{id}', [GeoController::class, 'destroy'])->name('geo.destroy');
        });

        Route::get('/permissions', [PermissionController::class, 'index'])
            ->middleware('can:settings.view')
            ->name('permissions.index');

        // Samities, holidays, notices (Step 7).
        Route::middleware('can:settings.view')->group(function () {
            Route::get('/samities', [SamityController::class, 'index'])->name('samities.index');
            Route::get('/samities/changes/{type}', [SamityController::class, 'changeForm'])->whereIn('type', ['day', 'officer', 'manager'])->name('samities.changes');
            Route::get('/samities/merge', [SamityController::class, 'mergeForm'])->name('samities.merge');
            Route::get('/samities/split', [SamityController::class, 'splitForm'])->name('samities.split');
            Route::get('/holidays', [SamityController::class, 'holidays'])->name('holidays.index');
            Route::get('/notices', [SamityController::class, 'notices'])->name('notices.index');
        });
        Route::middleware('can:settings.general')->group(function () {
            Route::post('/samities', [SamityController::class, 'store'])->name('samities.store');
            Route::put('/samities/{samity}', [SamityController::class, 'update'])->name('samities.update');
            Route::post('/samities/changes/{type}', [SamityController::class, 'applyChange'])->whereIn('type', ['day', 'officer', 'manager'])->name('samities.changes.apply');
            Route::post('/samities/merge', [SamityController::class, 'merge'])->name('samities.merge.apply');
            Route::post('/samities/split', [SamityController::class, 'split'])->name('samities.split.apply');
            Route::post('/holidays', [SamityController::class, 'storeHoliday'])->name('holidays.store');
            Route::delete('/holidays/{holiday}', [SamityController::class, 'destroyHoliday'])->name('holidays.destroy');
            Route::post('/notices', [SamityController::class, 'storeNotice'])->name('notices.store');
            Route::delete('/notices/{notice}', [SamityController::class, 'destroyNotice'])->name('notices.destroy');
        });

        // Employee & director registers (Step 6).
        Route::middleware('can:hrm.employees')->group(function () {
            Route::get('/employees', [RegisterController::class, 'index'])->defaults('entity', 'employees')->name('employees.index');
            Route::post('/employees', [RegisterController::class, 'store'])->defaults('entity', 'employees')->name('employees.store');
            Route::put('/employees/{id}', [RegisterController::class, 'update'])->defaults('entity', 'employees')->name('employees.update');
            Route::get('/directors', [RegisterController::class, 'index'])->defaults('entity', 'directors')->name('directors.index');
            Route::post('/directors', [RegisterController::class, 'store'])->defaults('entity', 'directors')->name('directors.store');
            Route::put('/directors/{id}', [RegisterController::class, 'update'])->defaults('entity', 'directors')->name('directors.update');
        });
    });
});

Route::middleware(['auth', 'password.changed', 'totp', 'can:hrm.masters'])->prefix('hrm')->name('hrm.')->group(function () {
    Route::get('/{entity}', [MasterController::class, 'index'])->name('masters.index');
    Route::post('/{entity}', [MasterController::class, 'store'])->name('masters.store');
    Route::put('/{entity}/{id}', [MasterController::class, 'update'])->name('masters.update');
    Route::delete('/{entity}/{id}', [MasterController::class, 'destroy'])->name('masters.destroy');
});

// --- Members (Step 8) ------------------------------------------------------
Route::middleware(['auth', 'password.changed', 'totp'])->prefix('members')->name('members.')->group(function () {
    Route::get('/', [MemberController::class, 'index'])->middleware('can:members.view')->name('index');
    Route::get('/admission', [MemberController::class, 'create'])->middleware('can:members.create')->name('admission');
    Route::post('/', [MemberController::class, 'store'])->middleware('can:members.create')->name('store');
    Route::get('/transfer', [MemberLifecycleController::class, 'transferForm'])->middleware('can:members.transfer')->name('transfer');
    Route::post('/transfer', [MemberLifecycleController::class, 'transfer'])->middleware('can:members.transfer')->name('transfer.apply');
    Route::get('/closing', [MemberLifecycleController::class, 'closingForm'])->middleware('can:members.close')->name('closing');
    Route::post('/closing', [MemberLifecycleController::class, 'close'])->middleware('can:members.close')->name('closing.apply');
    Route::post('/closing/{closing}/cancel', [MemberLifecycleController::class, 'cancelClosing'])->middleware('can:members.close')->name('closing.cancel');
    Route::get('/{member}', [MemberController::class, 'show'])->middleware('can:members.view')->name('show');
    Route::get('/{member}/edit', [MemberController::class, 'edit'])->middleware('can:members.edit')->name('edit');
    Route::put('/{member}', [MemberController::class, 'update'])->middleware('can:members.edit')->name('update');
    Route::get('/{member}/admission-form', [MemberController::class, 'admissionForm'])->middleware('can:members.print')->name('admission-form');
    Route::post('/{member}/documents', [MemberController::class, 'storeDocument'])->middleware('can:members.documents')->name('documents.store');
    Route::post('/{member}/visits', [MemberController::class, 'storeVisit'])->middleware('can:members.visits')->name('visits.store');
    Route::post('/{member}/check-in', [MemberController::class, 'checkIn'])->middleware('can:members.visits')->name('check-in');
    Route::post('/{member}/location', [MemberController::class, 'updateLocation'])->middleware('can:members.visits')->name('location');
});

// Private files: the controller itself requires an authenticated user.
Route::get('/files/{path}', [FileController::class, 'show'])
    ->where('path', '.*')
    ->name('files.show');
