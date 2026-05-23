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
        if (!$this->envBool('APP_SCHEDULER_ENABLED', true)) {
            return;
        }

        $inventoryReportLimit = max(1, min(300, (int) env('INVENTORY_REPORT_REQUEST_LIMIT', 30)));
        $inventoryOutboxLimit = max(1, min(2000, (int) env('INVENTORY_OUTBOX_LIMIT', 200)));
        $sunatReconcileLimit = max(1, min(200, (int) env('SUNAT_RECONCILE_LIMIT', 40)));
        $sunatExceptionHours = max(1, min(72, (int) env('SUNAT_EXCEPTION_HOURS', 6)));
        $sunatExceptionLimit = max(1, min(500, (int) env('SUNAT_EXCEPTION_LIMIT', 120)));

        $this->scheduleCommand(
            $schedule,
            sprintf('inventory:process-report-requests --limit=%d', $inventoryReportLimit),
            'INVENTORY_REPORT_REQUESTS_ENABLED',
            'INVENTORY_REPORT_REQUESTS_CRON',
            '* * * * *'
        );

        $this->scheduleCommand(
            $schedule,
            sprintf('inventory:process-outbox-events --limit=%d', $inventoryOutboxLimit),
            'INVENTORY_OUTBOX_ENABLED',
            'INVENTORY_OUTBOX_CRON',
            '* * * * *'
        );

        $this->scheduleCommand(
            $schedule,
            sprintf('sales:reconcile-sunat-pending --limit=%d', $sunatReconcileLimit),
            'SUNAT_RECONCILE_ENABLED',
            'SUNAT_RECONCILE_CRON',
            '* * * * *'
        );

        $this->scheduleCommand(
            $schedule,
            sprintf('sales:notify-sunat-exceptions --hours=%d --limit=%d', $sunatExceptionHours, $sunatExceptionLimit),
            'SUNAT_EXCEPTION_NOTIFY_ENABLED',
            'SUNAT_EXCEPTION_NOTIFY_CRON',
            '*/15 * * * *'
        );
    }

    private function scheduleCommand(
        Schedule $schedule,
        string $command,
        string $enabledEnv,
        string $cronEnv,
        string $defaultCron
    ): void {
        if (!$this->envBool($enabledEnv, true)) {
            return;
        }

        $cronExpression = trim((string) env($cronEnv, $defaultCron));
        if ($cronExpression === '') {
            $cronExpression = $defaultCron;
        }

        $schedule->command($command)
            ->cron($cronExpression)
            ->withoutOverlapping();
    }

    private function envBool(string $key, bool $default): bool
    {
        $raw = env($key, $default);

        if (is_bool($raw)) {
            return $raw;
        }

        $value = strtolower(trim((string) $raw));
        if ($value === '') {
            return $default;
        }

        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
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
