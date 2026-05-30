<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sales\ReferenceDocumentService;
use App\Services\Sales\SalesLookupService;
use Illuminate\Http\Request;

class SalesLookupController extends Controller
{
    public function __construct(
        private SalesController $salesController,
        private SalesDocumentController $salesDocumentController,
        private SalesLookupService $salesLookupService,
        private ReferenceDocumentService $referenceDocumentService
    )
    {
    }

    public function bootstrap(Request $request)
    {
        $includeDocuments = filter_var($request->query('include_documents', false), FILTER_VALIDATE_BOOLEAN);

        $lookupsResponse = $this->lookups($request);
        if ($lookupsResponse->getStatusCode() >= 400) {
            return $lookupsResponse;
        }

        $lookupsPayload = $lookupsResponse->getData(true);
        $documentsPayload = null;

        if ($includeDocuments) {
            $documentsResponse = $this->salesDocumentController->commercialDocuments($request);
            if ($documentsResponse->getStatusCode() >= 400) {
                return $documentsResponse;
            }

            $documentsPayload = $documentsResponse->getData(true);
        }

        return response()->json([
            'lookups' => $lookupsPayload,
            'documents' => $documentsPayload,
        ]);
    }

    public function lookups(Request $request)
    {
        return $this->salesController->lookups($request);
    }

    public function priceTiers(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $rows = $this->referenceDocumentService->listPriceTiers($companyId);

        return response()->json(['data' => $rows]);
    }

    public function referenceDocuments(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));
        $isAdminUser = str_contains($roleCode, 'ADMIN');
        $isSellerUser = $roleProfile === 'SELLER'
            || str_contains($roleProfile, 'VENDED')
            || str_contains($roleCode, 'VENDED')
            || str_contains($roleCode, 'SELLER')
            || str_contains($roleCode, 'VENTA');
        $customerId = (int) $request->query('customer_id', 0);
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $documentKindId = (int) $request->query('document_kind_id', 0);
        $noteKind = strtoupper(trim((string) $request->query('note_kind', '')));
        $limit = (int) $request->query('limit', 1000);

        if ($customerId <= 0) {
            return response()->json([
                'message' => 'customer_id es requerido',
            ], 422);
        }

        if ($noteKind !== '' && !in_array($noteKind, ['CREDIT_NOTE', 'DEBIT_NOTE'], true)) {
            return response()->json([
                'message' => 'note_kind invalido',
            ], 422);
        }

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 10000) {
            $limit = 10000;
        }

        $noteTargetKind = null;
        if ($documentKindId > 0) {
            $catalogRow = $this->findDocumentKindCatalogRowById($documentKindId);
            if (is_array($catalogRow) && !empty($catalogRow['note_target_kind'])) {
                $noteTargetKind = (string) $catalogRow['note_target_kind'];
            }
        }

        $rows = $this->referenceDocumentService->listReferenceDocuments(
            $companyId,
            $customerId,
            ($branchId !== null && $branchId !== '') ? (int) $branchId : null,
            $noteTargetKind,
            $noteKind,
            $limit,
            $isSellerUser ? (int) $authUser->id : ($isAdminUser ? null : (int) $authUser->id)
        );

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function seriesNumbers(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $warehouseId = $request->query('warehouse_id');
        $documentKind = $request->query('document_kind');
        $documentKindId = (int) $request->query('document_kind_id', 0);
        $enabledOnly = filter_var($request->query('enabled_only', true), FILTER_VALIDATE_BOOLEAN);

        $branchIdFilter = ($branchId !== null && $branchId !== '') ? (int) $branchId : null;
        $warehouseIdFilter = ($warehouseId !== null && $warehouseId !== '') ? (int) $warehouseId : null;
        $resolvedDocumentKindId = null;
        $resolvedDocumentKindCode = null;

        if ($documentKindId > 0) {
            $catalogRow = $this->findDocumentKindCatalogRowById($documentKindId);
            if (!is_array($catalogRow)) {
                return response()->json([
                    'message' => 'document_kind_id invalido',
                ], 422);
            }

            $resolvedDocumentKindId = $documentKindId;
            $resolvedDocumentKindCode = (string) ($catalogRow['code'] ?? '');
        } elseif ($documentKind) {
            $catalogRow = $this->findDocumentKindCatalogRowByCode((string) $documentKind);
            if (is_array($catalogRow)) {
                $resolvedDocumentKindId = (int) ($catalogRow['id'] ?? 0);
                $resolvedDocumentKindCode = (string) ($catalogRow['code'] ?? '');
            } else {
                $resolvedDocumentKindCode = (string) $documentKind;
            }
        }

        $rows = $this->salesLookupService->listSeriesNumbers(
            $companyId,
            $branchIdFilter,
            $warehouseIdFilter,
            $enabledOnly,
            $resolvedDocumentKindId,
            $resolvedDocumentKindCode
        );

        return response()->json([
            'data' => $rows,
        ]);
    }

    private function documentKindCatalog(): \Illuminate\Support\Collection
    {
        return collect($this->salesLookupService->listDocumentKindsCatalog())
            ->map(function (array $row) {
                $code = strtoupper(trim((string) ($row['code'] ?? '')));

                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'code' => $code,
                    'label' => (string) ($row['label'] ?? $code),
                    'sunat_code' => (string) ($row['sunat_code'] ?? ''),
                    'is_credit_note' => (bool) ($row['is_credit_note'] ?? false),
                    'is_debit_note' => (bool) ($row['is_debit_note'] ?? false),
                    'note_target_kind' => isset($row['note_target_kind']) && $row['note_target_kind'] !== ''
                        ? strtoupper(trim((string) $row['note_target_kind']))
                        : null,
                    'is_enabled' => (bool) ($row['is_enabled'] ?? true),
                ];
            })
            ->values();
    }

    private function findDocumentKindCatalogRowById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->documentKindCatalog()->firstWhere('id', $id);

        return is_array($row) ? $row : null;
    }

    private function findDocumentKindCatalogRowByCode(string $code): ?array
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            return null;
        }

        $row = $this->documentKindCatalog()->firstWhere('code', $normalized);

        return is_array($row) ? $row : null;
    }
}
