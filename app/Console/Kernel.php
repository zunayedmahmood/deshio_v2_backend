<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\RouteMethodCount::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Auto-cleanup recycle bin - runs daily at 2 AM
        // Permanently deletes items that have been in recycle bin for more than 7 days
        $schedule->call(function () {
            app(\App\Http\Controllers\RecycleBinController::class)->autoCleanup();
        })->dailyAt('02:00')->name('recycle-bin-cleanup');

        // Pathao bulk sender: keeps queued batches moving even if the orders page is closed.
        // The command itself spaces attempts so Pathao stays under 20 orders/minute.
        $schedule->command('pathao:bulk-tick --max=19')
            ->everyMinute()
            ->name('pathao-bulk-tick')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pathao-bulk-tick.log'));

        // Daily branch report — generates yesterday's per-branch CSV at 1 AM
        // Files land in storage/app/reports/  (one CSV per branch)
        $schedule->command('report:daily-branch')
            ->dailyAt('01:00')
            ->name('daily-branch-report')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/daily-branch-report.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
