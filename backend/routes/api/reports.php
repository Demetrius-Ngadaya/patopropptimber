<?php

use App\Http\Controllers\Api\V1\Reports\DashboardController;
use App\Http\Controllers\Api\V1\Reports\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.view')->name('dashboard');
Route::get('/dashboard/periods', [DashboardController::class, 'periods'])->middleware('permission:dashboard.view_financials')->name('dashboard.periods');

Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('/reports/{key}', [ReportController::class, 'show'])->where('key', '[a-z_]+')->name('reports.show');
