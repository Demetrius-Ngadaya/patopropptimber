<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Add ONE cron entry on the server:
|   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
*/

// Sales / credit / expense reports: bell + SMS / WhatsApp / email as chosen (23:00 so the day is almost complete)
Schedule::command('mauzo:send-report daily')->dailyAt('23:00')->withoutOverlapping();
Schedule::command('mauzo:send-report weekly')->weeklyOn(0, '23:05')->withoutOverlapping();
Schedule::command('mauzo:send-report monthly')->lastDayOfMonth('23:10')->withoutOverlapping();
Schedule::command('mauzo:send-report yearly')->yearlyOn(12, 31, '23:15')->withoutOverlapping();

// Notifications the admin designed (Settings → Automatic notifications)
Schedule::command('mauzo:notification-rules')->everyMinute()->withoutOverlapping();

// Morning stock and overdue-debt summaries (bell + chosen channels), and pruning old notifications
Schedule::command('mauzo:daily-alerts')->dailyAt('07:30')->withoutOverlapping();

// Reminders to pay suppliers for credit purchases (on the reminder day and the due date)
Schedule::command('mauzo:purchase-reminders')->dailyAt('08:00')->withoutOverlapping();
// Note reminders and "your shift starts soon"
Schedule::command('mauzo:personal-reminders')->everyMinute()->withoutOverlapping();
// Debt reminders to credit customers
Schedule::command('mauzo:debt-reminders')->dailyAt('09:00')->withoutOverlapping();

// Database backup and housekeeping
Schedule::command('mauzo:backup')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// AI credit scores (weekly) and month-end depreciation.
Schedule::command('mauzo:score-credit')->weeklyOn(1, '02:00')->withoutOverlapping();
Schedule::command('mauzo:depreciate')->monthlyOn(1, '02:30')->withoutOverlapping();
