<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sales\TaxBridge\TaxBridgeAuditService;
use Illuminate\Http\Request;

class TaxBridgeAuditController extends Controller
{
    private TaxBridgeAuditService $auditService;

    public function __construct(TaxBridgeAuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * GET /api/tax-bridge/audit/document/{documentId}
     * Obtener histórico de envíos para un documento específico
     */
    public function getDocumentHistory(Request $request, int $documentId)
    {
        $authUser = $request->attributes->get('auth_user');

        $document = $this->auditService->findCommercialDocumentScope($documentId);

        if (!$document) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $companyId = (int) $document->company_id;
        if (!$this->auditService->canAccessCompanyScope((int) $authUser->company_id, $companyId)) {
            return response()->json(['message' => 'Unauthorized company scope'], 403);
        }

        $traceabilityGate = $this->ensureTraceabilityFeatureEnabled(
            $companyId,
            $document->branch_id !== null ? (int) $document->branch_id : null
        );
        if ($traceabilityGate !== null) {
            return $traceabilityGate;
        }

        $limit = $this->auditService->sanitizeLimit($request->query('limit'), 50, 500);

        $history = $this->auditService->getDocumentHistory($documentId, $limit);

        return response()->json([
            'document_id' => $documentId,
            'count' => count($history),
            'logs' => $history,
        ]);
    }

    /**
     * GET /api/tax-bridge/audit/branch?filters
     * Obtener histórico de envíos filtrado por empresa/rama
     */
    public function getBranchHistory(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchId = $request->query('branch_id') ? (int)$request->query('branch_id') : null;

        $traceabilityGate = $this->ensureTraceabilityFeatureEnabled($companyId, $branchId);
        if ($traceabilityGate !== null) {
            return $traceabilityGate;
        }

        $filters = [
            'tributary_type' => $request->query('tributary_type'),
            'sunat_status' => $request->query('sunat_status'),
            'start_date' => $request->query('start_date'),
            'end_date' => $request->query('end_date'),
            'document_series' => $request->query('document_series'),
            'document_number' => $request->query('document_number'),
            'only_errors' => $request->query('only_errors') === 'true',
        ];

        $limit = $this->auditService->sanitizeLimit($request->query('limit'), 100, 1000);

        $history = $this->auditService->getBranchHistory($companyId, $branchId, $filters, $limit);

        return response()->json([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'applied_filters' => array_filter($filters, fn($v) => $v !== null && $v !== false),
            'count' => count($history),
            'logs' => $history,
        ]);
    }

    /**
     * GET /api/tax-bridge/audit/statistics
     * Obtener estadísticas de envíos por tipo tributario
     */
    public function getStatistics(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchId = $request->query('branch_id') ? (int)$request->query('branch_id') : null;
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $traceabilityGate = $this->ensureTraceabilityFeatureEnabled($companyId, $branchId);
        if ($traceabilityGate !== null) {
            return $traceabilityGate;
        }

        $stats = $this->auditService->getStatistics($companyId, $branchId, $startDate, $endDate);

        return response()->json([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'statistics' => $stats,
        ]);
    }

    /**
     * GET /api/tax-bridge/audit/failures
     * Obtener fallos recientes
     */
    public function getRecentFailures(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchId = $request->query('branch_id') ? (int)$request->query('branch_id') : null;
        $limit = $this->auditService->sanitizeLimit($request->query('limit'), 20, 200);

        $traceabilityGate = $this->ensureTraceabilityFeatureEnabled($companyId, $branchId);
        if ($traceabilityGate !== null) {
            return $traceabilityGate;
        }

        $failures = $this->auditService->getRecentFailures($companyId, $branchId, $limit);

        return response()->json([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'count' => count($failures),
            'recent_failures' => $failures,
        ]);
    }

    /**
     * GET /api/tax-bridge/audit/{logId}
     * Obtener detalles completos de un log (para drawer/modal)
     */
    public function getLogDetails(Request $request, int $logId)
    {
        $authUser = $request->attributes->get('auth_user');

        $scope = $this->auditService->findAuditLogScope($logId);

        if (!$scope) {
            return response()->json(['message' => 'Log not found'], 404);
        }

        $companyId = (int) $scope->company_id;
        if (!$this->auditService->canAccessCompanyScope((int) $authUser->company_id, $companyId)) {
            return response()->json(['message' => 'Unauthorized company scope'], 403);
        }

        $traceabilityGate = $this->ensureTraceabilityFeatureEnabled(
            $companyId,
            $scope->branch_id !== null ? (int) $scope->branch_id : null
        );
        if ($traceabilityGate !== null) {
            return $traceabilityGate;
        }

        $details = $this->auditService->getLogDetails($logId);

        if (!$details) {
            return response()->json(['message' => 'Log not found'], 404);
        }

        return response()->json($details);
    }

    private function ensureTraceabilityFeatureEnabled(int $companyId, ?int $branchId)
    {
        if (!$this->auditService->isTraceabilityEnabledForAudit($companyId, $branchId)) {
            return response()->json([
                'message' => 'La trazabilidad de intentos está deshabilitada por configuración',
            ], 403);
        }

        return null;
    }
}
