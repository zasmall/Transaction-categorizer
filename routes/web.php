<?php

use App\Http\Controllers\CategorizationRuleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WebhookReceiptController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('webhooks', [WebhookReceiptController::class, 'index'])->name('webhooks.index');

    Route::prefix('clients/{client:slug}')->name('clients.')->scopeBindings()->group(function () {
        Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
        Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
        Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');

        Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::patch('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
        Route::post('transactions/{transaction}/approve', [TransactionController::class, 'approve'])->name('transactions.approve');

        Route::get('review', [ReviewController::class, 'index'])->name('review.index');
        Route::post('review/approve', [ReviewController::class, 'approve'])->name('review.approve');

        Route::post('rules/apply', [CategorizationRuleController::class, 'apply'])->name('rules.apply');
        Route::post('rules/learned', [CategorizationRuleController::class, 'storeLearned'])->name('rules.learned');
        Route::resource('rules', CategorizationRuleController::class)->except('show');

        Route::get('exports', [ExportController::class, 'index'])->name('exports.index');
        Route::get('exports/quickbooks.csv', [ExportController::class, 'download'])->name('exports.download');
        Route::post('exports/mark-exported', [ExportController::class, 'markExported'])->name('exports.mark');
    });
});

require __DIR__.'/settings.php';
