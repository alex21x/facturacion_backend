<?php

namespace App\Console\Commands;

use App\Support\Inventory\OutboxEngine;
use Illuminate\Console\Command;

class ProcessInventoryOutboxEvents extends Command
{
    protected $signature = 'inventory:process-outbox-events {--limit=100 : Max pending outbox events to process}';

    protected $description = 'Process pending inventory outbox events in batches';

    public function handle(): int
    {
        OutboxEngine::ensureSchema();

        $limit = max(1, (int) $this->option('limit'));
        $result = OutboxEngine::processPending($limit);

        $this->info(sprintf(
            'Outbox processed. selected=%d processed=%d failed=%d',
            (int) $result['selected'],
            (int) $result['processed'],
            (int) $result['failed']
        ));

        return self::SUCCESS;
    }
}
