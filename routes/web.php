<?php

use App\Http\Controllers\Accounts\AccountMasterController;
use App\Http\Controllers\Accounts\AccountReportController;
use App\Http\Controllers\Accounts\TransferController;
use App\Http\Controllers\Accounts\VoucherController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\TotpController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Collections\CollectionController;
use App\Http\Controllers\Collections\CollectionReportController;
use App\Http\Controllers\Collections\MemberInstrumentController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Hrm\MasterController;
use App\Http\Controllers\Deposits\DepositAccountController;
use App\Http\Controllers\Deposits\DepositBlockClosingController;
use App\Http\Controllers\Deposits\DepositProductController;
use App\Http\Controllers\Deposits\DepositProfitController;
use App\Http\Controllers\Deposits\DepositTransactionController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Members\MemberController;
use App\Http\Controllers\Members\MemberLifecycleController;
use App\Http\Controllers\Settings\BalanceMigrationController;
use App\Http\Controllers\Settings\BranchController;
use App\Http\Controllers\Settings\BusinessDayController;
use App\Http\Controllers\Settings\GeneralSettingsController;
use App\Http\Controllers\Settings\GeoController;
use App\Http\Controllers\Settings\MemberMigrationController;
use App\Http\Controllers\Settings\PermissionController;
use App\Http\Controllers\Loans\DuePenaltyController;
use App\Http\Controllers\Loans\InsuranceClaimController;
use App\Http\Controllers\Loans\LoanAdjustmentController;
use App\Http\Controllers\Loans\LoanMasterController;
use App\Http\Controllers\Loans\LoanMoneyController;
use App\Http\Controllers\Loans\LoanOpeningController;
use App\Http\Controllers\Loans\LoanProposalController;
use App\Http\Controllers\Reports\DepositReportController;
use App\Http\Controllers\Reports\LlpController;
use App\Http\Controllers\Reports\LoanReportController;
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
    // Post-login home = the Dashboard (Module 2 / Step 24); the mega-hub
    // tiles/search render below the overview on the same page.
    Route::get('/', [DashboardController::class, 'index'])->name('home');

    // Module hub pages (redesign): navigation only — tiles/cards are
    // permission-filtered client-side, target routes stay authoritative.
    Route::get('/hub/{module}', function (string $module) {
        return Inertia::render('Hub/Show', ['module' => $module]);
    })->whereIn('module', ['member', 'deposit', 'loan', 'collection', 'accounts', 'hrm', 'settings'])->name('hub.show');

    Route::post('/branch/switch', [BranchSwitchController::class, 'switch'])->name('branch.switch');

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
        Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
        Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');

        Route::get('/business-day', [BusinessDayController::class, 'index'])->name('business-day.index');
        Route::post('/business-day/close', [BusinessDayController::class, 'close'])->name('business-day.close');
        Route::post('/business-day/auto-close', [BusinessDayController::class, 'toggleAutoClose'])
            ->middleware('can:settings.closing')->name('business-day.auto-close');

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
            Route::get('/geo/{entity}', [GeoController::class, 'index'])->name('geo.index')->whereIn('entity', ['villages', 'working-areas']);
            Route::post('/geo/{entity}', [GeoController::class, 'store'])->name('geo.store')->whereIn('entity', ['villages', 'working-areas']);
            Route::put('/geo/{entity}/{id}', [GeoController::class, 'update'])->name('geo.update')->whereIn('entity', ['villages', 'working-areas']);
            Route::delete('/geo/{entity}/{id}', [GeoController::class, 'destroy'])->name('geo.destroy')->whereIn('entity', ['villages', 'working-areas']);
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

        // Member migrations (Step 9A).
        Route::middleware('can:settings.view')->group(function () {
            Route::get('/member-migrations', [MemberMigrationController::class, 'index'])->name('member-migrations.index');
            Route::get('/member-migrations/template', [MemberMigrationController::class, 'template'])->name('member-migrations.template');
            Route::get('/member-migrations/export', [MemberMigrationController::class, 'export'])->name('member-migrations.export');
            Route::get('/member-migrations/{batch}', [MemberMigrationController::class, 'index'])->name('member-migrations.show');
            Route::get('/member-migrations/{batch}/error-report', [MemberMigrationController::class, 'errorReport'])->name('member-migrations.error-report');
        });
        Route::middleware('can:settings.general')->group(function () {
            Route::post('/member-migrations/dry-run/excel', [MemberMigrationController::class, 'dryRunExcel'])->name('member-migrations.dry-run.excel');
            Route::post('/member-migrations/dry-run/grid', [MemberMigrationController::class, 'dryRunGrid'])->name('member-migrations.dry-run.grid');
            Route::post('/member-migrations/{batch}/confirm', [MemberMigrationController::class, 'confirm'])->name('member-migrations.confirm');
            // Excel engine (JSON): preview → re-validate → all-or-nothing confirm.
            Route::post('/member-migrations/preview', [MemberMigrationController::class, 'previewJson'])->name('member-migrations.preview');
            Route::post('/member-migrations/validate-rows', [MemberMigrationController::class, 'validateRowsJson'])->name('member-migrations.validate-rows');
            Route::post('/member-migrations/confirm-import', [MemberMigrationController::class, 'confirmImportJson'])->name('member-migrations.confirm-import');
        });

        // Balance migrations (Step 20A).
        Route::middleware('can:settings.view')->group(function () {
            Route::get('/balance-migrations', [BalanceMigrationController::class, 'index'])->name('balance-migrations.index');
            Route::get('/balance-migrations/{batch}', [BalanceMigrationController::class, 'index'])->name('balance-migrations.show');
            Route::get('/balance-migrations/{batch}/error-report', [BalanceMigrationController::class, 'errorReport'])->name('balance-migrations.error-report');
        });
        Route::middleware('can:settings.general')->group(function () {
            Route::post('/balance-migrations/dry-run/excel', [BalanceMigrationController::class, 'dryRunExcel'])->name('balance-migrations.dry-run.excel');
            Route::post('/balance-migrations/dry-run/grid', [BalanceMigrationController::class, 'dryRunGrid'])->name('balance-migrations.dry-run.grid');
            Route::post('/balance-migrations/{batch}/confirm', [BalanceMigrationController::class, 'confirm'])->name('balance-migrations.confirm');
        });

        // Employee & director registers (Step 6).
        Route::middleware('can:hrm.employees')->group(function () {
            Route::get('/employees', [RegisterController::class, 'index'])->defaults('entity', 'employees')->name('employees.index');
            Route::post('/employees', [RegisterController::class, 'store'])->defaults('entity', 'employees')->name('employees.store');
            Route::get('/directors', [RegisterController::class, 'index'])->defaults('entity', 'directors')->name('directors.index');
            Route::post('/directors', [RegisterController::class, 'store'])->defaults('entity', 'directors')->name('directors.store');
            // One parameterized update route (HRM-masters pattern): route
            // params reach the controller positionally, so {entity} must be
            // a real URL segment — ->defaults() values arrive AFTER {id}
            // and silently swapped the two arguments.
            Route::put('/{entity}/{id}', [RegisterController::class, 'update'])->whereIn('entity', ['employees', 'directors'])->name('registers.update');
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

// --- Deposits (Step 10) ----------------------------------------------------
Route::middleware(['auth', 'password.changed', 'totp'])->prefix('deposits')->name('deposits.')->group(function () {
    Route::get('/', [DepositAccountController::class, 'index'])->middleware('can:deposits.view')->name('index');
    Route::get('/opening', [DepositAccountController::class, 'create'])->middleware('can:deposits.open')->name('opening');
    Route::post('/', [DepositAccountController::class, 'store'])->middleware('can:deposits.open')->name('store');
    Route::get('/other', [DepositAccountController::class, 'otherCreate'])->middleware('can:deposits.open')->name('other');
    Route::post('/other', [DepositAccountController::class, 'otherStore'])->middleware('can:deposits.open')->name('other.store');
    Route::get('/receive', [DepositTransactionController::class, 'receiveForm'])->middleware('can:deposits.receive')->name('receive');
    Route::post('/receive', [DepositTransactionController::class, 'storeReceive'])->middleware('can:deposits.receive')->name('receive.store');
    Route::get('/payment', [DepositTransactionController::class, 'paymentForm'])->middleware('can:deposits.pay')->name('payment');
    Route::post('/payment', [DepositTransactionController::class, 'storePayment'])->middleware('can:deposits.pay')->name('payment.store');
    Route::post('/transactions/{transaction}/cancel', [DepositTransactionController::class, 'cancelTransaction'])->middleware('can:deposits.pay')->name('transactions.cancel');
    Route::get('/transfer', [DepositTransactionController::class, 'transferForm'])->middleware('can:deposits.transfer')->name('transfer');
    Route::post('/transfer', [DepositTransactionController::class, 'storeTransfer'])->middleware('can:deposits.transfer')->name('transfer.store');
    Route::post('/transfers/{transfer}/cancel', [DepositTransactionController::class, 'cancelTransfer'])->middleware('can:deposits.transfer')->name('transfers.cancel');
    Route::get('/blocks', [DepositBlockClosingController::class, 'blocksForm'])->middleware('can:deposits.block')->name('blocks');
    Route::post('/blocks', [DepositBlockClosingController::class, 'storeBlock'])->middleware('can:deposits.block')->name('blocks.store');
    Route::get('/blocks/clear', [DepositBlockClosingController::class, 'clearForm'])->middleware('can:deposits.block')->name('blocks.clear');
    Route::post('/blocks/{block}/clear', [DepositBlockClosingController::class, 'clearBlock'])->middleware('can:deposits.block')->name('blocks.clear.apply');
    Route::get('/closing', [DepositBlockClosingController::class, 'closingForm'])->middleware('can:deposits.close')->name('closing');
    Route::post('/closing', [DepositBlockClosingController::class, 'storeClosing'])->middleware('can:deposits.close')->name('closing.store');
    Route::post('/closing/{closing}/cancel', [DepositBlockClosingController::class, 'cancelClosing'])->middleware('can:deposits.close')->name('closing.cancel');
    Route::get('/profit/setup', [DepositProfitController::class, 'setupForm'])->middleware('can:deposits.profit-setup')->name('profit.setup');
    Route::post('/profit/setup', [DepositProfitController::class, 'storeSetup'])->middleware('can:deposits.profit-setup')->name('profit.setup.store');
    Route::post('/profit/setup/{setup}/cancel', [DepositProfitController::class, 'cancelSetup'])->middleware('can:deposits.profit-setup')->name('profit.setup.cancel');
    Route::get('/profit/distribution', [DepositProfitController::class, 'distributionForm'])->middleware('can:deposits.profit-post')->name('profit.distribution');
    Route::get('/profit/distribution/auto', [DepositProfitController::class, 'autoForm'])->middleware('can:deposits.profit-post')->name('profit.distribution.auto');
    Route::post('/profit/distributions', [DepositProfitController::class, 'calculate'])->middleware('can:deposits.profit-post')->name('profit.distributions.calculate');
    Route::get('/profit/distributions', [DepositProfitController::class, 'distributionIndex'])->middleware('can:deposits.profit-post')->name('profit.distributions');
    Route::get('/profit/distributions/{distribution}', [DepositProfitController::class, 'distributionShow'])->middleware('can:deposits.profit-post')->name('profit.distributions.show');
    Route::post('/profit/distributions/{distribution}/post', [DepositProfitController::class, 'postDistribution'])->middleware('can:deposits.profit-post')->name('profit.distributions.post');
    Route::get('/profit/payment', [DepositProfitController::class, 'paymentForm'])->middleware('can:deposits.profit-post')->name('profit.payment');
    Route::post('/profit/payment', [DepositProfitController::class, 'storePayment'])->middleware('can:deposits.profit-post')->name('profit.payment.store');
    Route::get('/profit/profile', [DepositProfitController::class, 'profileForm'])->middleware('can:deposits.profit-setup')->name('profit.profile');
    Route::post('/profit/profile', [DepositProfitController::class, 'storeProfile'])->middleware('can:deposits.profit-setup')->name('profit.profile.store');
    Route::get('/share', [DepositTransactionController::class, 'shareForm'])->middleware('can:deposits.receive')->name('share');
    Route::post('/share', [DepositTransactionController::class, 'storeShare'])->middleware('can:deposits.receive')->name('share.store');
    Route::get('/products', [DepositProductController::class, 'index'])->middleware('can:deposits.profit-setup')->name('products');
    Route::post('/products', [DepositProductController::class, 'store'])->middleware('can:deposits.profit-setup')->name('products.store');
    Route::put('/products/{product}', [DepositProductController::class, 'update'])->middleware('can:deposits.profit-setup')->name('products.update');
    Route::delete('/products/{product}', [DepositProductController::class, 'destroy'])->middleware('can:deposits.profit-setup')->name('products.destroy');
});

Route::middleware(['auth', 'password.changed', 'totp'])->prefix('loans')->name('loans.')->group(function () {
    Route::get('/proposals', [LoanProposalController::class, 'index'])->middleware('can:loans.view')->name('proposals');
    Route::get('/proposals/create', [LoanProposalController::class, 'create'])->middleware('can:loans.propose')->name('proposals.create');
    Route::post('/proposals/preview', [LoanProposalController::class, 'preview'])->middleware('can:loans.propose')->name('proposals.preview');
    Route::post('/proposals', [LoanProposalController::class, 'store'])->middleware('can:loans.propose')->name('proposals.store');
    Route::post('/proposals/{proposal}/approve', [LoanProposalController::class, 'approve'])->middleware('can:loans.approve')->name('proposals.approve');
    Route::post('/proposals/{proposal}/reject', [LoanProposalController::class, 'reject'])->middleware('can:loans.approve')->name('proposals.reject');
    Route::get('/disbursement', [LoanMoneyController::class, 'disbursementList'])->middleware('can:loans.disburse')->name('disbursement');
    Route::post('/disbursement/{loan}', [LoanMoneyController::class, 'disburse'])->middleware('can:loans.disburse')->name('disbursement.apply');
    Route::get('/receive', [LoanMoneyController::class, 'receiveForm'])->middleware('can:loans.receive')->name('receive');
    Route::post('/receive', [LoanMoneyController::class, 'storeReceive'])->middleware('can:loans.receive')->name('receive.store');
    Route::get('/receive/cancel', [LoanMoneyController::class, 'cancelForm'])->middleware('can:loans.receive')->name('receive.cancel');
    Route::post('/receive/{receive}/cancel', [LoanMoneyController::class, 'cancelReceive'])->middleware('can:loans.receive')->name('receive.cancel.apply');
    Route::get('/receive/history', [LoanMoneyController::class, 'history'])->middleware('can:loans.view')->name('receive.history');
    Route::get('/opening', [LoanOpeningController::class, 'create'])->middleware('can:loans.open')->name('opening');
    Route::post('/opening', [LoanOpeningController::class, 'store'])->middleware('can:loans.open')->name('opening.store');
    Route::get('/due-penalty', [DuePenaltyController::class, 'index'])->middleware('can:loans.penalty')->name('due-penalty');
    Route::post('/due-penalty/{loan}', [DuePenaltyController::class, 'update'])->middleware('can:loans.penalty')->name('due-penalty.update');
    Route::get('/products', [LoanMasterController::class, 'productsIndex'])->middleware('can:loans.products')->name('products');
    Route::post('/products', [LoanMasterController::class, 'storeProduct'])->middleware('can:loans.products')->name('products.store');
    Route::put('/products/{product}', [LoanMasterController::class, 'updateProduct'])->middleware('can:loans.products')->name('products.update');
    Route::delete('/products/{product}', [LoanMasterController::class, 'destroyProduct'])->middleware('can:loans.products')->name('products.destroy');
    Route::get('/installment-settings', [LoanMasterController::class, 'emiIndex'])->middleware('can:loans.products')->name('installment-settings');
    Route::post('/installment-settings', [LoanMasterController::class, 'storeEmi'])->middleware('can:loans.products')->name('installment-settings.store');
    Route::put('/installment-settings/{template}', [LoanMasterController::class, 'updateEmi'])->middleware('can:loans.products')->name('installment-settings.update');
    Route::delete('/installment-settings/{template}', [LoanMasterController::class, 'destroyEmi'])->middleware('can:loans.products')->name('installment-settings.destroy');
    // Loan adjustments (Step 19).
    Route::get('/reschedule', [LoanAdjustmentController::class, 'rescheduleForm'])->middleware('can:loans.reschedule')->name('reschedule.form');
    Route::post('/reschedule', [LoanAdjustmentController::class, 'reschedule'])->middleware('can:loans.reschedule')->name('reschedule.apply');
    Route::get('/waiver', [LoanAdjustmentController::class, 'waiverForm'])->middleware('can:loans.waive')->name('waiver.form');
    Route::post('/waiver', [LoanAdjustmentController::class, 'waive'])->middleware('can:loans.waive')->name('waiver.apply');
    Route::post('/waiver/{waiver}/cancel', [LoanAdjustmentController::class, 'cancelWaiver'])->middleware('can:loans.waive')->name('waiver.cancel');
    Route::get('/penalty', [LoanAdjustmentController::class, 'penaltyForm'])->middleware('can:loans.penalty')->name('penalty.form');
    Route::post('/penalty', [LoanAdjustmentController::class, 'addPenalty'])->middleware('can:loans.penalty')->name('penalty.apply');
    Route::post('/penalty/{penalty}/cancel', [LoanAdjustmentController::class, 'cancelPenalty'])->middleware('can:loans.penalty')->name('penalty.cancel');
    Route::get('/writeoff', [LoanAdjustmentController::class, 'writeoffForm'])->middleware('can:loans.writeoff')->name('writeoff.form');
    Route::post('/writeoff', [LoanAdjustmentController::class, 'writeoff'])->middleware('can:loans.writeoff')->name('writeoff.apply');
    Route::post('/writeoff/collect', [LoanAdjustmentController::class, 'collectWriteoff'])->middleware('can:loans.writeoff')->name('writeoff.collect');
    Route::get('/insurance-claims', [InsuranceClaimController::class, 'index'])->middleware('can:loans.view')->name('insurance-claims');
    Route::post('/insurance-claims', [InsuranceClaimController::class, 'store'])->middleware('can:loans.propose')->name('insurance-claims.store');
    Route::post('/insurance-claims/{claim}/decide', [InsuranceClaimController::class, 'decide'])->middleware('can:loans.approve')->name('insurance-claims.decide');
    Route::post('/insurance-claims/{claim}/pay', [InsuranceClaimController::class, 'pay'])->middleware('can:loans.receive')->name('insurance-claims.pay');
});

// --- Collection (Steps 21–23) ----------------------------------------------
Route::middleware(['auth', 'password.changed', 'totp'])->prefix('collection')->name('collection.')->group(function () {
    // Posting pages (Common Collection).
    Route::get('/member', [CollectionController::class, 'memberForm'])->middleware('can:collection.collect')->name('member');
    Route::post('/member', [CollectionController::class, 'storeMember'])->middleware('can:collection.collect')->name('member.store');
    Route::get('/sheet', [CollectionController::class, 'sheetForm'])->middleware('can:collection.collect')->name('sheet');
    Route::post('/sheet', [CollectionController::class, 'storeSheet'])->middleware('can:collection.collect')->name('sheet.store');
    Route::get('/simple-sheet', [CollectionController::class, 'simpleForm'])->middleware('can:collection.collect')->name('simple-sheet');
    Route::post('/simple-sheet', [CollectionController::class, 'storeSimple'])->middleware('can:collection.collect')->name('simple-sheet.store');
    Route::get('/member-list', [CollectionController::class, 'memberList'])->middleware('can:collection.view')->name('member-list');
    Route::get('/samity-list', [CollectionController::class, 'samityList'])->middleware('can:collection.view')->name('samity-list');
    Route::get('/other', [CollectionController::class, 'otherForm'])->middleware('can:collection.collect')->name('other');
    Route::post('/other', [CollectionController::class, 'storeOther'])->middleware('can:collection.collect')->name('other.store');
    // Cancels (latest-only rules live in the money engines).
    Route::post('/batches/{batch}/cancel', [CollectionController::class, 'cancelBatch'])->middleware('can:collection.cancel')->name('batches.cancel');
    Route::post('/batches/{batch}/cancel-member', [CollectionController::class, 'cancelMemberLines'])->middleware('can:collection.cancel')->name('batches.cancel-member');
    Route::post('/lines/{line}/cancel', [CollectionController::class, 'cancelLine'])->middleware('can:collection.cancel')->name('lines.cancel');
    Route::post('/other/{other}/cancel', [CollectionController::class, 'cancelOther'])->middleware('can:collection.cancel')->name('other.cancel');
    // Sheets, reports, balances (registry engine).
    Route::get('/sheets', [CollectionReportController::class, 'sheets'])->middleware('can:collection.sheets')->name('sheets');
    Route::get('/reports', [CollectionReportController::class, 'reports'])->middleware('can:collection.reports')->name('reports');
    Route::get('/balances', [CollectionReportController::class, 'balances'])->middleware('can:collection.reports')->name('balances');
    Route::get('/reports/{key}/pdf', [CollectionReportController::class, 'pdf'])->middleware('can:collection.print')->name('reports.pdf');
    // Member services (Step 23).
    Route::get('/passbook-issue', [MemberInstrumentController::class, 'passbookForm'])->middleware('can:collection.collect')->name('passbook-issue');
    Route::post('/passbook-issue', [MemberInstrumentController::class, 'storePassbook'])->middleware('can:collection.collect')->name('passbook-issue.store');
    Route::post('/passbook-issue/{issue}/cancel', [MemberInstrumentController::class, 'cancelPassbook'])->middleware('can:collection.cancel')->name('passbook-issue.cancel');
    Route::get('/cheque-issue', [MemberInstrumentController::class, 'chequeForm'])->middleware('can:collection.collect')->name('cheque-issue');
    Route::post('/cheque-issue', [MemberInstrumentController::class, 'storeCheque'])->middleware('can:collection.collect')->name('cheque-issue.store');
    Route::post('/cheque-issue/{issue}/cancel', [MemberInstrumentController::class, 'cancelCheque'])->middleware('can:collection.cancel')->name('cheque-issue.cancel');
    Route::get('/passbook', [MemberInstrumentController::class, 'passbook'])->middleware('can:collection.view')->name('passbook');
    Route::get('/passbook/pdf', [MemberInstrumentController::class, 'passbookPdf'])->middleware('can:collection.print')->name('passbook.pdf');
});

// --- Accounts (Module 9, Steps 26–30) ---------------------------------------
Route::middleware(['auth', 'password.changed', 'totp'])->prefix('accounts')->name('accounts.')->group(function () {
    // Financial Transaction (Step 26).
    Route::get('/transaction', [VoucherController::class, 'transactionForm'])->middleware('can:accounts.post')->name('transaction');
    Route::post('/transaction', [VoucherController::class, 'storeTransaction'])->middleware('can:accounts.post')->name('transaction.store');
    Route::get('/ledger-transaction', [VoucherController::class, 'ledgerTransactionForm'])->middleware('can:accounts.post')->name('ledger-transaction');
    Route::post('/ledger-transaction', [VoucherController::class, 'storeLedgerTransaction'])->middleware('can:accounts.post')->name('ledger-transaction.store');
    Route::get('/journal', [VoucherController::class, 'journalForm'])->middleware('can:accounts.post')->name('journal');
    Route::post('/journal', [VoucherController::class, 'storeJournal'])->middleware('can:accounts.post')->name('journal.store');
    Route::get('/contra', [VoucherController::class, 'contraForm'])->middleware('can:accounts.post')->name('contra');
    Route::post('/contra', [VoucherController::class, 'storeContra'])->middleware('can:accounts.post')->name('contra.store');
    Route::get('/vouchers', [VoucherController::class, 'index'])->middleware('can:accounts.view')->name('vouchers');
    Route::get('/vouchers/{voucher}', [VoucherController::class, 'show'])->middleware('can:accounts.view')->name('vouchers.show');
    // Approval & Cancel (Step 27).
    Route::get('/authorization', [VoucherController::class, 'authorization'])->middleware('can:accounts.view')->name('authorization');
    Route::post('/vouchers/{voucher}/authorize', [VoucherController::class, 'authorize'])->middleware('can:accounts.authorize')->name('vouchers.authorize');
    Route::get('/cancel', [VoucherController::class, 'cancelForm'])->middleware('can:accounts.cancel')->name('cancel');
    Route::post('/vouchers/{voucher}/cancel', [VoucherController::class, 'cancel'])->middleware('can:accounts.cancel')->name('vouchers.cancel');
    Route::get('/recurring', [AccountMasterController::class, 'recurringIndex'])->middleware('can:accounts.view')->name('recurring');
    Route::post('/recurring', [AccountMasterController::class, 'recurringStore'])->middleware('can:accounts.post')->name('recurring.store');
    Route::put('/recurring/{setup}', [AccountMasterController::class, 'recurringUpdate'])->middleware('can:accounts.post')->name('recurring.update');
    Route::post('/recurring/{setup}/post', [AccountMasterController::class, 'recurringPost'])->middleware('can:accounts.post')->name('recurring.post');
    // Account Management (Steps 26/28).
    Route::get('/ledgers', [AccountMasterController::class, 'ledgerIndex'])->middleware('can:accounts.view')->name('ledgers');
    Route::post('/ledgers', [AccountMasterController::class, 'ledgerStore'])->middleware('can:accounts.post')->name('ledgers.store');
    Route::put('/ledgers/{ledger}', [AccountMasterController::class, 'ledgerUpdate'])->middleware('can:accounts.post')->name('ledgers.update');
    Route::get('/masters/{entity}', [AccountMasterController::class, 'masterIndex'])->middleware('can:accounts.view')->name('masters')->whereIn('entity', ['billing-types', 'setups']);
    Route::post('/masters/{entity}', [AccountMasterController::class, 'masterStore'])->middleware('can:accounts.post')->name('masters.store')->whereIn('entity', ['billing-types', 'setups']);
    Route::put('/masters/{entity}/{id}', [AccountMasterController::class, 'masterUpdate'])->middleware('can:accounts.post')->name('masters.update')->whereIn('entity', ['billing-types', 'setups']);
    Route::delete('/masters/{entity}/{id}', [AccountMasterController::class, 'masterDestroy'])->middleware('can:accounts.post')->name('masters.destroy')->whereIn('entity', ['billing-types', 'setups']);
    Route::get('/groups', [AccountMasterController::class, 'groupIndex'])->middleware('can:accounts.view')->name('groups');
    Route::post('/groups', [AccountMasterController::class, 'groupStore'])->middleware('can:accounts.post')->name('groups.store');
    Route::put('/groups/{group}', [AccountMasterController::class, 'groupUpdate'])->middleware('can:accounts.post')->name('groups.update');
    Route::delete('/groups/{group}', [AccountMasterController::class, 'groupDestroy'])->middleware('can:accounts.post')->name('groups.destroy');
    Route::get('/group-transaction', [AccountMasterController::class, 'groupTransactionForm'])->middleware('can:accounts.post')->name('group-transaction');
    Route::post('/group-transaction', [AccountMasterController::class, 'groupTransactionStore'])->middleware('can:accounts.post')->name('group-transaction.store');
    Route::get('/group-transactions', [AccountMasterController::class, 'groupTransactionList'])->middleware('can:accounts.view')->name('group-transactions');
    // Inter Branch Transaction (Step 28).
    Route::get('/transfer-request', [TransferController::class, 'requestForm'])->middleware('can:accounts.post')->name('transfer-request');
    Route::post('/transfer-request', [TransferController::class, 'storeRequest'])->middleware('can:accounts.post')->name('transfer-request.store');
    Route::get('/transfer-requests', [TransferController::class, 'requestList'])->middleware('can:accounts.view')->name('transfer-requests');
    Route::post('/transfers/{transfer}/approve', [TransferController::class, 'approve'])->middleware('can:accounts.authorize')->name('transfers.approve');
    Route::post('/transfers/{transfer}/receive', [TransferController::class, 'receive'])->middleware('can:accounts.authorize')->name('transfers.receive');
    Route::post('/transfers/{transfer}/close', [TransferController::class, 'close'])->middleware('can:accounts.post')->name('transfers.close');
    Route::get('/branch-balances', [TransferController::class, 'branchBalances'])->middleware('can:accounts.reports')->name('branch-balances');
    Route::get('/branch-statement', [TransferController::class, 'branchStatement'])->middleware('can:accounts.reports')->name('branch-statement');
    // Cash Management (Step 29).
    Route::get('/cash', [TransferController::class, 'cashForm'])->middleware('can:accounts.post')->name('cash');
    Route::post('/cash', [TransferController::class, 'storeCashTransfer'])->middleware('can:accounts.post')->name('cash.store');
    Route::post('/cash/{transfer}/receive', [TransferController::class, 'receiveCash'])->middleware('can:accounts.post')->name('cash.receive');
    Route::post('/cash/{transfer}/cancel', [TransferController::class, 'cancelCash'])->middleware('can:accounts.post')->name('cash.cancel');
    Route::post('/cash/count', [TransferController::class, 'storeCount'])->middleware('can:accounts.post')->name('cash.count');
    Route::get('/cashier-balances', [TransferController::class, 'cashierBalances'])->middleware('can:accounts.reports')->name('cashier-balances');
    Route::get('/cashier-details', [TransferController::class, 'cashierDetails'])->middleware('can:accounts.reports')->name('cashier-details');
    // Reports & Statements (Step 30).
    Route::get('/reports', [AccountReportController::class, 'index'])->middleware('can:accounts.reports')->name('reports');
    Route::get('/reports/pdf', [AccountReportController::class, 'pdf'])->middleware('can:accounts.reports')->name('reports.pdf');
    Route::get('/statements', [AccountReportController::class, 'statement'])->middleware('can:accounts.reports')->name('statements');
});

Route::prefix('reports')->name('reports.')->middleware(['auth', 'password.changed', 'totp'])->group(function () {
    Route::get('/deposits', [DepositReportController::class, 'index'])->middleware('can:reports.view')->name('deposits');
    Route::get('/deposits/{key}/pdf', [DepositReportController::class, 'pdf'])->middleware('can:reports.print')->name('deposits.pdf');
    Route::get('/loans', [LoanReportController::class, 'index'])->middleware('can:reports.view')->name('loans');
    Route::get('/loans/{key}/pdf', [LoanReportController::class, 'pdf'])->middleware('can:reports.print')->name('loans.pdf');
    Route::get('/llp', [LlpController::class, 'index'])->middleware('can:reports.view')->name('llp');
    Route::post('/llp/post', [LlpController::class, 'post'])->middleware('can:reports.view')->name('llp.post');
});

// Private files: the controller itself requires an authenticated user.
Route::get('/files/{path}', [FileController::class, 'show'])
    ->where('path', '.*')
    ->name('files.show');
