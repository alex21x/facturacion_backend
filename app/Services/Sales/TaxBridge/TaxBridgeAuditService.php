<?php

namespace App\Services\Sales\TaxBridge;

use App\Infrastructure\Models\Sales\TaxBridgeAuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TaxBridgeAuditService
{
    /**
     * Registrar un envío tributario (REQUEST + RESPONSE)
     */
    public function logDispatch(
        int $companyId,
        ?int $branchId,
        string $tributaryType,
        ?int $documentId,
        ?string $documentKind,
        ?string $documentSeries,
        ?string $documentNumber,
        array $config,
        string $payloadJson,
        ?string $responseBody,
        ?int $httpStatusCode,
        ?float $responseTimeMs,
        array $parsedResponse,
        array $options = []
    ): ?TaxBridgeAuditLog {
        try {
            $sunatStatus = $options['sunat_status'] ?? 'UNKNOWN';
            $errorKind = $options['error_kind'] ?? null;
            $errorMessage = $options['error_message'] ?? null;
            $userId = $options['user_id'] ?? null;
            $username = $options['username'] ?? null;
            $isRetry = $options['is_retry'] ?? false;
            $isManual = $options['is_manual'] ?? false;
            $attemptNumber = $options['attempt_number'] ?? 1;

            $log = TaxBridgeAuditLog::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'document_id' => $documentId,
                'document_kind' => $documentKind,
                'document_series' => $documentSeries,
                'document_number' => $documentNumber,
                'tributary_type' => $tributaryType,
                'bridge_mode' => $config['bridge_mode'] ?? 'PRODUCTION',
                'endpoint_url' => $config['endpoint_url'] ?? null,
                'http_method' => 'POST',
                'content_type' => 'application/x-www-form-urlencoded',
                'request_payload' => $payloadJson,
                'request_size_bytes' => strlen($payloadJson),
                'request_sha1_hash' => sha1($payloadJson),
                'response_body' => $responseBody ? substr($responseBody, 0, 100000) : null, // Limit to 100KB
                'response_size_bytes' => $responseBody ? strlen($responseBody) : null,
                'http_status_code' => $httpStatusCode,
                'response_time_ms' => $responseTimeMs,
                'sunat_status' => $sunatStatus,
                'sunat_code' => $parsedResponse['code'] ?? null,
                'ticket_number' => $parsedResponse['ticket'] ?? null,
                'cdr_code' => $parsedResponse['cdr_code'] ?? null,
                'sunat_message' => $parsedResponse['message'] ?? null,
                'auth_scheme' => $config['auth_scheme'] ?? 'none',
                'error_message' => $errorMessage ? substr($errorMessage, 0, 1000) : null,
                'error_kind' => $errorKind,
                'attempt_number' => $attemptNumber,
                'is_retry' => $isRetry,
                'is_manual_dispatch' => $isManual,
                'initiated_by_user_id' => $userId,
                'initiated_by_username' => $username,
                'sent_at' => Carbon::now(),
                'received_at' => $responseBody ? Carbon::now() : null,
            ]);

            return $log;
        } catch (\Throwable $e) {
            \Log::warning('Failed to log tax bridge audit', [
                'error' => $e->getMessage(),
                'company_id' => $companyId,
                'document_id' => $documentId,
            ]);
            return null;
        }
    }

    /**
     * Obtener histórico de envíos para un documento
     */
    public function getDocumentHistory(int $documentId, int $limit = 50)
    {
        return TaxBridgeAuditLog::forDocument($documentId)
            ->limit($limit)
            ->get()
            ->map(fn ($log) => $this->formatLogForApi($log));
    }

    /**
     * Obtener histórico de envíos para empresa/rama
     */
    public function getBranchHistory(int $companyId, ?int $branchId = null, array $filters = [], int $limit = 100)
    {
        $query = TaxBridgeAuditLog::forBranch($companyId, $branchId);

        // Aplicar filtros
        if (!empty($filters['tributary_type'])) {
            $query->byTributaryType($filters['tributary_type']);
        }
        if (!empty($filters['sunat_status'])) {
            $query->byStatus($filters['sunat_status']);
        }
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->betweenDates($filters['start_date'], $filters['end_date']);
        }
        if (!empty($filters['document_series'])) {
            $query->where('document_series', $filters['document_series']);
        }
        if (!empty($filters['document_number'])) {
            $query->where('document_number', 'like', '%' . $filters['document_number'] . '%');
        }
        if (!empty($filters['only_errors'])) {
            $query->where(function ($q) {
                $q->where('sunat_status', 'REJECTED')
                  ->orWhereRaw("error_kind IS NOT NULL");
            });
        }

        return $query->orderByDesc('sent_at')
            ->limit($limit)
            ->get()
            ->map(fn ($log) => $this->formatLogForApi($log));
    }

    /**
     * Obtener estadísticas por tipo tributario
     */
    public function getStatistics(int $companyId, ?int $branchId = null, $startDate = null, $endDate = null)
    {
        $query = TaxBridgeAuditLog::forBranch($companyId, $branchId);

        if ($startDate && $endDate) {
            $query->betweenDates($startDate, $endDate);
        }

        $stats = $query->selectRaw(
            "tributary_type,
             COUNT(*) as total_sent,
             SUM(CASE WHEN sunat_status = 'ACCEPTED' THEN 1 ELSE 0 END) as accepted,
             SUM(CASE WHEN sunat_status = 'REJECTED' THEN 1 ELSE 0 END) as rejected,
             SUM(CASE WHEN sunat_status = 'PENDING_CONFIRMATION' THEN 1 ELSE 0 END) as pending,
             SUM(CASE WHEN error_kind IS NOT NULL THEN 1 ELSE 0 END) as errors,
             AVG(response_time_ms) as avg_response_time_ms,
             MIN(response_time_ms) as min_response_time_ms,
             MAX(response_time_ms) as max_response_time_ms"
        )
            ->groupBy('tributary_type')
            ->get()
            ->map(function ($row) {
                return [
                    'tributary_type' => $row->tributary_type,
                    'total_sent' => $row->total_sent,
                    'accepted' => $row->accepted ?? 0,
                    'rejected' => $row->rejected ?? 0,
                    'pending' => $row->pending ?? 0,
                    'errors' => $row->errors ?? 0,
                    'success_rate' => $row->total_sent > 0 ? round(($row->accepted / $row->total_sent) * 100, 2) : 0,
                    'response_time_ms' => [
                        'avg' => round($row->avg_response_time_ms ?? 0, 2),
                        'min' => round($row->min_response_time_ms ?? 0, 2),
                        'max' => round($row->max_response_time_ms ?? 0, 2),
                    ],
                ];
            });

        return $stats;
    }

    /**
     * Obtener fallos recientes
     */
    public function getRecentFailures(int $companyId, ?int $branchId = null, int $limit = 20)
    {
        return TaxBridgeAuditLog::forBranch($companyId, $branchId)
            ->where(function ($q) {
                $q->where('sunat_status', 'REJECTED')
                  ->orWhereNotNull('error_kind');
            })
            ->orderByDesc('sent_at')
            ->limit($limit)
            ->get()
            ->map(fn ($log) => $this->formatLogForApi($log));
    }

    /**
     * Obtener detalles completos de un log (para drawer/modal)
     */
    public function getLogDetails(int $logId)
    {
        $log = TaxBridgeAuditLog::find($logId);
        if (!$log) {
            return null;
        }

        return [
            'id' => $log->id,
            'document' => [
                'id' => $log->document_id,
                'kind' => $log->document_kind,
                'series' => $log->document_series,
                'number' => $log->document_number,
                'full_number' => $log->document_series . '-' . $log->document_number,
            ],
            'tributary_type' => $log->tributary_type,
            'attempt' => [
                'number' => $log->attempt_number,
                'is_retry' => $log->is_retry,
                'is_manual' => $log->is_manual_dispatch,
            ],
            'bridge' => [
                'mode' => $log->bridge_mode,
                'endpoint' => $log->endpoint_url,
                'method' => $log->http_method,
                'content_type' => $log->content_type,
            ],
            'request' => [
                'size_bytes' => $log->request_size_bytes,
                'sha1' => $log->request_sha1_hash,
                'payload' => json_decode($log->request_payload, true),
            ],
            'response' => [
                'status_code' => $log->http_status_code,
                'size_bytes' => $log->response_size_bytes,
                'time_ms' => $log->response_time_ms,
                'body' => $log->response_body ? json_decode($log->response_body, true) : null,
            ],
            'sunat' => [
                'status' => $log->sunat_status,
                'code' => $log->sunat_code,
                'message' => $log->sunat_message,
                'ticket' => $log->ticket_number,
                'cdr_code' => $log->cdr_code,
            ],
            'error' => $log->error_kind ? [
                'kind' => $log->error_kind,
                'message' => $log->error_message,
            ] : null,
            'audit' => [
                'initiated_by_user_id' => $log->initiated_by_user_id,
                'initiated_by_username' => $log->initiated_by_username,
                'sent_at' => $log->sent_at?->toIso8601String(),
                'received_at' => $log->received_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Formatear log para vista lista (API response)
     */
    private function formatLogForApi(TaxBridgeAuditLog $log): array
    {
        return [
            'id' => $log->id,
            'document' => $log->document_series . '-' . $log->document_number,
            'document_kind' => $log->document_kind,
            'tributary_type' => $log->tributary_type,
            'status' => $log->sunat_status,
            'http_code' => $log->http_status_code,
            'response_time_ms' => $log->response_time_ms,
            'attempt_number' => $log->attempt_number,
            'is_retry' => $log->is_retry,
            'error_kind' => $log->error_kind,
            'message' => $log->sunat_message ?? $log->error_message,
            'sent_at' => $log->sent_at?->toIso8601String(),
            'initiated_by' => $log->initiated_by_username,
        ];
    }
}
