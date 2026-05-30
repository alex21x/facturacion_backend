<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ops\OpsLatencyService;
use Illuminate\Http\Request;

class OpsLatencyController extends Controller
{
    public function __construct(
        private OpsLatencyService $opsLatencyService
    ) {
    }

    public function summary(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $windowMinutes = max(5, min(1440, (int) $request->query('window_minutes', 60)));
        $limit = max(1, min(100, (int) $request->query('limit', 30)));

                $rows = $this->opsLatencyService->summaryByCompanyWindow($companyId, $windowMinutes, $limit);

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
