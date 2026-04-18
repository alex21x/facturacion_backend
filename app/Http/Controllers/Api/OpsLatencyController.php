<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OpsLatencyController extends Controller
{
    public function summary(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) ($authUser->company_id ?? 0);

        if ($companyId <= 0) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        $windowMinutes = max(5, min(1440, (int) $request->query('window_minutes', 60)));
        $limit = max(1, min(100, (int) $request->query('limit', 30)));

        $rows = DB::select(
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

        return response()->json([
            'window_minutes' => $windowMinutes,
            'limit' => $limit,
            'company_id' => $companyId,
            'captured_endpoints' => count($rows),
            'data' => array_map(static function ($row) {
                return [
                    'endpoint' => (string) $row->endpoint_key,
                    'samples' => (int) $row->samples,
                    'p50_ms' => (float) $row->p50_ms,
                    'p95_ms' => (float) $row->p95_ms,
                    'p99_ms' => (float) $row->p99_ms,
                    'avg_ms' => (float) $row->avg_ms,
                    'min_ms' => (float) $row->min_ms,
                    'max_ms' => (float) $row->max_ms,
                ];
            }, $rows),
        ]);
    }
}
