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
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('inventory:process-report-requests --limit=30')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('inventory:process-outbox-events --limit=200')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('sales:reconcile-sunat-pending --limit=40')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('sales:notify-sunat-exceptions --hours=6 --limit=120')
            ->everyFifteenMinutes()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
