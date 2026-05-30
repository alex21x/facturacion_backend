<?php

namespace App\Infrastructure\Repositories\Ops;

use App\Domain\Ops\Repositories\OpsLatencyRepositoryInterface;
use Illuminate\Support\Facades\DB;

class OpsLatencyRepository implements OpsLatencyRepositoryInterface
{
    public function isSamplesTableAvailable(): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'ops')
            ->where('table_name', 'http_endpoint_latency_samples')
            ->exists();
    }

    public function summaryByCompanyWindow(int $companyId, int $windowMinutes, int $limit): array
    {
        return DB::select(
            "SELECT
                endpoint_key,
                COUNT(*)::int AS samples,
                ROUND((PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY duration_ms))::numeric, 2) AS p50_ms,
                ROUND((PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms))::numeric, 2) AS p95_ms,
                ROUND((PERCENTILE_CONT(0.99) WITHIN GROUP (ORDER BY duration_ms))::numeric, 2) AS p99_ms,
                ROUND(AVG(duration_ms)::numeric, 2) AS avg_ms,
                ROUND(MAX(duration_ms)::numeric, 2) AS max_ms,
                ROUND(MIN(duration_ms)::numeric, 2) AS min_ms
             FROM ops.http_endpoint_latency_samples
             WHERE company_id = ?
               AND requested_at >= (NOW() - (? * INTERVAL '1 minute'))
             GROUP BY endpoint_key
             ORDER BY p95_ms DESC
             LIMIT ?",
            [$companyId, $windowMinutes, $limit]
        );
    }
}
