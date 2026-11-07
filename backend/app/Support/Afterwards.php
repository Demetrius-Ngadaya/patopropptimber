<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs slow side work (SMS, WhatsApp, email) after the database transaction
 * has committed and, on the web, after the response has been sent, so a
 * cashier never waits for an SMS gateway. In the console (cron, tests) it
 * runs straight away. Failures are logged, never thrown.
 */
final class Afterwards
{
    public static function run(callable $work): void
    {
        $safe = function () use ($work) {
            try {
                $work();
            } catch (Throwable $e) {
                Log::warning('Deferred work failed: '.$e->getMessage(), ['exception' => $e]);
            }
        };

        DB::afterCommit(function () use ($safe) {
            if (app()->runningInConsole()) {
                $safe();
            } else {
                app()->terminating($safe);
            }
        });
    }
}
