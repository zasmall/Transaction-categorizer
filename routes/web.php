<?php

use App\Http\Controllers\CategorizationRuleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('clients/{client:slug}')->name('clients.')->scopeBindings()->group(function () {
        Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
        Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
        Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');

        Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::patch('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');

        Route::post('rules/apply', [CategorizationRuleController::class, 'apply'])->name('rules.apply');
        Route::resource('rules', CategorizationRuleController::class)->except('show');
    });
});

require __DIR__.'/settings.php';
