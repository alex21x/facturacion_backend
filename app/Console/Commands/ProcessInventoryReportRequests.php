<?php

namespace App\Console\Commands;

use App\Support\Inventory\ReportEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessInventoryReportRequests extends Command
{
    protected $signature = 'inventory:process-report-requests {--limit=20 : Max pending requests to process}';

    protected $description = 'Process pending inventory report requests in batches';

    public function handle(): int
    {
        ReportEngine::ensureSchema();

        $limit = max(1, (int) $this->option('limit'));

        $rows = DB::table('inventory.report_requests')
            ->select('id')
            ->where('status', ReportEngine::STATUS_PENDING)
            ->orderBy('requested_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No pending inventory report requests.');
            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $reportId = (int) $row->id;
            $this->line('Processing report request #' . $reportId);
            ReportEngine::process($reportId);
        }

        $this->info('Processed ' . $rows->count() . ' report request(s).');

        return self::SUCCESS;
    }
}
