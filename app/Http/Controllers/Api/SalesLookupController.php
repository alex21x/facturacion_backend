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
        $authUser  = $request->attributes->get('auth_user');
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

    public function topProducts(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $limit     = max(1, min(12, (int) $request->query('limit', 4)));
        $days      = max(7, min(365, (int) $request->query('days', 30)));

        $since = now()->subDays($days)->format('Y-m-d H:i:s');

        // Aggregate sold qty per product from commercial document items,
        // joining active inventory product data so the response matches
        // the InventoryProduct type expected by the frontend.
        $rows = \DB::table('sales.commercial_document_items as cdi')
            ->join('sales.commercial_documents as cd', function ($j) use ($companyId) {
                $j->on('cd.id', '=', 'cdi.document_id')
                  ->where('cd.company_id', $companyId)
                                    ->whereNotIn('cd.document_kind', ['CREDIT_NOTE', 'DEBIT_NOTE']);
            })
            ->join('inventory.products as p', function ($j) {
                $j->on('p.id', '=', 'cdi.product_id')
                  ->whereNull('p.deleted_at')
                  ->where('p.status', 1);
            })
            ->leftJoin('inventory.product_units as pu', function ($j) {
                $j->on('pu.unit_id', '=', 'p.unit_id')
                  ->on('pu.product_id', '=', 'p.id')
                  ->where('pu.is_base', true);
            })
            ->leftJoin('inventory.units as u', 'u.id', '=', 'p.unit_id')
            ->leftJoin('inventory.product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->where('cd.issue_at', '>=', $since)
            ->whereNotNull('cdi.product_id')
            ->select([
                'p.id',
                'p.unit_id',
                'p.sku',
                'p.barcode',
                'p.name',
                \DB::raw("COALESCE(CAST(p.sale_price AS TEXT), '0') as sale_price"),
                \DB::raw("COALESCE(CAST(p.cost_price AS TEXT), '0') as cost_price"),
                'p.is_stockable',
                \DB::raw('false as lot_tracking'),
                \DB::raw('false as has_expiration'),
                'p.status',
                'pc.name as category_name',
                'u.code as unit_code',
                'u.name as unit_name',
                'p.sunat_code',
                \DB::raw("COALESCE(p.image_url, '') as image_url"),
                \DB::raw("COALESCE(CAST(p.seller_commission_percent AS TEXT), '0') as seller_commission_percent"),
                \DB::raw("'PRODUCT' as product_nature"),
                \DB::raw("NULL::int as line_id"),
                \DB::raw("NULL::int as brand_id"),
                \DB::raw("NULL::int as location_id"),
                \DB::raw("NULL::int as warranty_id"),
                \DB::raw("NULL as line_name"),
                \DB::raw("NULL as brand_name"),
                \DB::raw("NULL as location_name"),
                \DB::raw("NULL as warranty_name"),
                \DB::raw('SUM(cdi.qty) as total_sold'),
            ])
            ->groupBy([
                'p.id', 'p.unit_id', 'p.sku', 'p.barcode', 'p.name',
                'p.sale_price', 'p.cost_price', 'p.is_stockable', 'p.status',
                'p.sunat_code', 'p.image_url', 'p.seller_commission_percent',
                'pc.name', 'u.code', 'u.name',
            ])
            ->orderByDesc('total_sold')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $rows->values()->all()]);
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
