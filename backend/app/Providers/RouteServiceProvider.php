<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * Standard CRUD + restore routes guarded by "{perm}.{action}" permissions.
         *
         *   Route::crud('units', UnitController::class, 'units');
         *   Route::crud('expense-categories', ExpenseCategoryController::class, 'expenses',
         *       perms: ['store' => 'expenses.manage_categories', ...]);
         */
        Route::macro('crud', function (string $uri, string $controller, string $perm, array $only = ['index', 'store', 'show', 'update', 'destroy', 'restore'], array $perms = []) {
            $defaults = [
                'index' => "{$perm}.view", 'show' => "{$perm}.view", 'store' => "{$perm}.create",
                'update' => "{$perm}.update", 'destroy' => "{$perm}.delete", 'restore' => "{$perm}.restore",
            ];
            $p = fn (string $action) => 'permission:'.($perms[$action] ?? $defaults[$action]);
            $name = str_replace('/', '.', $uri);

            if (in_array('index', $only, true)) {
                Route::get($uri, [$controller, 'index'])->middleware($p('index'))->name("{$name}.index");
            }
            if (in_array('store', $only, true)) {
                Route::post($uri, [$controller, 'store'])->middleware($p('store'))->name("{$name}.store");
            }
            if (in_array('show', $only, true)) {
                Route::get("{$uri}/{id}", [$controller, 'show'])->middleware($p('show'))->whereNumber('id')->name("{$name}.show");
            }
            if (in_array('update', $only, true)) {
                Route::put("{$uri}/{id}", [$controller, 'update'])->middleware($p('update'))->whereNumber('id')->name("{$name}.update");
            }
            if (in_array('destroy', $only, true)) {
                Route::delete("{$uri}/{id}", [$controller, 'destroy'])->middleware($p('destroy'))->whereNumber('id')->name("{$name}.destroy");
            }
            if (in_array('restore', $only, true)) {
                Route::post("{$uri}/{id}/restore", [$controller, 'restore'])->middleware($p('restore'))->whereNumber('id')->name("{$name}.restore");
            }
        });
    }
}
