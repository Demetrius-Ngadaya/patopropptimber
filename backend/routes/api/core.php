<?php

use App\Http\Controllers\Api\V1\Core\AuditController;
use App\Http\Controllers\Api\V1\Core\BranchController;
use App\Http\Controllers\Api\V1\Core\LoginSlideController;
use App\Http\Controllers\Api\V1\Core\LookupController;
use App\Http\Controllers\Api\V1\Core\NotificationController;
use App\Http\Controllers\Api\V1\Core\NotificationRuleController;
use App\Http\Controllers\Api\V1\Core\RoleController;
use App\Http\Controllers\Api\V1\Core\SettingController;
use App\Http\Controllers\Api\V1\Core\SmsController;
use App\Http\Controllers\Api\V1\Core\SystemModuleController;
use App\Http\Controllers\Api\V1\Core\UserController;
use App\Http\Controllers\Api\V1\Core\UserPermissionController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [LookupController::class, 'index'])->name('lookups');
Route::get('/lookups/customers', [LookupController::class, 'customers'])->name('lookups.customers');

Route::crud('branches', BranchController::class, 'branches');

Route::prefix('users')->name('users.')->group(function () {
    Route::get('/', [UserController::class, 'index'])->middleware('permission:users.view|allocations.create')->name('index');
    Route::post('/', [UserController::class, 'store'])->middleware('permission:users.create')->name('store');
    Route::get('/{id}', [UserController::class, 'show'])->middleware('permission:users.view')->whereNumber('id')->name('show');
    Route::put('/{id}', [UserController::class, 'update'])->middleware('permission:users.update')->whereNumber('id')->name('update');
    Route::delete('/{id}', [UserController::class, 'destroy'])->middleware('permission:users.delete')->whereNumber('id')->name('destroy');
    Route::post('/{id}/restore', [UserController::class, 'restore'])->middleware('permission:users.restore')->whereNumber('id')->name('restore');
    Route::post('/{id}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:users.reset_password')->whereNumber('id')->name('reset-password');
    Route::post('/{id}/unlock', [UserController::class, 'unlock'])->middleware('permission:users.reset_password')->whereNumber('id')->name('unlock');
    Route::post('/{id}/two-factor/reset', [UserController::class, 'resetTwoFactor'])->middleware('permission:users.reset_password')->whereNumber('id')->name('two-factor.reset');
    Route::get('/{id}/permissions', [UserPermissionController::class, 'show'])->middleware('permission:users.permissions')->whereNumber('id')->name('permissions.show');
    Route::put('/{id}/permissions', [UserPermissionController::class, 'update'])->middleware('permission:users.permissions')->whereNumber('id')->name('permissions.update');
    Route::post('/{id}/force-logout', [UserController::class, 'forceLogout'])->middleware('permission:users.force_logout')->whereNumber('id')->name('force-logout');
});

Route::get('/roles/permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view')->name('roles.permissions');
Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.view|users.create|users.update|settings.notifications')->name('roles.index');
Route::crud('roles', RoleController::class, 'roles', ['store', 'show', 'update', 'destroy', 'restore']);

Route::get('/settings', [SettingController::class, 'index'])->middleware('permission:settings.view')->name('settings.index');
Route::put('/settings', [SettingController::class, 'update'])->middleware('permission:settings.update')->name('settings.update');
Route::post('/settings/logo', [SettingController::class, 'uploadLogo'])->middleware('permission:settings.update')->name('settings.logo');

Route::prefix('audit')->name('audit.')->group(function () {
    Route::get('/activity', [AuditController::class, 'activity'])->middleware('permission:audit.activity')->name('activity');
    Route::get('/changes', [AuditController::class, 'changes'])->middleware('permission:audit.changes')->name('changes');
    Route::get('/changes/{type}/{id}', [AuditController::class, 'record'])->middleware('permission:audit.changes')->where('type', '[a-z_]+')->whereNumber('id')->name('record');
    Route::get('/logins', [AuditController::class, 'logins'])->middleware('permission:audit.logins')->name('logins');
    Route::get('/sms', [AuditController::class, 'sms'])->middleware('permission:sms.logs')->name('sms');
});

Route::post('/sms/send', [SmsController::class, 'send'])->middleware('permission:sms.send')->name('sms.send');

Route::post('/login-slides/reorder', [LoginSlideController::class, 'reorder'])->middleware('permission:login_slides.update')->name('login-slides.reorder');
Route::crud('login-slides', LoginSlideController::class, 'login_slides', ['index', 'store', 'update', 'destroy', 'restore']);

Route::prefix('system')->name('system.')->middleware('developer')->group(function () {
    Route::get('/modules', [SystemModuleController::class, 'index'])->name('modules.index');
    Route::put('/modules', [SystemModuleController::class, 'update'])->name('modules.update');
});

// The bell. Every staff member; what each one sees depends on their permissions and branch.
Route::prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::get('/summary', [NotificationController::class, 'summary'])->name('summary');
    Route::post('/read-all', [NotificationController::class, 'readAll'])->name('read-all');
    Route::post('/{id}/read', [NotificationController::class, 'read'])->whereNumber('id')->name('read');
});

// Automatic notifications the admin designs.
Route::prefix('notification-rules')->name('notification-rules.')->middleware('permission:settings.notifications')->group(function () {
    Route::get('/', [NotificationRuleController::class, 'index'])->name('index');
    Route::get('/sources', [NotificationRuleController::class, 'sources'])->name('sources');
    Route::post('/', [NotificationRuleController::class, 'store'])->name('store');
    Route::post('/preview', [NotificationRuleController::class, 'preview'])->name('preview-new');
    Route::get('/{id}', [NotificationRuleController::class, 'show'])->whereNumber('id')->name('show');
    Route::put('/{id}', [NotificationRuleController::class, 'update'])->whereNumber('id')->name('update');
    Route::delete('/{id}', [NotificationRuleController::class, 'destroy'])->whereNumber('id')->name('destroy');
    Route::post('/{id}/preview', [NotificationRuleController::class, 'preview'])->whereNumber('id')->name('preview');
    Route::post('/{id}/run', [NotificationRuleController::class, 'run'])->whereNumber('id')->name('run');
});
