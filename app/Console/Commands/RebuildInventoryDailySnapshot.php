<?php

namespace App\Console\Commands;

use App\Support\Inventory\ProjectionEngine;
use Illuminate\Console\Command;

class RebuildInventoryDailySnapshot extends Command
{
    protected $signature = 'inventory:rebuild-daily-snapshot {--company-id= : Restrict rebuild to one company} {--date-from= : Rebuild from date YYYY-MM-DD}';

    protected $description = 'Rebuild inventory daily snapshot projection from inventory ledger';

    public function handle(): int
    {
        $companyId = $this->option('company-id');
        $dateFrom = $this->option('date-from');

        $result = ProjectionEngine::rebuildDailySnapshot(
            $companyId !== null && $companyId !== '' ? (int) $companyId : null,
            $dateFrom !== null && $dateFrom !== '' ? (string) $dateFrom : null
        );

        $this->info(sprintf(
            'Daily snapshot rebuilt. deleted=%d inserted=%d',
            (int) $result['deleted'],
            (int) $result['inserted']
        ));

        return self::SUCCESS;
    }
}
