<?php

namespace App\Support\Reports;

use App\Support\Inventory\ReportEngine;
use Illuminate\Support\Facades\DB;

class ReportRequestService
{
    private const REPORT_MAP = [
        'INVENTORY_STOCK_SNAPSHOT' => 'STOCK_SNAPSHOT',
        'INVENTORY_KARDEX_PHYSICAL' => 'KARDEX_PHYSICAL',
        'INVENTORY_KARDEX_VALUED' => 'KARDEX_VALUED',
        'INVENTORY_LOT_EXPIRY' => 'LOT_EXPIRY',
        'INVENTORY_CUT' => 'INVENTORY_CUT',
        'SALES_DOCUMENTS_SUMMARY' => 'SALES_DOCUMENTS_SUMMARY',
        'SALES_SUNAT_MONITOR' => 'SALES_SUNAT_MONITOR',
    ];

    public function availableCatalog(): array
    {
        return [
            [
                'code' => 'INVENTORY_STOCK_SNAPSHOT',
                'module' => 'INVENTORY',
                'label' => 'Stock Snapshot',
                'description' => 'Stock actual por almacen y producto',
                'async' => true,
            ],
            [
                'code' => 'INVENTORY_KARDEX_PHYSICAL',
                'module' => 'INVENTORY',
                'label' => 'Kardex Fisico',
                'description' => 'Movimientos fisicos de inventario',
                'async' => true,
            ],
            [
                'code' => 'INVENTORY_KARDEX_VALUED',
                'module' => 'INVENTORY',
                'label' => 'Kardex Valorizado',
                'description' => 'Movimientos valorizados de inventario',
                'async' => true,
            ],
            [
                'code' => 'INVENTORY_LOT_EXPIRY',
                'module' => 'INVENTORY',
                'label' => 'Lotes por vencer',
                'description' => 'Control de vencimientos de lotes',
                'async' => true,
            ],
            [
                'code' => 'INVENTORY_CUT',
                'module' => 'INVENTORY',
                'label' => 'Corte de Inventario',
                'description' => 'Corte consolidado por fecha',
                'async' => true,
            ],
            [
                'code' => 'SALES_DOCUMENTS_SUMMARY',
                'module' => 'SALES',
                'label' => 'Resumen de Comprobantes de Venta',
                'description' => 'Estado comercial y montos por comprobante',
                'async' => true,
            ],
            [
                'code' => 'SALES_SUNAT_MONITOR',
                'module' => 'SALES',
                'label' => 'Monitoreo SUNAT',
                'description' => 'Seguimiento de estados SUNAT y reconciliacion',
                'async' => true,
            ],
        ];
    }

    public function isSupportedCode(string $reportCode): bool
    {
        return array_key_exists(strtoupper(trim($reportCode)), self::REPORT_MAP);
    }

    public function createRequest(int $companyId, ?int $branchId, int $requestedBy, string $reportCode, array $filters): int
    {
        ReportEngine::ensureSchema();

        $normalizedCode = strtoupper(trim($reportCode));
        if (!$this->isSupportedCode($normalizedCode)) {
            throw new \RuntimeException('Unsupported report code');
        }

        $reportType = self::REPORT_MAP[$normalizedCode];

        return (int) DB::table('inventory.report_requests')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'requested_by' => $requestedBy,
            'report_type' => $reportType,
            'filters_json' => json_encode($filters),
            'status' => ReportEngine::STATUS_PENDING,
            'result_json' => null,
            'error_message' => null,
            'requested_at' => now(),
            'started_at' => null,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function listRequests(int $companyId, ?string $status = null, ?string $reportCode = null, int $page = 1, int $perPage = 20): array
    {
        ReportEngine::ensureSchema();

        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));

        $query = DB::table('inventory.report_requests')
            ->where('company_id', $companyId)
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        if ($status !== null && trim($status) !== '') {
            $query->where('status', strtoupper(trim($status)));
        }

        if ($reportCode !== null && trim($reportCode) !== '') {
            $normalizedCode = strtoupper(trim($reportCode));
            if ($this->isSupportedCode($normalizedCode)) {
                $query->where('report_type', self::REPORT_MAP[$normalizedCode]);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $total = (clone $query)->count();
        $rows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $inverse = array_flip(self::REPORT_MAP);
        $data = $rows->map(function ($row) use ($inverse) {
            $reportType = (string) ($row->report_type ?? '');
            $reportCode = $inverse[$reportType] ?? $reportType;

            return [
                'id' => (int) $row->id,
                'company_id' => (int) $row->company_id,
                'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                'requested_by' => (int) $row->requested_by,
                'report_code' => $reportCode,
                'report_type' => $reportType,
                'status' => (string) $row->status,
                'error_message' => $row->error_message,
                'requested_at' => $row->requested_at,
                'started_at' => $row->started_at,
                'finished_at' => $row->finished_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        })->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function showRequest(int $companyId, int $requestId): ?array
    {
        ReportEngine::ensureSchema();

        $row = DB::table('inventory.report_requests')
            ->where('company_id', $companyId)
            ->where('id', $requestId)
            ->first();

        if (!$row) {
            return null;
        }

        $inverse = array_flip(self::REPORT_MAP);
        $reportType = (string) ($row->report_type ?? '');

        return [
            'id' => (int) $row->id,
            'company_id' => (int) $row->company_id,
            'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
            'requested_by' => (int) $row->requested_by,
            'report_code' => $inverse[$reportType] ?? $reportType,
            'report_type' => $reportType,
            'status' => (string) $row->status,
            'filters_json' => is_string($row->filters_json)
                ? (json_decode($row->filters_json, true) ?: [])
                : ((array) $row->filters_json),
            'result_json' => is_string($row->result_json)
                ? (json_decode($row->result_json, true) ?: null)
                : $row->result_json,
            'error_message' => $row->error_message,
            'requested_at' => $row->requested_at,
            'started_at' => $row->started_at,
            'finished_at' => $row->finished_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
