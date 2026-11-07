<?php

use App\Http\Controllers\Api\V1\Notes\NoteController;
use App\Http\Controllers\Api\V1\Notes\StaffMessageController;
use Illuminate\Support\Facades\Route;

// Notes: every staff user (no permission needed); access is decided per note.
Route::prefix('notes')->name('notes.')->group(function () {
    Route::get('/', [NoteController::class, 'index'])->name('index');
    Route::post('/', [NoteController::class, 'store'])->middleware('throttle:60,1')->name('store');
    Route::get('/people', [NoteController::class, 'people'])->name('people');
    Route::get('/attachments/{id}', [NoteController::class, 'download'])->whereNumber('id')->name('attachments.download');
    Route::delete('/attachments/{id}', [NoteController::class, 'removeAttachment'])->whereNumber('id')->name('attachments.destroy');
    Route::get('/{id}', [NoteController::class, 'show'])->whereNumber('id')->name('show');
    Route::put('/{id}', [NoteController::class, 'update'])->whereNumber('id')->name('update');
    Route::delete('/{id}', [NoteController::class, 'destroy'])->whereNumber('id')->name('destroy');
    Route::post('/{id}/pin', [NoteController::class, 'pin'])->whereNumber('id')->name('pin');
    Route::post('/{id}/attachments', [NoteController::class, 'upload'])->middleware('throttle:30,1')->whereNumber('id')->name('attachments.store');
});

// Messages from the administration. Everyone reads their own; sending needs messages.send.
Route::prefix('staff-messages')->name('staff-messages.')->group(function () {
    Route::get('/', [StaffMessageController::class, 'inbox'])->name('inbox');
    Route::get('/pending', [StaffMessageController::class, 'pending'])->name('pending');
    Route::post('/{id}/read', [StaffMessageController::class, 'read'])->whereNumber('id')->name('read');
    Route::post('/{id}/acknowledge', [StaffMessageController::class, 'acknowledge'])->whereNumber('id')->name('acknowledge');
    Route::get('/sent', [StaffMessageController::class, 'sent'])->middleware('permission:messages.send')->name('sent');
    Route::get('/sent/{id}', [StaffMessageController::class, 'show'])->middleware('permission:messages.send')->whereNumber('id')->name('show');
    Route::post('/', [StaffMessageController::class, 'store'])->middleware(['permission:messages.send', 'throttle:20,1'])->name('store');
    Route::delete('/{id}', [StaffMessageController::class, 'destroy'])->middleware('permission:messages.send')->whereNumber('id')->name('destroy');
});
