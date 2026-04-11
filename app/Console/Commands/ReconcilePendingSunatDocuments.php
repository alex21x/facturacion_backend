<?php

namespace App\Console\Commands;

use App\Services\Sales\TaxBridge\TaxBridgeService;
use Illuminate\Console\Command;

class ReconcilePendingSunatDocuments extends Command
{
    protected $signature = 'sales:reconcile-sunat-pending {--limit=30 : Max documents to reconcile per run}';

    protected $description = 'Reconcile tributary documents stuck in pending SUNAT confirmation state';

    public function handle(TaxBridgeService $taxBridgeService): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $result = $taxBridgeService->reconcilePendingDocuments($limit);

        $this->info(sprintf(
            'SUNAT reconcile done. processed=%d accepted=%d rejected=%d pending=%d failed=%d',
            (int) ($result['processed'] ?? 0),
            (int) ($result['accepted'] ?? 0),
            (int) ($result['rejected'] ?? 0),
            (int) ($result['pending'] ?? 0),
            (int) ($result['failed'] ?? 0)
        ));

        return self::SUCCESS;
    }
}
