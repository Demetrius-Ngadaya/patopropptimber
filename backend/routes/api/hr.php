<?php

use App\Http\Controllers\Api\V1\Hr\AttendanceController;
use App\Http\Controllers\Api\V1\Hr\DepartmentController;
use App\Http\Controllers\Api\V1\Hr\EmployeeController;
use App\Http\Controllers\Api\V1\Hr\HolidayController;
use App\Http\Controllers\Api\V1\Hr\LeaveRequestController;
use App\Http\Controllers\Api\V1\Hr\LeaveTypeController;
use App\Http\Controllers\Api\V1\Hr\PayrollController;
use App\Http\Controllers\Api\V1\Hr\PositionController;
use App\Http\Controllers\Api\V1\Hr\SalaryAdvanceController;
use App\Http\Controllers\Api\V1\Hr\SalaryComponentController;
use App\Http\Controllers\Api\V1\Hr\SelfServiceController;
use Illuminate\Support\Facades\Route;

/*
| Human resources and payroll.
*/
Route::prefix('hr')->name('hr.')->group(function () {
    $dept = ['store' => 'departments.manage', 'update' => 'departments.manage', 'destroy' => 'departments.manage', 'restore' => 'departments.manage'];
    Route::crud('departments', DepartmentController::class, 'departments', ['index', 'store', 'show', 'update', 'destroy', 'restore'], $dept);
    Route::crud('positions', PositionController::class, 'departments', ['index', 'store', 'show', 'update', 'destroy', 'restore'], $dept);

    $leave = ['index' => 'leave.view', 'store' => 'leave.manage', 'update' => 'leave.manage', 'destroy' => 'leave.manage', 'restore' => 'leave.manage'];
    Route::crud('leave-types', LeaveTypeController::class, 'leave', ['index', 'store', 'update', 'destroy', 'restore'], $leave);
    Route::crud('holidays', HolidayController::class, 'leave', ['index', 'store', 'update', 'destroy', 'restore'], $leave);

    $pay = ['index' => 'payroll.view', 'store' => 'payroll.settings', 'update' => 'payroll.settings', 'destroy' => 'payroll.settings', 'restore' => 'payroll.settings'];
    Route::crud('salary-components', SalaryComponentController::class, 'payroll', ['index', 'store', 'update', 'destroy', 'restore'], $pay);

    Route::prefix('employees')->name('employees.')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->middleware('permission:employees.view')->name('index');
        Route::post('/', [EmployeeController::class, 'store'])->middleware('permission:employees.create')->name('store');
        Route::get('/{id}', [EmployeeController::class, 'show'])->middleware('permission:employees.view')->whereNumber('id')->name('show');
        Route::put('/{id}', [EmployeeController::class, 'update'])->middleware('permission:employees.update')->whereNumber('id')->name('update');
        Route::post('/{id}', [EmployeeController::class, 'update'])->middleware('permission:employees.update')->whereNumber('id')->name('update.multipart'); // with a photo
        Route::delete('/{id}', [EmployeeController::class, 'destroy'])->middleware('permission:employees.delete')->whereNumber('id')->name('destroy');
        Route::post('/{id}/restore', [EmployeeController::class, 'restore'])->middleware('permission:employees.restore')->whereNumber('id')->name('restore');
        Route::post('/{id}/terminate', [EmployeeController::class, 'terminate'])->middleware('permission:employees.terminate')->whereNumber('id')->name('terminate');
        Route::put('/{id}/login', [EmployeeController::class, 'login'])->middleware('permission:employees.update')->whereNumber('id')->name('login');
        Route::put('/{id}/status', [EmployeeController::class, 'status'])->middleware('permission:employees.update')->whereNumber('id')->name('status');
        Route::post('/{id}/documents', [EmployeeController::class, 'uploadDocument'])->middleware('permission:employees.documents')->whereNumber('id')->name('documents.store');
        Route::get('/{id}/documents/{document}', [EmployeeController::class, 'downloadDocument'])->middleware('permission:employees.documents')->whereNumber(['id', 'document'])->name('documents.download');
        Route::delete('/{id}/documents/{document}', [EmployeeController::class, 'deleteDocument'])->middleware('permission:employees.documents')->whereNumber(['id', 'document'])->name('documents.destroy');
    });

    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])->middleware('permission:attendance.view')->name('index');
        Route::post('/', [AttendanceController::class, 'record'])->middleware('permission:attendance.record')->name('record');
        Route::get('/summary', [AttendanceController::class, 'summary'])->middleware('permission:attendance.view')->name('summary');
    });

    Route::prefix('leave')->name('leave.')->group(function () {
        Route::get('/', [LeaveRequestController::class, 'index'])->middleware('permission:leave.view')->name('index');
        Route::post('/', [LeaveRequestController::class, 'store'])->middleware('permission:leave.approve')->name('store');
        Route::get('/balances', [LeaveRequestController::class, 'balances'])->middleware('permission:leave.view')->name('balances');
        Route::put('/balances/{id}', [LeaveRequestController::class, 'adjustBalance'])->middleware('permission:leave.manage')->whereNumber('id')->name('balances.adjust');
        Route::post('/{id}/approve', [LeaveRequestController::class, 'approve'])->middleware('permission:leave.approve')->whereNumber('id')->name('approve');
        Route::post('/{id}/reject', [LeaveRequestController::class, 'reject'])->middleware('permission:leave.approve')->whereNumber('id')->name('reject');
        Route::post('/{id}/cancel', [LeaveRequestController::class, 'cancel'])->middleware('permission:leave.approve')->whereNumber('id')->name('cancel');
        Route::get('/{id}/attachment', [LeaveRequestController::class, 'attachment'])->middleware('permission:leave.view')->whereNumber('id')->name('attachment');
    });

    Route::prefix('payroll')->name('payroll.')->group(function () {
        Route::get('/', [PayrollController::class, 'index'])->middleware('permission:payroll.view')->name('index');
        Route::post('/', [PayrollController::class, 'store'])->middleware('permission:payroll.prepare')->name('store');
        Route::get('/preview', [PayrollController::class, 'preview'])->middleware('permission:payroll.view')->name('preview');
        Route::post('/remittances', [PayrollController::class, 'remit'])->middleware('permission:payroll.pay')->name('remit');
        Route::get('/{id}', [PayrollController::class, 'show'])->middleware('permission:payroll.view')->whereNumber('id')->name('show');
        Route::post('/{id}/recalculate', [PayrollController::class, 'recalculate'])->middleware('permission:payroll.prepare')->whereNumber('id')->name('recalculate');
        Route::post('/{id}/approve', [PayrollController::class, 'approve'])->middleware('permission:payroll.approve')->whereNumber('id')->name('approve');
        Route::post('/{id}/pay', [PayrollController::class, 'pay'])->middleware('permission:payroll.pay')->whereNumber('id')->name('pay');
        Route::post('/{id}/cancel', [PayrollController::class, 'cancel'])->middleware('permission:payroll.approve')->whereNumber('id')->name('cancel');
        Route::get('/{id}/payslips/{payslip}/pdf', [PayrollController::class, 'payslipPdf'])->middleware('permission:payroll.view')->whereNumber(['id', 'payslip'])->name('payslips.pdf');
    });

    Route::prefix('advances')->name('advances.')->middleware('permission:payroll.advances|payroll.approve')->group(function () {
        Route::get('/', [SalaryAdvanceController::class, 'index'])->name('index');
        Route::post('/', [SalaryAdvanceController::class, 'store'])->name('store');
        Route::post('/{id}/decide', [SalaryAdvanceController::class, 'decide'])->whereNumber('id')->name('decide');
        Route::post('/{id}/pay', [SalaryAdvanceController::class, 'pay'])->middleware('permission:payroll.pay')->whereNumber('id')->name('pay');
    });

    // Self-service: always the logged-in user's own record.
    Route::prefix('me')->name('me.')->middleware('permission:hr.self_service')->group(function () {
        Route::get('/', [SelfServiceController::class, 'profile'])->name('profile');
        Route::get('/payslips', [SelfServiceController::class, 'payslips'])->name('payslips');
        Route::get('/payslips/{id}/pdf', [SelfServiceController::class, 'payslipPdf'])->whereNumber('id')->name('payslips.pdf');
        Route::get('/leave', [SelfServiceController::class, 'leave'])->name('leave');
        Route::post('/leave', [SelfServiceController::class, 'applyLeave'])->name('leave.apply');
        Route::post('/leave/{id}/cancel', [SelfServiceController::class, 'cancelLeave'])->whereNumber('id')->name('leave.cancel');
        Route::get('/attendance', [SelfServiceController::class, 'attendance'])->name('attendance');
        Route::post('/attendance/clock-in', [SelfServiceController::class, 'clockIn'])->middleware('throttle:10,1')->name('clock-in');
        Route::post('/attendance/clock-out', [SelfServiceController::class, 'clockOut'])->middleware('throttle:10,1')->name('clock-out');
        Route::get('/advances', [SelfServiceController::class, 'advances'])->name('advances');
        Route::post('/advances', [SelfServiceController::class, 'requestAdvance'])->name('advances.request');
    });
});
