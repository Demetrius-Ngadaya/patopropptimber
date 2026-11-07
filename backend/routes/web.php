<?php

use Illuminate\Support\Facades\Route;

// The API has no web UI. The Next.js application is the user interface.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
]));
