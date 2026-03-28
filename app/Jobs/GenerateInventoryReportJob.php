<?php

namespace App\Jobs;

use App\Support\Inventory\ReportEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateInventoryReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $reportRequestId;

    public function __construct(int $reportRequestId)
    {
        $this->reportRequestId = $reportRequestId;
    }

    public function handle(): void
    {
        ReportEngine::process($this->reportRequestId);
    }
}
