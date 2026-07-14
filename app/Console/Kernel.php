<?php

namespace App\Console;

use App\Services\AppConfig\CompanySubscriptionService;
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
        $subscriptionScheduleConfig = app(CompanySubscriptionService::class)->getGlobalSchedule();
        $subscriptionFrequency = strtoupper(trim((string) ($subscriptionScheduleConfig['alert_frequency'] ?? 'WEEKLY')));
        $subscriptionTime = (string) ($subscriptionScheduleConfig['alert_time'] ?? '08:00');
        $weeklyDigestDay = max(1, min(7, (int) ($subscriptionScheduleConfig['weekly_digest_day'] ?? 1)));
        $monthlyDigestDay = max(1, min(28, (int) ($subscriptionScheduleConfig['monthly_digest_day'] ?? 1)));

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

        [$subscriptionHour, $subscriptionMinute] = $this->parseScheduleTime($subscriptionTime);

        $subscriptionSchedule = $schedule->command('companies:notify-subscription-alerts')
            ->withoutOverlapping();

        if ($subscriptionFrequency === 'DAILY') {
            $subscriptionSchedule->dailyAt(sprintf('%02d:%02d', $subscriptionHour, $subscriptionMinute));
            return;
        }

        if ($subscriptionFrequency === 'MONTHLY') {
            $subscriptionSchedule->monthlyOn($monthlyDigestDay, sprintf('%02d:%02d', $subscriptionHour, $subscriptionMinute));
            return;
        }

        $subscriptionSchedule->weeklyOn($weeklyDigestDay, sprintf('%02d:%02d', $subscriptionHour, $subscriptionMinute));
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

    private function parseScheduleTime(string $time): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $matches) !== 1) {
            return [8, 0];
        }

        $hour = max(0, min(23, (int) $matches[1]));
        $minute = max(0, min(59, (int) $matches[2]));

        return [$hour, $minute];
    }
}
