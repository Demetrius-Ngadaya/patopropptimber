<?php

use App\Http\Controllers\Api\V1\Finance\AccountController;
use App\Http\Controllers\Api\V1\Finance\BankReconciliationController;
use App\Http\Controllers\Api\V1\Finance\BudgetController;
use App\Http\Controllers\Api\V1\Finance\ExpenseCategoryController;
use App\Http\Controllers\Api\V1\Finance\ExpenseController;
use App\Http\Controllers\Api\V1\Finance\FinancialReportController;
use App\Http\Controllers\Api\V1\Finance\FiscalYearController;
use App\Http\Controllers\Api\V1\Finance\FixedAssetController;
use App\Http\Controllers\Api\V1\Finance\JournalController;
use Illuminate\Support\Facades\Route;

$manage = 'expenses.manage_categories';
Route::crud('expense-categories', ExpenseCategoryController::class, 'expenses', ['index', 'store', 'update', 'destroy', 'restore'], [
    'store' => $manage, 'update' => $manage, 'destroy' => $manage, 'restore' => $manage,
]);
Route::crud('expenses', ExpenseController::class, 'expenses');

/*
| Accounting: chart of accounts, journals, years, statements, budgets,
| fixed assets and reconciliation. Everything is read from / written to the
| double-entry ledger through LedgerService.
*/
Route::prefix('accounting')->name('accounting.')->group(function () {
    Route::prefix('accounts')->name('accounts.')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->middleware('permission:accounting.view|accounting.journal|expenses.create')->name('index');
        Route::post('/', [AccountController::class, 'store'])->middleware('permission:accounting.manage_accounts')->name('store');
        Route::get('/{id}', [AccountController::class, 'show'])->middleware('permission:accounting.view')->whereNumber('id')->name('show');
        Route::put('/{id}', [AccountController::class, 'update'])->middleware('permission:accounting.manage_accounts')->whereNumber('id')->name('update');
        Route::delete('/{id}', [AccountController::class, 'destroy'])->middleware('permission:accounting.manage_accounts')->whereNumber('id')->name('destroy');
        Route::post('/{id}/restore', [AccountController::class, 'restore'])->middleware('permission:accounting.manage_accounts')->whereNumber('id')->name('restore');
        Route::get('/{id}/ledger', [FinancialReportController::class, 'generalLedger'])->middleware('permission:accounting.view')->whereNumber('id')->name('ledger');
    });

    Route::prefix('journals')->name('journals.')->group(function () {
        Route::get('/', [JournalController::class, 'index'])->middleware('permission:accounting.view')->name('index');
        Route::post('/', [JournalController::class, 'store'])->middleware('permission:accounting.journal')->name('store');
        Route::post('/quick', [JournalController::class, 'quick'])->middleware('permission:accounting.journal')->name('quick');
        Route::get('/{id}', [JournalController::class, 'show'])->middleware('permission:accounting.view')->whereNumber('id')->name('show');
        Route::post('/{id}/reverse', [JournalController::class, 'reverse'])->middleware('permission:accounting.reverse')->whereNumber('id')->name('reverse');
    });

    Route::prefix('fiscal-years')->name('fiscal-years.')->group(function () {
        Route::get('/', [FiscalYearController::class, 'index'])->middleware('permission:accounting.view')->name('index');
        Route::post('/', [FiscalYearController::class, 'store'])->middleware('permission:accounting.close_year')->name('store');
        Route::post('/{id}/close', [FiscalYearController::class, 'close'])->middleware('permission:accounting.close_year')->whereNumber('id')->name('close');
        Route::post('/{id}/reopen', [FiscalYearController::class, 'reopen'])->middleware('permission:accounting.close_year')->whereNumber('id')->name('reopen');
        Route::get('/{id}/budgets', [BudgetController::class, 'index'])->middleware('permission:accounting.budgets|accounting.statements')->whereNumber('id')->name('budgets');
        Route::put('/{id}/budgets', [BudgetController::class, 'save'])->middleware('permission:accounting.budgets')->whereNumber('id')->name('budgets.save');
        Route::get('/{id}/budget-vs-actual', [FinancialReportController::class, 'budgetVsActual'])->middleware('permission:accounting.statements')->whereNumber('id')->name('budget-vs-actual');
    });

    Route::prefix('reports')->name('reports.')->middleware('permission:accounting.statements')->group(function () {
        Route::get('/trial-balance', [FinancialReportController::class, 'trialBalance'])->name('trial-balance');
        Route::get('/profit-and-loss', [FinancialReportController::class, 'profitAndLoss'])->name('profit-and-loss');
        Route::get('/balance-sheet', [FinancialReportController::class, 'balanceSheet'])->name('balance-sheet');
        Route::get('/cash-flow', [FinancialReportController::class, 'cashFlow'])->name('cash-flow');
        Route::get('/vat', [FinancialReportController::class, 'vat'])->name('vat');
        Route::get('/aged-receivables', [FinancialReportController::class, 'agedReceivables'])->name('aged-receivables');
        Route::get('/aged-payables', [FinancialReportController::class, 'agedPayables'])->name('aged-payables');
    });

    Route::prefix('fixed-assets')->name('fixed-assets.')->group(function () {
        Route::get('/', [FixedAssetController::class, 'index'])->middleware('permission:accounting.view|accounting.fixed_assets')->name('index');
        Route::post('/', [FixedAssetController::class, 'store'])->middleware('permission:accounting.fixed_assets')->name('store');
        Route::post('/depreciate', [FixedAssetController::class, 'depreciate'])->middleware('permission:accounting.fixed_assets')->name('depreciate');
        Route::get('/{id}', [FixedAssetController::class, 'show'])->middleware('permission:accounting.view|accounting.fixed_assets')->whereNumber('id')->name('show');
        Route::put('/{id}', [FixedAssetController::class, 'update'])->middleware('permission:accounting.fixed_assets')->whereNumber('id')->name('update');
        Route::post('/{id}/dispose', [FixedAssetController::class, 'dispose'])->middleware('permission:accounting.fixed_assets')->whereNumber('id')->name('dispose');
    });

    Route::prefix('reconciliations')->name('reconciliations.')->middleware('permission:accounting.reconcile')->group(function () {
        Route::get('/', [BankReconciliationController::class, 'index'])->name('index');
        Route::post('/', [BankReconciliationController::class, 'store'])->name('store');
        Route::get('/{id}', [BankReconciliationController::class, 'show'])->whereNumber('id')->name('show');
        Route::post('/{id}/lines', [BankReconciliationController::class, 'mark'])->whereNumber('id')->name('mark');
        Route::post('/{id}/complete', [BankReconciliationController::class, 'complete'])->whereNumber('id')->name('complete');
        Route::delete('/{id}', [BankReconciliationController::class, 'destroy'])->whereNumber('id')->name('destroy');
    });
});
