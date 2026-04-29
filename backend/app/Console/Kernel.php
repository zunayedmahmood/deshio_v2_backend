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
