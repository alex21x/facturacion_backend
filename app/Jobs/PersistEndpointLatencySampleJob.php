<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class PersistEndpointLatencySampleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private array $payload)
    {
    }

    public function handle(): void
    {
        try {
            DB::table('ops.http_endpoint_latency_samples')->insert([
                'company_id' => $this->payload['company_id'] ?? null,
                'method' => (string) ($this->payload['method'] ?? ''),
                'route_uri' => (string) ($this->payload['route_uri'] ?? '/'),
                'endpoint_key' => (string) ($this->payload['endpoint_key'] ?? ''),
                'status_code' => (int) ($this->payload['status_code'] ?? 0),
                'duration_ms' => (float) ($this->payload['duration_ms'] ?? 0),
                'requested_at' => $this->payload['requested_at'] ?? now(),
                'created_at' => $this->payload['created_at'] ?? now(),
                'updated_at' => $this->payload['updated_at'] ?? now(),
            ]);
        } catch (\Throwable $e) {
            // Keep observability best-effort and never fail queue processing.
        }
    }
}
