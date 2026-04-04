<?php

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Sales\CreateCommercialDocumentUseCase;
use App\Application\UseCases\Sales\UpdateCommercialDocumentDraftUseCase;
use App\Application\UseCases\Sales\VoidCommercialDocumentUseCase;
use App\Http\Controllers\Controller;
use App\Services\AppConfig\CompanyIgvRateService;
use App\Services\Sales\Documents\SalesDocumentException;
use App\Services\Sales\TaxBridge\TaxBridgeException;
use App\Services\Sales\TaxBridge\TaxBridgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SalesController extends Controller
{    
    private $stockProjection = [];
    private $lotStockProjection = [];

    public function __construct(
        private CompanyIgvRateService $companyIgvRateService,
        private TaxBridgeService $taxBridgeService,
        private CreateCommercialDocumentUseCase $createCommercialDocumentUseCase,
        private UpdateCommercialDocumentDraftUseCase $updateCommercialDocumentDraftUseCase,
        private VoidCommercialDocumentUseCase $voidCommercialDocumentUseCase
    )
    {
    }

    public function lookups(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
        } else {
            $branchId = null;
        }

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        if ($branchId !== null) {
            $branchExists = DB::table('core.branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->exists();

            if (!$branchExists) {
                return response()->json([
                    'message' => 'Invalid branch scope',
                ], 422);
            }
        }

        $currencies = DB::table('core.currencies')
            ->select('id', 'code', 'name', 'symbol', 'is_default')
            ->where('status', 1)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $paymentMethods = DB::table('core.payment_methods')
            ->select('id', 'code', 'name')
            ->where('status', 1)
            ->orderBy('name')
            ->get();

        $catalog = collect([
            ['code' => 'QUOTATION', 'label' => 'Cotizacion'],
            ['code' => 'SALES_ORDER', 'label' => 'Pedido de Venta'],
            ['code' => 'INVOICE', 'label' => 'Factura'],
            ['code' => 'RECEIPT', 'label' => 'Boleta'],
            ['code' => 'CREDIT_NOTE', 'label' => 'Nota de Credito'],
            ['code' => 'DEBIT_NOTE', 'label' => 'Nota de Debito'],
        ]);

        $featureCodes = $catalog->map(function ($row) {
            return 'DOC_KIND_' . $row['code'];
        })->values()->all();

        $enabledToggles = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->whereIn('feature_code', $featureCodes)
            ->pluck('is_enabled', 'feature_code');

        $documentKinds = $catalog->filter(function ($row) use ($enabledToggles) {
            $featureCode = 'DOC_KIND_' . $row['code'];

            return !$enabledToggles->has($featureCode) || (bool) $enabledToggles->get($featureCode);
        })->values();

        if ($documentKinds->isEmpty()) {
            $documentKinds = $catalog->values();
        }

        $commerceFeatureDefaults = [
            'SALES_SELLER_TO_CASHIER' => false,
            'SALES_CUSTOMER_PRICE_PROFILE' => false,
            'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL' => true,
            'SALES_ANTICIPO_ENABLED' => false,
            'SALES_TAX_BRIDGE' => false,
            'SALES_ALLOW_DRAFT_EDIT' => true,
            'SALES_ALLOW_DOCUMENT_VOID' => true,
            'SALES_ALLOW_VOID_FOR_SELLER' => true,
            'SALES_ALLOW_VOID_FOR_CASHIER' => true,
            'SALES_ALLOW_VOID_FOR_ADMIN' => true,
            'SALES_VOID_REVERSE_STOCK' => true,
        ];
        $commerceFeatureCodes = array_keys($commerceFeatureDefaults);

        $companyFeatureRows = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->whereIn('feature_code', $commerceFeatureCodes)
            ->pluck('is_enabled', 'feature_code');

        $branchFeatureRows = collect();
        if ($branchId !== null) {
            $branchFeatureRows = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereIn('feature_code', $commerceFeatureCodes)
                ->pluck('is_enabled', 'feature_code');
        }

        $commerceFeatures = collect($commerceFeatureCodes)->map(function ($featureCode) use ($companyFeatureRows, $branchFeatureRows, $commerceFeatureDefaults) {
            $branchEnabled = $branchFeatureRows->has($featureCode)
                ? (bool) $branchFeatureRows->get($featureCode)
                : null;
            $companyEnabled = $companyFeatureRows->has($featureCode)
                ? (bool) $companyFeatureRows->get($featureCode)
                : null;
            $defaultEnabled = (bool) ($commerceFeatureDefaults[$featureCode] ?? false);

            $isEnabled = $branchEnabled !== null
                ? $branchEnabled
                : ($companyEnabled ?? $defaultEnabled);

            return [
                'feature_code' => $featureCode,
                'is_enabled' => $isEnabled,
                'company_enabled' => $companyEnabled,
                'branch_enabled' => $branchEnabled,
            ];
        })->values();

        $salesDetraccionEnabled = $this->isFeatureEnabled($companyId, $branchId, 'SALES_DETRACCION_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'SALES_DETRACCION_ENABLED');
        $salesRetencionEnabled = $this->isFeatureEnabled($companyId, $branchId, 'SALES_RETENCION_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'SALES_RETENCION_ENABLED');
        $salesPercepcionEnabled = $this->isFeatureEnabled($companyId, $branchId, 'SALES_PERCEPCION_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'SALES_PERCEPCION_ENABLED');

        $taxCategories = $this->companyIgvRateService->applyActiveRateToTaxCategories(
            $companyId,
            $this->resolveTaxCategories($companyId)->all()
        );

        return response()->json([
            'document_kinds' => $documentKinds,
            'currencies' => $currencies,
            'payment_methods' => $paymentMethods,
            'tax_categories' => $taxCategories,
            'active_igv_rate_percent' => $this->companyIgvRateService->resolveActiveRatePercent($companyId),
            'units' => $this->enabledUnits($companyId),
            'inventory_settings' => $this->inventorySettingsForCompany($companyId),
            'credit_note_reasons' => $this->resolveDocumentNoteReasons('CREDIT_NOTE'),
            'debit_note_reasons' => $this->resolveDocumentNoteReasons('DEBIT_NOTE'),
            'detraccion_service_codes' => $salesDetraccionEnabled ? $this->resolveDetractionServiceCodes() : [],
            'detraccion_min_amount' => $salesDetraccionEnabled ? $this->getDetractionMinAmount($companyId, $branchId) : null,
            'detraccion_account' => $salesDetraccionEnabled ? $this->resolveFeatureAccountInfo($companyId, $branchId, 'SALES_DETRACCION_ENABLED', 'DETRACCION') : null,
            'retencion_types' => $salesRetencionEnabled ? $this->resolveRetencionTypes($companyId, $branchId) : [],
            'retencion_account' => $salesRetencionEnabled ? $this->resolveFeatureAccountInfo($companyId, $branchId, 'SALES_RETENCION_ENABLED', 'RETENCION') : null,
            'retencion_percentage' => $salesRetencionEnabled ? 3.00 : null,
            'percepcion_types' => $salesPercepcionEnabled ? $this->resolvePercepcionTypes($companyId, $branchId) : [],
            'percepcion_account' => $salesPercepcionEnabled ? $this->resolveFeatureAccountInfo($companyId, $branchId, 'SALES_PERCEPCION_ENABLED', 'PERCEPCION') : null,
            'sunat_operation_types' => ($salesDetraccionEnabled || $salesRetencionEnabled || $salesPercepcionEnabled) ? $this->resolveSunatOperationTypes($companyId, $branchId) : [],
            'commerce_features' => $commerceFeatures,
        ]);
    }

    private function isFeatureEnabled(int $companyId, $branchId, string $featureCode): bool
    {
        $branchEnabled = null;
        if ($branchId !== null) {
            $branchToggle = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('feature_code', $featureCode)
                ->first();
            if ($branchToggle) {
                $branchEnabled = (bool) $branchToggle->is_enabled;
            }
        }

        if ($branchEnabled !== null) {
            return $branchEnabled;
        }

        $companyToggle = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->first();

        return $companyToggle ? (bool) $companyToggle->is_enabled : false;
    }

    public function referenceDocuments(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $customerId = (int) $request->query('customer_id', 0);
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $noteKind = strtoupper(trim((string) $request->query('note_kind', '')));
        $limit = (int) $request->query('limit', 100);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

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
        if ($limit > 300) {
            $limit = 300;
        }

        $query = DB::table('sales.commercial_documents as d')
            ->select([
                'd.id',
                'd.customer_id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.total',
                'd.balance_due',
                'd.status',
                DB::raw("COALESCE((
                    SELECT SUM(COALESCE(nd.total, 0))
                    FROM sales.commercial_documents nd
                    WHERE nd.company_id = d.company_id
                      AND nd.document_kind = 'CREDIT_NOTE'
                      AND nd.status NOT IN ('VOID', 'CANCELED')
                      AND COALESCE((nd.metadata->>'source_document_id')::BIGINT, 0) = d.id
                ), 0) as applied_credit_total"),
                DB::raw("COALESCE((
                    SELECT SUM(COALESCE(nd.total, 0))
                    FROM sales.commercial_documents nd
                    WHERE nd.company_id = d.company_id
                      AND nd.document_kind = 'DEBIT_NOTE'
                      AND nd.status NOT IN ('VOID', 'CANCELED')
                      AND COALESCE((nd.metadata->>'source_document_id')::BIGINT, 0) = d.id
                ), 0) as applied_debit_total"),
                DB::raw("EXISTS (
                    SELECT 1
                    FROM sales.commercial_documents nd
                    WHERE nd.company_id = d.company_id
                      AND nd.document_kind = 'CREDIT_NOTE'
                      AND nd.status NOT IN ('VOID', 'CANCELED')
                      AND COALESCE((nd.metadata->>'source_document_id')::BIGINT, 0) = d.id
                ) as has_credit_note"),
                DB::raw("EXISTS (
                    SELECT 1
                    FROM sales.commercial_documents nd
                    WHERE nd.company_id = d.company_id
                      AND nd.document_kind = 'DEBIT_NOTE'
                      AND nd.status NOT IN ('VOID', 'CANCELED')
                      AND COALESCE((nd.metadata->>'source_document_id')::BIGINT, 0) = d.id
                ) as has_debit_note"),
            ])
            ->where('d.company_id', $companyId)
            ->where('d.customer_id', $customerId)
            ->whereIn('d.document_kind', ['INVOICE', 'RECEIPT'])
            ->whereNotIn('d.status', ['VOID', 'CANCELED']);

        if ($branchId !== null && $branchId !== '') {
            $query->where('d.branch_id', (int) $branchId);
        }

        if ($noteKind === 'CREDIT_NOTE') {
            $query->whereRaw("(
                COALESCE(d.total, 0) - COALESCE((
                    SELECT SUM(COALESCE(nd.total, 0))
                    FROM sales.commercial_documents nd
                    WHERE nd.company_id = d.company_id
                      AND nd.document_kind = 'CREDIT_NOTE'
                      AND nd.status NOT IN ('VOID', 'CANCELED')
                      AND COALESCE((nd.metadata->>'source_document_id')::BIGINT, 0) = d.id
                ), 0)
            ) > 0");
        }

        if ($noteKind === 'DEBIT_NOTE') {
            $query->whereRaw("(
                COALESCE(d.total, 0) - COALESCE((
                    SELECT SUM(COALESCE(nd.total, 0))
                    FROM sales.commercial_documents nd
                    WHERE nd.company_id = d.company_id
                      AND nd.document_kind = 'DEBIT_NOTE'
                      AND nd.status NOT IN ('VOID', 'CANCELED')
                      AND COALESCE((nd.metadata->>'source_document_id')::BIGINT, 0) = d.id
                ), 0)
            ) > 0");
        }

        $rows = $query
            ->orderBy('d.issue_at', 'desc')
            ->orderBy('d.id', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function priceTiers(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $rows = DB::table('sales.price_tiers')
            ->select('id', 'code', 'name', 'min_qty', 'max_qty', 'priority', 'status')
            ->where('company_id', $companyId)
            ->orderBy('priority')
            ->orderBy('min_qty')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'min_qty' => $row->min_qty,
                    'max_qty' => $row->max_qty,
                    'priority' => (int) $row->priority,
                    'status' => (int) $row->status,
                ];
            })
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function customerAutocomplete(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $search = trim((string) $request->query('q', ''));
        $limit = (int) $request->query('limit', 12);

        $this->ensureCustomerPriceProfilesTable();

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 30) {
            $limit = 30;
        }

        $query = DB::table('sales.customers as c')
            ->leftJoin('sales.customer_types as ct', 'ct.id', '=', 'c.customer_type_id')
            ->leftJoin('sales.customer_price_profiles as cpp', function ($join) use ($companyId) {
                $join->on('cpp.customer_id', '=', 'c.id')
                    ->where('cpp.company_id', '=', $companyId);
            })
            ->leftJoin('sales.price_tiers as pt', function ($join) use ($companyId) {
                $join->on('pt.id', '=', 'cpp.default_tier_id')
                    ->where('pt.company_id', '=', $companyId);
            })
            ->select([
                'c.id',
                'c.doc_type',
                'c.customer_type_id',
                'ct.name as customer_type_name',
                'ct.sunat_code as customer_type_sunat_code',
                'c.doc_number',
                'c.legal_name',
                'c.trade_name',
                'c.first_name',
                'c.last_name',
                'c.plate',
                'c.address',
                'cpp.default_tier_id',
                'cpp.discount_percent',
                'cpp.status as price_profile_status',
                'pt.code as default_tier_code',
                'pt.name as default_tier_name',
            ])
            ->where('c.company_id', $companyId)
            ->where('c.status', 1)
            ->orderBy('c.legal_name')
            ->limit($limit);

        if ($search !== '') {
            $query->where(function ($nested) use ($search) {
                $nested->where('c.doc_number', 'like', '%' . $search . '%')
                    ->orWhere('c.legal_name', 'like', '%' . $search . '%')
                    ->orWhere('c.trade_name', 'like', '%' . $search . '%')
                    ->orWhere('c.first_name', 'like', '%' . $search . '%')
                    ->orWhere('c.last_name', 'like', '%' . $search . '%')
                    ->orWhere('c.plate', 'like', '%' . $search . '%');
            });
        }

        $rows = $query->get()->map(function ($row) {
            $name = $row->legal_name;

            if (!$name) {
                $name = trim(collect([$row->first_name, $row->last_name])->filter()->implode(' '));
            }

            return [
                'id' => (int) $row->id,
                'doc_type' => $row->doc_type,
                'customer_type_id' => $row->customer_type_id !== null ? (int) $row->customer_type_id : null,
                'customer_type_name' => $row->customer_type_name,
                'customer_type_sunat_code' => $row->customer_type_sunat_code !== null ? (int) $row->customer_type_sunat_code : null,
                'doc_number' => $row->doc_number,
                'name' => $name ?: ('Cliente #' . $row->id),
                'trade_name' => $row->trade_name,
                'plate' => $row->plate,
                'address' => $row->address,
                'default_tier_id' => $row->default_tier_id !== null ? (int) $row->default_tier_id : null,
                'default_tier_code' => $row->default_tier_code,
                'default_tier_name' => $row->default_tier_name,
                'discount_percent' => $row->discount_percent !== null ? (float) $row->discount_percent : 0,
                'price_profile_status' => $row->price_profile_status !== null ? (int) $row->price_profile_status : 1,
            ];
        })->values();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function customers(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $limit = (int) $request->query('limit', 100);

        $this->ensureCustomerPriceProfilesTable();

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 300) {
            $limit = 300;
        }

        $query = DB::table('sales.customers as c')
            ->leftJoin('sales.customer_types as ct', 'ct.id', '=', 'c.customer_type_id')
            ->leftJoin('sales.customer_price_profiles as cpp', function ($join) use ($companyId) {
                $join->on('cpp.customer_id', '=', 'c.id')
                    ->where('cpp.company_id', '=', $companyId);
            })
            ->leftJoin('sales.price_tiers as pt', function ($join) use ($companyId) {
                $join->on('pt.id', '=', 'cpp.default_tier_id')
                    ->where('pt.company_id', '=', $companyId);
            })
            ->select([
                'c.id',
                'c.doc_type',
                'c.customer_type_id',
                'ct.name as customer_type_name',
                'ct.sunat_code as customer_type_sunat_code',
                'c.doc_number',
                'c.legal_name',
                'c.trade_name',
                'c.first_name',
                'c.last_name',
                'c.plate',
                'c.address',
                'c.status',
                'cpp.default_tier_id',
                'cpp.discount_percent',
                'cpp.status as price_profile_status',
                'pt.code as default_tier_code',
                'pt.name as default_tier_name',
            ])
            ->where('c.company_id', $companyId)
            ->orderBy('c.legal_name')
            ->limit($limit);

        if ($search !== '') {
            $query->where(function ($nested) use ($search) {
                $nested->where('c.doc_number', 'like', '%' . $search . '%')
                    ->orWhere('c.legal_name', 'like', '%' . $search . '%')
                    ->orWhere('c.trade_name', 'like', '%' . $search . '%')
                    ->orWhere('c.first_name', 'like', '%' . $search . '%')
                    ->orWhere('c.last_name', 'like', '%' . $search . '%')
                    ->orWhere('c.plate', 'like', '%' . $search . '%');
            });
        }

        if ($status !== null && $status !== '') {
            $query->where('c.status', (int) $status);
        }

        $rows = $query->get()->map(function ($row) {
            $name = $row->legal_name;

            if (!$name) {
                $name = trim(collect([$row->first_name, $row->last_name])->filter()->implode(' '));
            }

            return [
                'id' => (int) $row->id,
                'doc_type' => $row->doc_type,
                'customer_type_id' => $row->customer_type_id !== null ? (int) $row->customer_type_id : null,
                'customer_type_name' => $row->customer_type_name,
                'customer_type_sunat_code' => $row->customer_type_sunat_code !== null ? (int) $row->customer_type_sunat_code : null,
                'doc_number' => $row->doc_number,
                'name' => $name ?: ('Cliente #' . $row->id),
                'trade_name' => $row->trade_name,
                'plate' => $row->plate,
                'address' => $row->address,
                'status' => (int) $row->status,
                'default_tier_id' => $row->default_tier_id !== null ? (int) $row->default_tier_id : null,
                'default_tier_code' => $row->default_tier_code,
                'default_tier_name' => $row->default_tier_name,
                'discount_percent' => $row->discount_percent !== null ? (float) $row->discount_percent : 0,
                'price_profile_status' => $row->price_profile_status !== null ? (int) $row->price_profile_status : 1,
            ];
        })->values();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function customerTypes(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $rows = DB::table('sales.customer_types')
            ->select('id', 'name', 'sunat_code', 'sunat_abbr', 'is_active')
            ->where('is_active', true)
            ->orderBy('sunat_code')
            ->get()
            ->map(fn($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'sunat_code' => (int) $row->sunat_code,
                'sunat_abbr' => $row->sunat_abbr,
                'is_active' => (bool) $row->is_active,
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function createCustomer(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        $this->ensureCustomerPriceProfilesTable();

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'doc_type' => 'nullable|string|max:20',
            'customer_type_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    if (!DB::table('sales.customer_types')->where('id', (int) $value)->exists()) {
                        $fail('El tipo de cliente seleccionado no es válido.');
                    }
                },
            ],
            'doc_number' => 'nullable|string|max:40',
            'legal_name' => 'nullable|string|max:180',
            'trade_name' => 'nullable|string|max:180',
            'first_name' => 'nullable|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'plate' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:250',
            'status' => 'nullable|integer|in:0,1',
            'default_tier_id' => 'nullable|integer|min:1',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'price_profile_status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        if (!array_key_exists('customer_type_id', $payload) && isset($payload['doc_type'])) {
            $docType = trim((string) $payload['doc_type']);
            if ($docType !== '' && is_numeric($docType)) {
                $type = DB::table('sales.customer_types')
                    ->where('sunat_code', (int) $docType)
                    ->where('is_active', true)
                    ->select('id')
                    ->first();

                if ($type) {
                    $payload['customer_type_id'] = (int) $type->id;
                }
            }
        }

        $validatedTierId = $this->resolveValidatedTierId($companyId, $payload['default_tier_id'] ?? null);
        if (array_key_exists('default_tier_id', $payload) && $payload['default_tier_id'] !== null && $validatedTierId === null) {
            return response()->json(['message' => 'Invalid price tier for customer profile'], 422);
        }

        $id = DB::table('sales.customers')->insertGetId([
            'company_id' => $companyId,
            'doc_type' => $payload['doc_type'] ?? null,
            'customer_type_id' => $payload['customer_type_id'] ?? null,
            'doc_number' => $payload['doc_number'] ?? null,
            'legal_name' => $payload['legal_name'] ?? null,
            'trade_name' => $payload['trade_name'] ?? null,
            'first_name' => $payload['first_name'] ?? null,
            'last_name' => $payload['last_name'] ?? null,
            'plate' => $payload['plate'] ?? null,
            'address' => $payload['address'] ?? null,
            'status' => (int) ($payload['status'] ?? 1),
        ]);

        if (
            array_key_exists('default_tier_id', $payload)
            || array_key_exists('discount_percent', $payload)
            || array_key_exists('price_profile_status', $payload)
        ) {
            DB::table('sales.customer_price_profiles')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'customer_id' => (int) $id,
                ],
                [
                    'default_tier_id' => $validatedTierId,
                    'discount_percent' => (float) ($payload['discount_percent'] ?? 0),
                    'status' => (int) ($payload['price_profile_status'] ?? 1),
                ]
            );
        }

        return response()->json(['message' => 'Customer created', 'id' => (int) $id], 201);
    }

    public function updateCustomer(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        $this->ensureCustomerPriceProfilesTable();

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'doc_type' => 'nullable|string|max:20',
            'customer_type_id' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    if ($value !== null && !DB::table('sales.customer_types')->where('id', (int) $value)->exists()) {
                        $fail('El tipo de cliente seleccionado no es válido.');
                    }
                },
            ],
            'doc_number' => 'nullable|string|max:40',
            'legal_name' => 'nullable|string|max:180',
            'trade_name' => 'nullable|string|max:180',
            'first_name' => 'nullable|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'plate' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:250',
            'status' => 'nullable|integer|in:0,1',
            'default_tier_id' => 'nullable|integer|min:1',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'price_profile_status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = DB::table('sales.customers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        $changes = $validator->validated();

        if (!array_key_exists('customer_type_id', $changes) && array_key_exists('doc_type', $changes)) {
            $docType = trim((string) $changes['doc_type']);
            if ($docType !== '' && is_numeric($docType)) {
                $type = DB::table('sales.customer_types')
                    ->where('sunat_code', (int) $docType)
                    ->where('is_active', true)
                    ->select('id')
                    ->first();

                if ($type) {
                    $changes['customer_type_id'] = (int) $type->id;
                }
            }
        }

        $profileRequested =
            array_key_exists('default_tier_id', $changes)
            || array_key_exists('discount_percent', $changes)
            || array_key_exists('price_profile_status', $changes);

        $validatedTierId = null;
        if (array_key_exists('default_tier_id', $changes)) {
            $validatedTierId = $this->resolveValidatedTierId($companyId, $changes['default_tier_id']);

            if ($changes['default_tier_id'] !== null && $validatedTierId === null) {
                return response()->json(['message' => 'Invalid price tier for customer profile'], 422);
            }
        }

        if ($profileRequested) {
            $currentProfile = DB::table('sales.customer_price_profiles')
                ->where('company_id', $companyId)
                ->where('customer_id', $id)
                ->first();

            $currentTierId = $currentProfile ? ($currentProfile->default_tier_id !== null ? (int) $currentProfile->default_tier_id : null) : null;
            $currentDiscount = $currentProfile ? (float) ($currentProfile->discount_percent ?? 0) : 0;
            $currentProfileStatus = $currentProfile ? (int) ($currentProfile->status ?? 1) : 1;

            DB::table('sales.customer_price_profiles')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'customer_id' => $id,
                ],
                [
                    'default_tier_id' => array_key_exists('default_tier_id', $changes)
                        ? $validatedTierId
                        : $currentTierId,
                    'discount_percent' => array_key_exists('discount_percent', $changes)
                        ? (float) $changes['discount_percent']
                        : $currentDiscount,
                    'status' => array_key_exists('price_profile_status', $changes)
                        ? (int) $changes['price_profile_status']
                        : $currentProfileStatus,
                ]
            );
        }

        unset($changes['default_tier_id'], $changes['discount_percent'], $changes['price_profile_status']);

        if (empty($changes) && !$profileRequested) {
            return response()->json(['message' => 'No changes provided'], 422);
        }

        if (!empty($changes)) {
            DB::table('sales.customers')
                ->where('id', $id)
                ->where('company_id', $companyId)
                ->update($changes);
        }

        return response()->json(['message' => 'Customer updated']);
    }

    public function createCommercialDocument(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'cash_register_id' => 'nullable|integer|min:1',
            'document_kind' => 'required|string|in:QUOTATION,SALES_ORDER,INVOICE,RECEIPT,CREDIT_NOTE,DEBIT_NOTE',
            'series' => 'required|string|max:10',
            'issue_at' => 'nullable|date',
            'due_at' => 'nullable|date',
            'customer_id' => 'required|integer|min:1',
            'currency_id' => 'required|integer|min:1',
            'payment_method_id' => 'nullable|integer|min:1',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
            'status' => 'nullable|string|in:DRAFT,APPROVED,ISSUED,VOID,CANCELED',
            'items' => 'required|array|min:1',
            'items.*.line_no' => 'nullable|integer|min:1',
            'items.*.product_id' => 'nullable|integer|min:1',
            'items.*.unit_id' => 'nullable|integer|min:1',
            'items.*.price_tier_id' => 'nullable|integer|min:1',
            'items.*.tax_category_id' => 'nullable|integer|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.qty_base' => 'nullable|numeric|min:0',
            'items.*.conversion_factor' => 'nullable|numeric|min:0.00000001',
            'items.*.base_unit_price' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.wholesale_discount_percent' => 'nullable|numeric|min:0',
            'items.*.price_source' => 'nullable|string|in:MANUAL,TIER,PROFILE',
            'items.*.discount_total' => 'nullable|numeric|min:0',
            'items.*.tax_total' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
            'items.*.total' => 'nullable|numeric|min:0',
            'items.*.metadata' => 'nullable|array',
            'items.*.lots' => 'nullable|array',
            'items.*.lots.*.lot_id' => 'required_with:items.*.lots|integer|min:1',
            'items.*.lots.*.qty' => 'required_with:items.*.lots|numeric|min:0.001',
            'payments' => 'nullable|array',
            'payments.*.payment_method_id' => 'required_with:payments|integer|min:1',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.due_at' => 'nullable|date',
            'payments.*.paid_at' => 'nullable|date',
            'payments.*.status' => 'nullable|string|in:PENDING,PAID,CANCELED',
            'payments.*.notes' => 'nullable|string|max:300',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            return response()->json([
                'message' => $errors->first() ?: 'Validation failed',
                'errors' => $errors,
            ], 422);
        }

        $payload = $validator->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);
        $branchId = array_key_exists('branch_id', $payload) ? $payload['branch_id'] : $authUser->branch_id;
        $warehouseId = $payload['warehouse_id'] ?? null;
        $cashRegisterId = $payload['cash_register_id'] ?? null;

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        if ($branchId !== null) {
            $branchExists = DB::table('core.branches')
                ->where('id', (int) $branchId)
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->exists();

            if (!$branchExists) {
                return response()->json([
                    'message' => 'Invalid branch scope',
                ], 422);
            }
        }

        if ($warehouseId !== null) {
            $warehouseExists = DB::table('inventory.warehouses')
                ->where('id', (int) $warehouseId)
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->when($branchId !== null, function ($query) use ($branchId) {
                    $query->where(function ($nested) use ($branchId) {
                        $nested->where('branch_id', (int) $branchId)
                            ->orWhereNull('branch_id');
                    });
                })
                ->exists();

            if (!$warehouseExists) {
                return response()->json([
                    'message' => 'Invalid warehouse scope',
                ], 422);
            }
        }

        if ($cashRegisterId !== null) {
            $cashRegisterExists = DB::table('sales.cash_registers')
                ->where('id', (int) $cashRegisterId)
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->when($branchId !== null, function ($query) use ($branchId) {
                    $query->where(function ($nested) use ($branchId) {
                        $nested->where('branch_id', (int) $branchId)
                            ->orWhereNull('branch_id');
                    });
                })
                ->exists();

            if (!$cashRegisterExists) {
                return response()->json([
                    'message' => 'Invalid cash register scope',
                ], 422);
            }
        }

        $documentKind = (string) $payload['document_kind'];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        if (in_array($documentKind, ['CREDIT_NOTE', 'DEBIT_NOTE'], true)) {
            $sourceDocumentId = isset($metadata['source_document_id']) ? (int) $metadata['source_document_id'] : 0;

            if ($sourceDocumentId <= 0) {
                return response()->json([
                    'message' => 'Para nota de credito/debito debe indicar documento afectado',
                ], 422);
            }

            $sourceDocument = DB::table('sales.commercial_documents')
                ->select('id', 'customer_id', 'document_kind', 'series', 'number', 'status')
                ->where('id', $sourceDocumentId)
                ->where('company_id', $companyId)
                ->first();

            if (!$sourceDocument) {
                return response()->json([
                    'message' => 'Documento afectado no encontrado',
                ], 422);
            }

            if (!in_array((string) $sourceDocument->document_kind, ['INVOICE', 'RECEIPT'], true)) {
                return response()->json([
                    'message' => 'Solo se puede afectar Factura o Boleta',
                ], 422);
            }

            if (in_array((string) $sourceDocument->status, ['VOID', 'CANCELED'], true)) {
                return response()->json([
                    'message' => 'No se puede afectar un documento anulado/cancelado',
                ], 422);
            }

            if ((int) $sourceDocument->customer_id !== (int) $payload['customer_id']) {
                return response()->json([
                    'message' => 'El documento afectado no corresponde al cliente seleccionado',
                ], 422);
            }

            $noteReasons = $this->resolveDocumentNoteReasons($documentKind);
            if (count($noteReasons) === 0) {
                return response()->json([
                    'message' => 'No hay maestro de tipos de nota configurado',
                ], 422);
            }

            $noteReasonCode = trim((string) ($metadata['note_reason_code'] ?? ''));
            $noteReasonId = isset($metadata['note_reason_id']) ? (int) $metadata['note_reason_id'] : 0;

            $resolvedReason = null;

            if ($noteReasonCode !== '') {
                $resolvedReason = collect($noteReasons)->first(function ($row) use ($noteReasonCode) {
                    return strtoupper((string) ($row['code'] ?? '')) === strtoupper($noteReasonCode);
                });
            }

            if (!$resolvedReason && $noteReasonId > 0) {
                $resolvedReason = collect($noteReasons)->first(function ($row) use ($noteReasonId) {
                    return (int) ($row['id'] ?? 0) === $noteReasonId;
                });
            }

            if (!$resolvedReason || !is_array($resolvedReason)) {
                return response()->json([
                    'message' => 'Debe seleccionar un tipo de nota valido',
                ], 422);
            }

            $payload['metadata'] = array_merge($metadata, [
                'source_document_id' => (int) $sourceDocument->id,
                'source_document_kind' => (string) $sourceDocument->document_kind,
                'source_document_number' => (string) $sourceDocument->series . '-' . (string) $sourceDocument->number,
                'note_reason_id' => (int) ($resolvedReason['id'] ?? 0),
                'note_reason_code' => (string) ($resolvedReason['code'] ?? ''),
                'note_reason_description' => (string) ($resolvedReason['description'] ?? ''),
            ]);
        }

        try {
            $result = $this->createCommercialDocumentUseCase->execute(
                $authUser,
                $payload,
                $companyId,
                $branchId,
                $warehouseId,
                $cashRegisterId
            );
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }

        return response()->json([
            'message' => 'Commercial document created',
            'data' => $result,
        ], 201);
    }

    public function seriesNumbers(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $warehouseId = $request->query('warehouse_id');
        $documentKind = $request->query('document_kind');
        $enabledOnly = filter_var($request->query('enabled_only', true), FILTER_VALIDATE_BOOLEAN);

        $query = DB::table('sales.series_numbers')
            ->where('company_id', $companyId)
            ->orderBy('document_kind')
            ->orderBy('series');

        if ($branchId !== null && $branchId !== '') {
            $query->where('branch_id', (int) $branchId);
        }

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('warehouse_id', (int) $warehouseId);
        }

        if ($documentKind) {
            $query->where('document_kind', $documentKind);
        }

        if ($enabledOnly) {
            $query->where('is_enabled', true);
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function updateCommercialDocument(Request $request, $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $authUser->company_id;
        $documentId = (int) $id;

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'cash_register_id' => 'nullable|integer|min:1',
            'due_at' => 'nullable|date',
            'customer_id' => 'nullable|integer|min:1',
            'currency_id' => 'nullable|integer|min:1',
            'payment_method_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
            'items' => 'nullable|array|min:1',
            'items.*.line_no' => 'nullable|integer|min:1',
            'items.*.product_id' => 'nullable|integer|min:1',
            'items.*.unit_id' => 'nullable|integer|min:1',
            'items.*.price_tier_id' => 'nullable|integer|min:1',
            'items.*.tax_category_id' => 'nullable|integer|min:1',
            'items.*.description' => 'required_with:items|string|max:500',
            'items.*.qty' => 'required_with:items|numeric|min:0.001',
            'items.*.qty_base' => 'nullable|numeric|min:0',
            'items.*.conversion_factor' => 'nullable|numeric|min:0.00000001',
            'items.*.base_unit_price' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.wholesale_discount_percent' => 'nullable|numeric|min:0',
            'items.*.price_source' => 'nullable|string|in:MANUAL,TIER,PROFILE',
            'items.*.discount_total' => 'nullable|numeric|min:0',
            'items.*.tax_total' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
            'items.*.total' => 'nullable|numeric|min:0',
            'items.*.metadata' => 'nullable|array',
            'items.*.lots' => 'nullable|array',
            'items.*.lots.*.lot_id' => 'required_with:items.*.lots|integer|min:1',
            'items.*.lots.*.qty' => 'required_with:items.*.lots|numeric|min:0.001',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            return response()->json([
                'message' => $errors->first() ?: 'Validation failed',
                'errors' => $errors,
            ], 422);
        }

        $payload = $validator->validated();

        try {
            $result = $this->updateCommercialDocumentDraftUseCase->execute($authUser, $companyId, $documentId, $payload);
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }

        return response()->json([
            'message' => 'Documento comercial actualizado',
            'data' => $result,
        ]);
    }

    public function voidCommercialDocument(Request $request, $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $authUser->company_id;
        $documentId = (int) $id;

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500',
            'void_at' => 'nullable|date',
            'sunat_void_status' => 'nullable|string|max:40',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            return response()->json([
                'message' => $errors->first() ?: 'Validation failed',
                'errors' => $errors,
            ], 422);
        }

        $payload = $validator->validated();

        try {
            $result = $this->voidCommercialDocumentUseCase->execute($authUser, $companyId, $documentId, $payload);
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }

        return response()->json([
            'message' => 'Documento comercial anulado',
            'data' => $result,
        ]);
    }

    public function commercialDocuments(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $filters = [
            'branch_id' => $request->query('branch_id', $authUser->branch_id),
            'warehouse_id' => $request->query('warehouse_id'),
            'cash_register_id' => $request->query('cash_register_id'),
            'document_kind' => $request->query('document_kind'),
            'status' => $request->query('status'),
            'conversion_state' => $request->query('conversion_state'),
            'customer' => trim((string) $request->query('customer', '')),
            'issue_date_from' => $request->query('issue_date_from'),
            'issue_date_to' => $request->query('issue_date_to'),
            'series' => trim((string) $request->query('series', '')),
            'number' => trim((string) $request->query('number', '')),
        ];
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('per_page', $request->query('limit', 10));

        if ($page < 1) {
            $page = 1;
        }

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('core.payment_methods as pm', 'pm.id', '=', 'd.payment_method_id')
            ->select([
                'd.id',
                'd.company_id',
                'd.branch_id',
                'd.document_kind',
                DB::raw("CASE d.document_kind
                    WHEN 'QUOTATION'   THEN 'Cotizacion'
                    WHEN 'SALES_ORDER' THEN 'Nota de Pedido'
                    WHEN 'INVOICE'     THEN 'Factura'
                    WHEN 'RECEIPT'     THEN 'Boleta'
                    WHEN 'CREDIT_NOTE' THEN 'Nota de Credito'
                    WHEN 'DEBIT_NOTE'  THEN 'Nota de Debito'
                    ELSE d.document_kind END as document_kind_label"),
                'd.series',
                'd.number',
                'd.issue_at',
                'd.created_at',
                'd.status',
                DB::raw("CASE d.status
                    WHEN 'DRAFT'    THEN 'Borrador'
                    WHEN 'APPROVED' THEN 'Aprobado'
                    WHEN 'ISSUED'   THEN 'Emitido'
                    WHEN 'VOID'     THEN 'Anulado'
                    WHEN 'CANCELED' THEN 'Cancelado'
                    ELSE d.status END as status_label"),
                'd.external_status',
                DB::raw("COALESCE((d.metadata->>'sunat_status'), '') as sunat_status"),
                'd.total',
                'd.balance_due',
                                DB::raw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0) as source_document_id"),
                                DB::raw("(
                                        SELECT dsrc.document_kind
                                        FROM sales.commercial_documents dsrc
                                        WHERE dsrc.company_id = d.company_id
                                            AND dsrc.id = COALESCE((d.metadata->>'source_document_id')::BIGINT, 0)
                                        LIMIT 1
                                ) as source_document_kind"),
                DB::raw("COALESCE(pm.name, 'Sin metodo de pago') as payment_method_name"),
                                DB::raw("EXISTS (
                                        SELECT 1
                                        FROM sales.commercial_documents d2
                                        WHERE d2.company_id = d.company_id
                                            AND d2.document_kind IN ('INVOICE', 'RECEIPT')
                                            AND d2.status NOT IN ('VOID', 'CANCELED')
                                            AND COALESCE((d2.metadata->>'source_document_id')::BIGINT, 0) = d.id
                                ) as has_tributary_conversion"),
                                DB::raw("EXISTS (
                                                        SELECT 1
                                                        FROM sales.commercial_documents d3
                                                        WHERE d3.company_id = d.company_id
                                                            AND d3.document_kind = 'SALES_ORDER'
                                                            AND d3.status NOT IN ('VOID', 'CANCELED')
                                                            AND COALESCE((d3.metadata->>'source_document_id')::BIGINT, 0) = d.id
                                                ) as has_order_conversion"),
                                DB::raw("EXISTS (
                                                        SELECT 1
                                                        FROM sales.commercial_document_items di
                                                        WHERE di.document_id = d.id
                                                ) as has_items"),
                DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
            ])
            ->where('d.company_id', $companyId);

        $this->applyCommercialDocumentFilters($query, $filters);

        $total = (clone $query)->count('d.id');
        $lastPage = (int) max(1, ceil($total / $limit));
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $rows = $query
            ->orderBy('d.issue_at', 'desc')
            ->orderBy('d.id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $limit,
                'total' => (int) $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function exportCommercialDocuments(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $format = strtolower(trim((string) $request->query('format', 'csv')));
        $filters = [
            'branch_id' => $request->query('branch_id', $authUser->branch_id),
            'warehouse_id' => $request->query('warehouse_id'),
            'cash_register_id' => $request->query('cash_register_id'),
            'document_kind' => $request->query('document_kind'),
            'status' => $request->query('status'),
            'conversion_state' => $request->query('conversion_state'),
            'customer' => trim((string) $request->query('customer', '')),
            'issue_date_from' => $request->query('issue_date_from'),
            'issue_date_to' => $request->query('issue_date_to'),
            'series' => trim((string) $request->query('series', '')),
            'number' => trim((string) $request->query('number', '')),
        ];

        $max = (int) $request->query('max', 5000);
        if ($max < 1) {
            $max = 1;
        }
        if ($max > 20000) {
            $max = 20000;
        }

        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('core.payment_methods as pm', 'pm.id', '=', 'd.payment_method_id')
            ->select([
                'd.id',
                'd.document_kind',
                DB::raw("CASE d.document_kind
                    WHEN 'QUOTATION'   THEN 'Cotizacion'
                    WHEN 'SALES_ORDER' THEN 'Nota de Pedido'
                    WHEN 'INVOICE'     THEN 'Factura'
                    WHEN 'RECEIPT'     THEN 'Boleta'
                    WHEN 'CREDIT_NOTE' THEN 'Nota de Credito'
                    WHEN 'DEBIT_NOTE'  THEN 'Nota de Debito'
                    ELSE d.document_kind END as document_kind_label"),
                'd.series',
                'd.number',
                'd.issue_at',
                'd.status',
                DB::raw("CASE d.status
                    WHEN 'DRAFT'    THEN 'Borrador'
                    WHEN 'APPROVED' THEN 'Aprobado'
                    WHEN 'ISSUED'   THEN 'Emitido'
                    WHEN 'VOID'     THEN 'Anulado'
                    WHEN 'CANCELED' THEN 'Cancelado'
                    ELSE d.status END as status_label"),
                'd.total',
                'd.balance_due',
                DB::raw("COALESCE(pm.name, 'Sin metodo de pago') as payment_method_name"),
                DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
            ])
            ->where('d.company_id', $companyId);

        $this->applyCommercialDocumentFilters($query, $filters);

        $rows = $query
            ->orderBy('d.issue_at', 'desc')
            ->orderBy('d.id', 'desc')
            ->limit($max)
            ->get();

        if ($format === 'json') {
            return response()->json([
                'data' => $rows,
                'meta' => [
                    'count' => (int) $rows->count(),
                    'max' => $max,
                ],
            ]);
        }

        $filename = 'reporte_ventas_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            // UTF-8 BOM for Excel compatibility.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'ID',
                'Documento',
                'Serie',
                'Numero',
                'Fecha Emision',
                'Cliente',
                'Forma de Pago',
                'Estado',
                'Total',
                'Saldo',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($out, [
                    (int) $row->id,
                    (string) ($row->document_kind_label ?? $row->document_kind),
                    (string) $row->series,
                    (string) $row->number,
                    $row->issue_at ? (string) $row->issue_at : '',
                    (string) ($row->customer_name ?? ''),
                    (string) ($row->payment_method_name ?? 'Sin metodo de pago'),
                    (string) ($row->status_label ?? $row->status),
                    number_format((float) ($row->total ?? 0), 2, '.', ''),
                    number_format((float) ($row->balance_due ?? 0), 2, '.', ''),
                ], ';');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function applyCommercialDocumentFilters($query, array $filters): void
    {
        $branchId = $filters['branch_id'] ?? null;
        $warehouseId = $filters['warehouse_id'] ?? null;
        $cashRegisterId = $filters['cash_register_id'] ?? null;
        $documentKind = $filters['document_kind'] ?? null;
        $status = $filters['status'] ?? null;
        $conversionState = $filters['conversion_state'] ?? null;
        $customer = trim((string) ($filters['customer'] ?? ''));
        $issueDateFrom = $filters['issue_date_from'] ?? null;
        $issueDateTo = $filters['issue_date_to'] ?? null;
        $series = trim((string) ($filters['series'] ?? ''));
        $number = trim((string) ($filters['number'] ?? ''));

        if ($branchId !== null && $branchId !== '') {
            $query->where('d.branch_id', (int) $branchId);
        }

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('d.warehouse_id', (int) $warehouseId);
        }

        if ($cashRegisterId !== null && $cashRegisterId !== '') {
            $query->whereRaw("COALESCE((d.metadata->>'cash_register_id')::BIGINT, 0) = ?", [(int) $cashRegisterId]);
        }

        if ($documentKind) {
            $kinds = array_values(array_filter(array_map('trim', explode(',', (string) $documentKind))));

            if (count($kinds) > 1) {
                $query->whereIn('d.document_kind', $kinds);
            } else {
                $query->where('d.document_kind', $kinds[0] ?? (string) $documentKind);
            }
        }

        if ($status) {
            $query->where('d.status', (string) $status);
        }

        if ($customer !== '') {
            $like = '%' . $customer . '%';
            $query->where(function ($nested) use ($like) {
                $nested->where('c.legal_name', 'ilike', $like)
                    ->orWhereRaw("CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) ILIKE ?", [$like])
                    ->orWhere('c.doc_number', 'ilike', $like);
            });
        }

        if ($issueDateFrom) {
            $query->whereDate('d.issue_at', '>=', $issueDateFrom);
        }

        if ($issueDateTo) {
            $query->whereDate('d.issue_at', '<=', $issueDateTo);
        }

        if ($series !== '') {
            $query->where('d.series', 'ilike', '%' . $series . '%');
        }

        if ($number !== '') {
            if (ctype_digit($number)) {
                $query->where('d.number', (int) $number);
            } else {
                $query->whereRaw('CAST(d.number AS TEXT) ILIKE ?', ['%' . $number . '%']);
            }
        }

        if ($conversionState === 'PENDING') {
            $query
                ->whereIn('d.document_kind', ['QUOTATION', 'SALES_ORDER'])
                ->whereRaw("NOT EXISTS (
                    SELECT 1
                    FROM sales.commercial_documents d2
                    WHERE d2.company_id = d.company_id
                      AND d2.document_kind IN ('INVOICE', 'RECEIPT')
                      AND d2.status NOT IN ('VOID', 'CANCELED')
                      AND COALESCE((d2.metadata->>'source_document_id')::BIGINT, 0) = d.id
                )")
                ->whereRaw("(
                    d.document_kind <> 'QUOTATION'
                    OR NOT EXISTS (
                        SELECT 1
                        FROM sales.commercial_documents d3
                        WHERE d3.company_id = d.company_id
                          AND d3.document_kind = 'SALES_ORDER'
                          AND d3.status NOT IN ('VOID', 'CANCELED')
                          AND COALESCE((d3.metadata->>'source_document_id')::BIGINT, 0) = d.id
                    )
                )");
        }

        if ($conversionState === 'CONVERTED') {
            $query
                ->whereIn('d.document_kind', ['QUOTATION', 'SALES_ORDER'])
                ->whereRaw("(
                    EXISTS (
                        SELECT 1
                        FROM sales.commercial_documents d2
                        WHERE d2.company_id = d.company_id
                          AND d2.document_kind IN ('INVOICE', 'RECEIPT')
                          AND d2.status NOT IN ('VOID', 'CANCELED')
                          AND COALESCE((d2.metadata->>'source_document_id')::BIGINT, 0) = d.id
                    )
                    OR (
                        d.document_kind = 'QUOTATION'
                        AND EXISTS (
                            SELECT 1
                            FROM sales.commercial_documents d3
                            WHERE d3.company_id = d.company_id
                              AND d3.document_kind = 'SALES_ORDER'
                              AND d3.status NOT IN ('VOID', 'CANCELED')
                              AND COALESCE((d3.metadata->>'source_document_id')::BIGINT, 0) = d.id
                        )
                    )
                )");
        }
    }

    public function convertCommercialDocument(Request $request, $id)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'target_document_kind' => 'required|string|in:INVOICE,RECEIPT,SALES_ORDER',
            'series' => 'nullable|string|max:10',
            'issue_at' => 'nullable|date',
            'due_at' => 'nullable|date',
            'cash_register_id' => 'nullable|integer|min:1',
            'payment_method_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
            'status' => 'nullable|string|in:ISSUED,DRAFT',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            return response()->json([
                'message' => $errors->first() ?: 'Validation failed',
                'errors' => $errors,
            ], 422);
        }

        $payload = $validator->validated();
        $companyId = (int) $authUser->company_id;
        $sourceId = (int) $id;
        $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));

        if ($roleCode === '' && $roleProfile === '') {
            $roleContext = $this->resolveAuthRoleContext((int) $authUser->id, $companyId);
            $roleCode = strtoupper(trim((string) ($roleContext['role_code'] ?? '')));
            $roleProfile = strtoupper(trim((string) ($roleContext['role_profile'] ?? '')));
        }

        $source = DB::table('sales.commercial_documents')
            ->where('id', $sourceId)
            ->where('company_id', $companyId)
            ->first();

        if (!$source) {
            return response()->json([
                'message' => 'Documento origen no encontrado',
            ], 404);
        }

        if (!in_array((string) $source->document_kind, ['QUOTATION', 'SALES_ORDER'], true)) {
            return response()->json([
                'message' => 'Solo se puede convertir cotizacion o pedido de venta',
            ], 422);
        }

        $sourceBranchId = $source->branch_id !== null ? (int) $source->branch_id : null;
        if ($this->isCommerceFeatureEnabledForContext($companyId, $sourceBranchId, 'SALES_SELLER_TO_CASHIER') && !$this->isCashierActor($roleProfile, $roleCode)) {
            return response()->json([
                'message' => 'Solo caja puede convertir pedidos en este modo de venta.',
            ], 403);
        }

        if (in_array((string) $source->status, ['VOID', 'CANCELED'], true)) {
            return response()->json([
                'message' => 'No se puede convertir un documento anulado/cancelado',
            ], 422);
        }

        $targetDocumentKind = (string) $payload['target_document_kind'];
        $targetStatus = isset($payload['status']) && $payload['status'] !== null
            ? (string) $payload['status']
            : 'ISSUED';

        if ($targetDocumentKind === 'SALES_ORDER' && strtoupper($targetStatus) !== 'ISSUED') {
            $targetStatus = 'ISSUED';
        }

        if ((string) $source->document_kind === 'SALES_ORDER' && $targetDocumentKind === 'SALES_ORDER') {
            return response()->json([
                'message' => 'El documento origen ya es una nota de pedido',
            ], 422);
        }

        $alreadyConverted = DB::table('sales.commercial_documents as d')
            ->where('d.company_id', $companyId)
            ->where('d.document_kind', $targetDocumentKind)
            ->whereNotIn('d.status', ['VOID', 'CANCELED'])
            ->whereRaw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0) = ?", [$sourceId])
            ->exists();

        if ($alreadyConverted) {
            return response()->json([
                'message' => 'El documento ya fue convertido a ' . $targetDocumentKind,
            ], 409);
        }

        $sourceItems = DB::table('sales.commercial_document_items')
            ->where('document_id', $sourceId)
            ->orderBy('line_no')
            ->get();

        if ($sourceItems->isEmpty()) {
            return response()->json([
                'message' => 'El documento origen no tiene items para convertir',
            ], 422);
        }

        $sourceItemIds = $sourceItems->pluck('id')->map(function ($rowId) {
            return (int) $rowId;
        })->values()->all();

        $lotsByItem = DB::table('sales.commercial_document_item_lots')
            ->whereIn('document_item_id', $sourceItemIds)
            ->get()
            ->groupBy('document_item_id');

        $series = isset($payload['series']) && trim((string) $payload['series']) !== ''
            ? trim((string) $payload['series'])
            : null;

        if ($series === null) {
            $candidateSeries = DB::table('sales.series_numbers')
                ->where('company_id', $companyId)
                ->where('document_kind', $targetDocumentKind)
                ->where('is_enabled', true)
                ->when($source->branch_id !== null, function ($query) use ($source) {
                    $query->where(function ($nested) use ($source) {
                        $nested->where('branch_id', (int) $source->branch_id)
                            ->orWhereNull('branch_id');
                    });
                })
                ->when($source->warehouse_id !== null, function ($query) use ($source) {
                    $query->where(function ($nested) use ($source) {
                        $nested->where('warehouse_id', (int) $source->warehouse_id)
                            ->orWhereNull('warehouse_id');
                    });
                })
                ->orderByDesc('branch_id')
                ->orderByDesc('warehouse_id')
                ->orderBy('series')
                ->first();

            if (!$candidateSeries) {
                return response()->json([
                    'message' => 'No existe serie habilitada para ' . $targetDocumentKind,
                ], 422);
            }

            $series = (string) $candidateSeries->series;
        }

        $sourceMetadata = [];
        if (isset($source->metadata) && $source->metadata !== null && $source->metadata !== '') {
            $decoded = json_decode((string) $source->metadata, true);
            if (is_array($decoded)) {
                $sourceMetadata = $decoded;
            }
        }

        $sourceHadStockImpact = $this->shouldAffectStock((string) $source->document_kind, (string) $source->status);

        $sourceNumber = (string) $source->series . '-' . (string) $source->number;
        $itemsPayload = $sourceItems->map(function ($item) use ($lotsByItem) {
            $itemLots = $lotsByItem->get((int) $item->id, collect())->map(function ($lot) {
                return [
                    'lot_id' => (int) $lot->lot_id,
                    'qty' => (float) $lot->qty,
                ];
            })->values()->all();

            return [
                'line_no' => (int) $item->line_no,
                'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                'unit_id' => $item->unit_id !== null ? (int) $item->unit_id : null,
                'price_tier_id' => $item->price_tier_id !== null ? (int) $item->price_tier_id : null,
                'tax_category_id' => $item->tax_category_id !== null ? (int) $item->tax_category_id : null,
                'description' => (string) $item->description,
                'qty' => (float) $item->qty,
                'qty_base' => (float) $item->qty_base,
                'conversion_factor' => (float) $item->conversion_factor,
                'base_unit_price' => (float) $item->base_unit_price,
                'unit_price' => (float) $item->unit_price,
                'unit_cost' => (float) $item->unit_cost,
                'wholesale_discount_percent' => (float) $item->wholesale_discount_percent,
                'price_source' => $item->price_source ?: 'MANUAL',
                'discount_total' => (float) $item->discount_total,
                'tax_total' => (float) $item->tax_total,
                'subtotal' => (float) $item->subtotal,
                'total' => (float) $item->total,
                'metadata' => null,
                'lots' => !empty($itemLots) ? $itemLots : null,
            ];
        })->values()->all();

        $forwardPayload = [
            'company_id' => $companyId,
            'branch_id' => $source->branch_id !== null ? (int) $source->branch_id : null,
            'warehouse_id' => $source->warehouse_id !== null ? (int) $source->warehouse_id : null,
            'cash_register_id' => isset($payload['cash_register_id'])
                ? (int) $payload['cash_register_id']
                : (isset($sourceMetadata['cash_register_id']) && $sourceMetadata['cash_register_id'] !== null
                    ? (int) $sourceMetadata['cash_register_id']
                    : null),
            'document_kind' => $targetDocumentKind,
            'series' => $series,
            'issue_at' => $payload['issue_at'] ?? now(),
            'due_at' => $payload['due_at'] ?? $source->due_at,
            'customer_id' => (int) $source->customer_id,
            'currency_id' => (int) $source->currency_id,
            'payment_method_id' => isset($payload['payment_method_id'])
                ? (int) $payload['payment_method_id']
                : ($source->payment_method_id !== null ? (int) $source->payment_method_id : null),
            'exchange_rate' => $source->exchange_rate !== null ? (float) $source->exchange_rate : null,
            'notes' => $payload['notes'] ?? $source->notes,
            'metadata' => array_merge($sourceMetadata, [
                'source_document_id' => $sourceId,
                'source_document_kind' => (string) $source->document_kind,
                'source_document_number' => $sourceNumber,
                'conversion_origin' => 'SALES_MODULE',
                'stock_already_discounted' => $sourceHadStockImpact,
            ]),
            'status' => $targetStatus,
            'items' => $itemsPayload,
            'payments' => [],
        ];

        $forwardRequest = Request::create('/api/sales/commercial-documents', 'POST', $forwardPayload);
        $forwardRequest->attributes->set('auth_user', $authUser);

        return $this->createCommercialDocument($forwardRequest);
    }

    public function showCommercialDocument(Request $request, $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $authUser->company_id;
        $documentId = (int) $id;

        $doc = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('core.currencies as cur', 'cur.id', '=', 'd.currency_id')
            ->leftJoin('core.payment_methods as pm', 'pm.id', '=', 'd.payment_method_id')
            ->select([
                'd.id',
                'd.branch_id',
                'd.warehouse_id',
                'd.customer_id',
                'd.currency_id',
                'd.payment_method_id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.due_at',
                'd.status',
                'd.total',
                'd.balance_due',
                'd.notes',
                'd.metadata',
                'cur.code as currency_code',
                'cur.symbol as currency_symbol',
                'pm.name as payment_method_name',
                DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
                'c.doc_number as customer_doc_number',
                'c.address as customer_address',
            ])
            ->where('d.id', $documentId)
            ->where('d.company_id', $companyId)
            ->first();

        if (!$doc) {
            return response()->json([
                'message' => 'Documento no encontrado',
            ], 404);
        }

        $items = $this->resolveDocumentItemsWithFallback($companyId, $documentId);
        $itemIds = $items->pluck('id')->map(function ($rowId) {
            return (int) $rowId;
        })->values()->all();
        $lotsByItem = empty($itemIds)
            ? collect()
            : DB::table('sales.commercial_document_item_lots')
                ->whereIn('document_item_id', $itemIds)
                ->get()
                ->groupBy('document_item_id');

        $allTaxCategories = $this->resolveTaxCategories($companyId);

        $mappedItems = $items->map(function ($item) use ($allTaxCategories, $lotsByItem) {
            $taxCat = $item->tax_category_id ? $allTaxCategories->firstWhere('id', $item->tax_category_id) : null;
            $taxLabel = is_array($taxCat) ? (string) ($taxCat['label'] ?? 'Sin IGV') : 'Sin IGV';
            $taxRate = is_array($taxCat) ? (float) ($taxCat['rate_percent'] ?? 0) : 0;
            $itemLots = $lotsByItem->get((int) $item->id, collect())->map(function ($lot) {
                return [
                    'lot_id' => (int) $lot->lot_id,
                    'qty' => (float) $lot->qty,
                ];
            })->values();
            $itemMetadata = null;

            if ($item->metadata !== null && $item->metadata !== '') {
                $decodedMetadata = json_decode((string) $item->metadata, true);
                if (is_array($decodedMetadata)) {
                    $itemMetadata = $decodedMetadata;
                }
            }

            return [
                'lineNo' => (int) $item->line_no,
                'productId' => $item->product_id !== null ? (int) $item->product_id : null,
                'unitId' => $item->unit_id !== null ? (int) $item->unit_id : null,
                'priceTierId' => $item->price_tier_id !== null ? (int) $item->price_tier_id : null,
                'qty' => (float) $item->qty,
                'qtyBase' => (float) ($item->qty_base ?? 0),
                'conversionFactor' => (float) ($item->conversion_factor ?? 1),
                'baseUnitPrice' => (float) ($item->base_unit_price ?? 0),
                'unitLabel' => (string) ($item->unit_code ?? ''),
                'description' => (string) $item->description,
                'unitPrice' => (float) $item->unit_price,
                'unitCost' => (float) ($item->unit_cost ?? 0),
                'wholesaleDiscountPercent' => (float) ($item->wholesale_discount_percent ?? 0),
                'priceSource' => $item->price_source ?: 'MANUAL',
                'discountTotal' => (float) ($item->discount_total ?? 0),
                'lineTotal' => (float) $item->total,
                'taxCategoryId' => $item->tax_category_id,
                'taxLabel' => $taxLabel,
                'taxRate' => $taxRate,
                'taxAmount' => (float) $item->tax_total,
                'metadata' => $itemMetadata,
                'lots' => $itemLots,
            ];
        })->values();

        $docMetadata = [];
        if ($doc->metadata !== null && $doc->metadata !== '') {
            $decodedDocMetadata = json_decode((string) $doc->metadata, true);
            if (is_array($decodedDocMetadata)) {
                $docMetadata = $decodedDocMetadata;
            }
        }

        $gravadaTotal = 0;
        $inafectaTotal = 0;
        $exoneradaTotal = 0;
        $taxTotal = 0;

        foreach ($items as $item) {
            $taxCat = $item->tax_category_id ? $allTaxCategories->firstWhere('id', $item->tax_category_id) : null;
            $taxLabel = is_array($taxCat) ? (string) ($taxCat['label'] ?? 'Sin IGV') : 'Sin IGV';

            if ($taxLabel === 'IGV') {
                $gravadaTotal += (float) ($item->subtotal ?? 0);
                $taxTotal += (float) ($item->tax_total ?? 0);
            } elseif ($taxLabel === 'Inafecta') {
                $inafectaTotal += (float) ($item->subtotal ?? 0);
            } elseif ($taxLabel === 'Exonerada') {
                $exoneradaTotal += (float) ($item->subtotal ?? 0);
            }
        }

        return response()->json([
            'data' => [
                'id' => (int) $doc->id,
                'branchId' => $doc->branch_id !== null ? (int) $doc->branch_id : null,
                'warehouseId' => $doc->warehouse_id !== null ? (int) $doc->warehouse_id : null,
                'customerId' => (int) $doc->customer_id,
                'currencyId' => (int) $doc->currency_id,
                'paymentMethodId' => $doc->payment_method_id !== null ? (int) $doc->payment_method_id : null,
                'documentKind' => (string) $doc->document_kind,
                'series' => (string) $doc->series,
                'number' => (int) $doc->number,
                'issueDate' => (string) ($doc->issue_at ?? ''),
                'dueDate' => $doc->due_at ? (string) $doc->due_at : null,
                'status' => (string) $doc->status,
                'currencyCode' => (string) ($doc->currency_code ?? 'PEN'),
                'currencySymbol' => (string) ($doc->currency_symbol ?? 'S/'),
                'paymentMethodName' => (string) ($doc->payment_method_name ?? '-'),
                'customerName' => (string) ($doc->customer_name ?? '-'),
                'customerDocNumber' => (string) ($doc->customer_doc_number ?? '-'),
                'customerAddress' => (string) ($doc->customer_address ?? '-'),
                'subtotal' => (float) ($gravadaTotal + $inafectaTotal + $exoneradaTotal),
                'taxTotal' => (float) $taxTotal,
                'grandTotal' => (float) $doc->total,
                'metadata' => $docMetadata,
                'gravadaTotal' => (float) $gravadaTotal,
                'inafectaTotal' => (float) $inafectaTotal,
                'exoneradaTotal' => (float) $exoneradaTotal,
                'items' => $mappedItems,
            ],
        ]);
    }

    private function registerCashIncomeFromDocument(
        int $companyId,
        ?int $branchId,
        ?int $cashRegisterId,
        int $documentId,
        string $documentKind,
        string $series,
        int $number,
        float $paidTotal,
        int $userId,
        array $payments = []
    ): void {
        if ($cashRegisterId === null || $paidTotal <= 0) {
            return;
        }

        if (!$this->tableExists('sales.cash_sessions') || !$this->tableExists('sales.cash_movements')) {
            return;
        }

        $session = DB::table('sales.cash_sessions')
            ->where('company_id', $companyId)
            ->where('cash_register_id', $cashRegisterId)
            ->where('status', 'OPEN')
            ->orderByDesc('opened_at')
            ->first();

        if (!$session) {
            return;
        }

        $firstPaidMethod = collect($payments)
            ->first(function ($payment) {
                return ($payment['status'] ?? 'PENDING') === 'PAID';
            });

        DB::table('sales.cash_movements')->insert([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'cash_register_id' => $cashRegisterId,
            'cash_session_id' => (int) $session->id,
            'movement_type' => 'INCOME',
            'payment_method_id' => $firstPaidMethod['payment_method_id'] ?? null,
            'amount' => round($paidTotal, 4),
            'description' => 'Cobro doc ' . (['INVOICE' => 'Factura', 'RECEIPT' => 'Boleta', 'CREDIT_NOTE' => 'Nota Credito', 'DEBIT_NOTE' => 'Nota Debito', 'QUOTATION' => 'Cotizacion', 'SALES_ORDER' => 'Pedido'][$documentKind] ?? $documentKind) . ' ' . $series . '-' . $number,
            'notes' => 'Cobro doc ' . (['INVOICE' => 'Factura', 'RECEIPT' => 'Boleta', 'CREDIT_NOTE' => 'Nota Credito', 'DEBIT_NOTE' => 'Nota Debito', 'QUOTATION' => 'Cotizacion', 'SALES_ORDER' => 'Pedido'][$documentKind] ?? $documentKind) . ' ' . $series . '-' . $number,
            'ref_type' => 'COMMERCIAL_DOCUMENT',
            'ref_id' => $documentId,
            'created_by' => $userId,
            'user_id' => $userId,
            'movement_at' => now(),
            'created_at' => now(),
        ]);

        $totalIn = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', (int) $session->id)
            ->whereIn('movement_type', ['IN', 'INCOME'])
            ->sum('amount');

        $totalOut = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', (int) $session->id)
            ->whereIn('movement_type', ['OUT', 'EXPENSE'])
            ->sum('amount');

        DB::table('sales.cash_sessions')
            ->where('id', (int) $session->id)
            ->update([
                'expected_balance' => round((float) $session->opening_balance + $totalIn - $totalOut, 4),
            ]);
    }

    private function resolveTaxCategories(int $companyId)
    {
        $sourceTable = null;

        foreach (['core.tax_categories', 'sales.tax_categories', 'appcfg.tax_categories'] as $candidate) {
            if ($this->tableExists($candidate)) {
                $sourceTable = $candidate;
                break;
            }
        }

        if (!$sourceTable) {
            return collect();
        }

        $columns = $this->tableColumns($sourceTable);
        $idColumn = $this->firstExistingColumn($columns, ['id']);
        $codeColumn = $this->firstExistingColumn($columns, ['code', 'sunat_code', 'tax_code']);
        $labelColumn = $this->firstExistingColumn($columns, ['name', 'label', 'description']);
        $rateColumn = $this->firstExistingColumn($columns, ['rate_percent', 'rate', 'percentage', 'tax_rate']);
        $statusColumn = $this->firstExistingColumn($columns, ['status', 'is_enabled', 'enabled', 'active']);
        $companyColumn = $this->firstExistingColumn($columns, ['company_id']);

        $query = DB::table($sourceTable);

        if ($statusColumn) {
            if ($statusColumn === 'status') {
                $query->where($statusColumn, 1);
            } else {
                $query->where($statusColumn, true);
            }
        }

        if ($companyColumn) {
            $query->where(function ($nested) use ($companyColumn, $companyId) {
                $nested->where($companyColumn, $companyId)
                    ->orWhereNull($companyColumn);
            });
        }

        $rows = $query->get()->map(function ($row) use ($idColumn, $codeColumn, $labelColumn, $rateColumn) {
            $id = $idColumn ? (int) ($row->{$idColumn} ?? 0) : 0;
            $code = $codeColumn ? (string) ($row->{$codeColumn} ?? '') : '';
            $label = $labelColumn ? (string) ($row->{$labelColumn} ?? '') : '';
            $rate = $rateColumn ? (float) ($row->{$rateColumn} ?? 0) : 0.0;

            if ($label === '') {
                $label = $code !== '' ? $code : ('IGV #' . $id);
            }

            return [
                'id' => $id,
                'code' => $code,
                'label' => $label,
                'rate_percent' => round($rate, 4),
            ];
        })->filter(function ($row) {
            return $row['id'] > 0;
        })->values();

        if ($rows->isEmpty()) {
            return collect();
        }

        return collect($this->companyIgvRateService->applyActiveRateToTaxCategories($companyId, $rows->all()));
    }

    private function resolveDocumentNoteReasons(string $documentKind): array
    {
        $targetTable = strtoupper($documentKind) === 'DEBIT_NOTE'
            ? 'master.debit_note_reasons'
            : 'master.credit_note_reasons';

        if (!$this->tableExists($targetTable)) {
            return [];
        }

        $columns = $this->tableColumns($targetTable);
        $idColumn = $this->firstExistingColumn($columns, ['id']);
        $codeColumn = $this->firstExistingColumn($columns, ['code']);
        $descriptionColumn = $this->firstExistingColumn($columns, ['description', 'name', 'label']);
        $deletedColumn = $this->firstExistingColumn($columns, ['is_deleted', 'deleted']);
        $statusColumn = $this->firstExistingColumn($columns, ['status', 'is_enabled', 'enabled', 'active']);

        $query = DB::table($targetTable);

        if ($deletedColumn) {
            $query->where(function ($nested) use ($deletedColumn) {
                $nested->whereNull($deletedColumn)
                    ->orWhere($deletedColumn, false)
                    ->orWhere($deletedColumn, 0);
            });
        }

        if ($statusColumn) {
            if ($statusColumn === 'status') {
                $query->where($statusColumn, 1);
            } else {
                $query->where($statusColumn, true);
            }
        }

        return $query->get()->map(function ($row) use ($idColumn, $codeColumn, $descriptionColumn) {
            return [
                'id' => $idColumn ? (int) ($row->{$idColumn} ?? 0) : 0,
                'code' => $codeColumn ? (string) ($row->{$codeColumn} ?? '') : '',
                'description' => $descriptionColumn ? (string) ($row->{$descriptionColumn} ?? '') : '',
            ];
        })->filter(function ($row) {
            return $row['id'] > 0 && trim($row['code']) !== '';
        })->sortBy(function ($row) {
            return $row['code'];
        })->values()->all();
    }

    private function getDetractionMinAmount(int $companyId, $branchId): float
    {
        // Read min_amount from toggle config JSON; fallback to SUNAT default 700 PEN
        $row = null;
        if ($branchId !== null) {
            $row = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('feature_code', 'SALES_DETRACCION_ENABLED')
                ->first();
        }
        if (!$row) {
            $row = DB::table('appcfg.company_feature_toggles')
                ->where('company_id', $companyId)
                ->where('feature_code', 'SALES_DETRACCION_ENABLED')
                ->first();
        }
        if ($row && !empty($row->config)) {
            $config = is_string($row->config) ? json_decode($row->config, true) : (array) $row->config;
            if (isset($config['min_amount']) && is_numeric($config['min_amount'])) {
                return (float) $config['min_amount'];
            }
        }
        return 700.00;
    }

    private function resolveRetencionTypes(int $companyId, $branchId): array
    {
        $defaultRate = 3.00;
        $defaultType = [
            'code' => 'RET_IGV_3',
            'name' => 'Retencion IGV',
            'rate_percent' => $defaultRate,
        ];

        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_RETENCION_ENABLED');
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);

        $configuredTypes = isset($config['retencion_types']) && is_array($config['retencion_types'])
            ? $config['retencion_types']
            : [];

        $rows = collect($configuredTypes)
            ->map(function ($item) use ($defaultRate) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $rate = isset($item['rate_percent']) && is_numeric($item['rate_percent'])
                    ? (float) $item['rate_percent']
                    : $defaultRate;

                return [
                    'code' => $code,
                    'name' => $name,
                    'rate_percent' => $rate,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolvePercepcionTypes(int $companyId, $branchId): array
    {
        $defaultRate = 2.00;
        $defaultType = [
            'code' => 'PERC_IGV_2',
            'name' => 'Percepcion IGV',
            'rate_percent' => $defaultRate,
        ];

        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_PERCEPCION_ENABLED');
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);

        $configuredTypes = isset($config['percepcion_types']) && is_array($config['percepcion_types'])
            ? $config['percepcion_types']
            : [];

        $rows = collect($configuredTypes)
            ->map(function ($item) use ($defaultRate) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $rate = isset($item['rate_percent']) && is_numeric($item['rate_percent'])
                    ? (float) $item['rate_percent']
                    : $defaultRate;

                return [
                    'code' => $code,
                    'name' => $name,
                    'rate_percent' => $rate,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolveSunatOperationTypes(int $companyId, $branchId): array
    {
        $defaultRows = [
            ['code' => '0101', 'name' => 'Venta interna', 'regime' => 'NONE'],
            ['code' => '1001', 'name' => 'Operacion sujeta a detraccion', 'regime' => 'DETRACCION'],
            ['code' => '2001', 'name' => 'Operacion sujeta a retencion', 'regime' => 'RETENCION'],
            ['code' => '3001', 'name' => 'Operacion sujeta a percepcion', 'regime' => 'PERCEPCION'],
        ];

        $detraccionRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_DETRACCION_ENABLED');
        $retencionRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_RETENCION_ENABLED');
        $percepcionRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_PERCEPCION_ENABLED');
        $detraccionConfig = $this->decodeFeatureConfig($detraccionRow ? $detraccionRow->config : null);
        $retencionConfig = $this->decodeFeatureConfig($retencionRow ? $retencionRow->config : null);
        $percepcionConfig = $this->decodeFeatureConfig($percepcionRow ? $percepcionRow->config : null);

        $configuredRows = [];
        if (isset($detraccionConfig['sunat_operation_types']) && is_array($detraccionConfig['sunat_operation_types'])) {
            $configuredRows = array_merge($configuredRows, $detraccionConfig['sunat_operation_types']);
        }
        if (isset($retencionConfig['sunat_operation_types']) && is_array($retencionConfig['sunat_operation_types'])) {
            $configuredRows = array_merge($configuredRows, $retencionConfig['sunat_operation_types']);
        }
        if (isset($percepcionConfig['sunat_operation_types']) && is_array($percepcionConfig['sunat_operation_types'])) {
            $configuredRows = array_merge($configuredRows, $percepcionConfig['sunat_operation_types']);
        }

        $rows = collect($configuredRows)
            ->map(function ($item) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $regime = strtoupper(trim((string) ($item['regime'] ?? 'NONE')));
                if (!in_array($regime, ['NONE', 'DETRACCION', 'RETENCION', 'PERCEPCION'], true)) {
                    $regime = 'NONE';
                }

                return [
                    'code' => $code,
                    'name' => $name,
                    'regime' => $regime,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->unique('code')
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : $defaultRows;
    }

    private function resolveFeatureAccountInfo(int $companyId, $branchId, string $featureCode, string $fallbackKeyword): ?array
    {
        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, $featureCode);
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);

        $accountNumber = trim((string) ($config['account_number'] ?? ''));
        if ($accountNumber !== '') {
            return [
                'bank_name' => trim((string) ($config['bank_name'] ?? '')),
                'account_number' => $accountNumber,
                'account_holder' => trim((string) ($config['account_holder'] ?? '')),
            ];
        }

        $bankAccounts = $this->resolveCompanyBankAccounts($companyId);
        $keyword = strtoupper(trim($fallbackKeyword));
        foreach ($bankAccounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $accountType = strtoupper(trim((string) ($account['account_type'] ?? '')));
            $number = trim((string) ($account['account_number'] ?? ''));
            if ($number === '') {
                continue;
            }
            if ($keyword !== '' && strpos($accountType, $keyword) === false) {
                continue;
            }

            return [
                'bank_name' => trim((string) ($account['bank_name'] ?? '')),
                'account_number' => $number,
                'account_holder' => trim((string) ($account['account_holder'] ?? '')),
            ];
        }

        return null;
    }

    private function resolveCompanyBankAccounts(int $companyId): array
    {
        if (!$this->tableExists('core.company_settings')) {
            return [];
        }

        $row = DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->select('bank_accounts')
            ->first();

        if (!$row || $row->bank_accounts === null) {
            return [];
        }

        $decoded = is_string($row->bank_accounts)
            ? json_decode($row->bank_accounts, true)
            : (array) $row->bank_accounts;

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, function ($item) {
            return is_array($item);
        }));
    }

    private function resolveFeatureToggleRow(int $companyId, $branchId, string $featureCode)
    {
        $companyRow = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->first();

        if ($branchId !== null) {
            $branchRow = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('feature_code', $featureCode)
                ->first();

            if ($branchRow && (bool) ($branchRow->is_enabled ?? false)) {
                return $branchRow;
            }

            if ($companyRow && (bool) ($companyRow->is_enabled ?? false)) {
                return $companyRow;
            }

            if ($branchRow) {
                return $branchRow;
            }
        }

        return $companyRow;
    }

    private function decodeFeatureConfig($rawConfig): array
    {
        if ($rawConfig === null) {
            return [];
        }

        if (is_string($rawConfig)) {
            $decoded = json_decode($rawConfig, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($rawConfig)) {
            return $rawConfig;
        }

        return [];
    }

    private function resolveDetractionServiceCodes(): array
    {
        if (!$this->tableExists('master.detraccion_service_codes')) {
            return [];
        }

        return DB::table('master.detraccion_service_codes')
            ->select('id', 'code', 'name', 'rate_percent')
            ->where('is_active', 1)
            ->orderBy('code')
            ->get()
            ->map(function ($row) {
                return [
                    'id'           => (int) $row->id,
                    'code'         => (string) $row->code,
                    'name'         => (string) $row->name,
                    'rate_percent' => (float) $row->rate_percent,
                ];
            })
            ->values()
            ->all();
    }

    private function tableExists(string $qualifiedTable): bool
    {
        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        $row = DB::selectOne(
            'select exists (select 1 from information_schema.tables where table_schema = ? and table_name = ?) as present',
            [$schema, $table]
        );

        return isset($row->present) && (bool) $row->present;
    }

    private function tableColumns(string $qualifiedTable): array
    {
        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        return collect(DB::select(
            'select column_name from information_schema.columns where table_schema = ? and table_name = ?',
            [$schema, $table]
        ))->map(function ($row) {
            return (string) $row->column_name;
        })->all();
    }

    private function firstExistingColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function enabledUnits(int $companyId)
    {
        $this->ensureCompanyUnitsTable();

        return DB::table('core.units as u')
            ->join('appcfg.company_units as cu', function ($join) use ($companyId) {
                $join->on('cu.unit_id', '=', 'u.id')
                    ->where('cu.company_id', '=', $companyId);
            })
            ->select('u.id', 'u.code', 'u.sunat_uom_code', 'u.name')
            ->where('cu.is_enabled', true)
            ->orderBy('u.name')
            ->get();
    }

    private function ensureCompanyUnitsTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.company_units (
                company_id BIGINT NOT NULL,
                unit_id BIGINT NOT NULL,
                is_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                updated_by BIGINT NULL,
                updated_at TIMESTAMP NULL,
                PRIMARY KEY (company_id, unit_id)
            )'
        );
    }

    private function ensureCustomerPriceProfilesTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.customer_price_profiles (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                customer_id BIGINT NOT NULL,
                default_tier_id BIGINT NULL,
                discount_percent NUMERIC(8,4) NOT NULL DEFAULT 0,
                status SMALLINT NOT NULL DEFAULT 1,
                UNIQUE(company_id, customer_id)
            )'
        );
    }

    private function resolveValidatedTierId(int $companyId, $tierId): ?int
    {
        if ($tierId === null || $tierId === '') {
            return null;
        }

        $resolved = (int) $tierId;
        if ($resolved <= 0) {
            return null;
        }

        $exists = DB::table('sales.price_tiers')
            ->where('company_id', $companyId)
            ->where('id', $resolved)
            ->exists();

        return $exists ? $resolved : null;
    }

    private function splitQualifiedTable(string $qualifiedTable): array
    {
        if (strpos($qualifiedTable, '.') === false) {
            return ['public', $qualifiedTable];
        }

        [$schema, $table] = explode('.', $qualifiedTable, 2);

        return [$schema, $table];
    }

    private function resolveDocumentItemsWithFallback(int $companyId, int $documentId, int $maxDepth = 5)
    {
        $visited = [];
        $currentDocumentId = $documentId;

        for ($depth = 0; $depth <= $maxDepth; $depth++) {
            if (in_array($currentDocumentId, $visited, true)) {
                break;
            }
            $visited[] = $currentDocumentId;

            $items = DB::table('sales.commercial_document_items as i')
                ->leftJoin('core.units as u', 'u.id', '=', 'i.unit_id')
                ->select([
                    'i.id',
                    'i.line_no',
                    'i.product_id',
                    'i.unit_id',
                    'i.price_tier_id',
                    'i.qty',
                    'i.qty_base',
                    'i.conversion_factor',
                    'i.base_unit_price',
                    'i.description',
                    'i.unit_price',
                    'i.unit_cost',
                    'i.wholesale_discount_percent',
                    'i.price_source',
                    'i.discount_total',
                    'u.code as unit_code',
                    'i.tax_category_id',
                    'i.tax_total',
                    'i.subtotal',
                    'i.total',
                    'i.metadata',
                ])
                ->where('i.document_id', $currentDocumentId)
                ->orderBy('i.line_no')
                ->get();

            if (!$items->isEmpty()) {
                return $items;
            }

            $docRow = DB::table('sales.commercial_documents')
                ->select('id', 'metadata')
                ->where('company_id', $companyId)
                ->where('id', $currentDocumentId)
                ->first();

            if (!$docRow || $docRow->metadata === null || $docRow->metadata === '') {
                break;
            }

            $metadata = json_decode((string) $docRow->metadata, true);
            $nextDocumentId = is_array($metadata) ? (int) ($metadata['source_document_id'] ?? 0) : 0;

            if ($nextDocumentId <= 0) {
                break;
            }

            $currentDocumentId = $nextDocumentId;
        }

        return collect();
    }

    private function decodeDocumentMetadata($rawMetadata): array
    {
        if ($rawMetadata === null || $rawMetadata === '') {
            return [];
        }

        if (is_array($rawMetadata)) {
            return $rawMetadata;
        }

        $decoded = json_decode((string) $rawMetadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveIssueAtForStorage($issueAt)
    {
        if ($issueAt === null || $issueAt === '') {
            return now();
        }

        $text = trim((string) $issueAt);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            $limaNow = now('America/Lima');
            return $text . ' ' . $limaNow->format('H:i:s') . '-05:00';
        }

        return $issueAt;
    }

    private function hasActiveChildConversions(int $companyId, int $sourceDocumentId): bool
    {
        return DB::table('sales.commercial_documents as d')
            ->where('d.company_id', $companyId)
            ->whereNotIn('d.status', ['VOID', 'CANCELED'])
            ->whereRaw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0) = ?", [$sourceDocumentId])
            ->exists();
    }

    private function reverseInventoryLedgerForDocument(
        int $companyId,
        int $documentId,
        ?string $voidAt,
        int $userId
    ): void {
        $settings = $this->inventorySettingsForCompany($companyId);
        $movedAt = $voidAt ?: now();

        $rows = DB::table('inventory.inventory_ledger')
            ->where('company_id', $companyId)
            ->where('ref_type', 'COMMERCIAL_DOCUMENT')
            ->where('ref_id', $documentId)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $originalType = strtoupper((string) $row->movement_type);
            if (!in_array($originalType, ['IN', 'OUT'], true)) {
                continue;
            }

            $reverseType = $originalType === 'IN' ? 'OUT' : 'IN';
            $qty = round((float) ($row->quantity ?? 0), 8);

            if ($qty <= 0) {
                continue;
            }

            $delta = $reverseType === 'IN' ? $qty : -$qty;

            $this->applyCurrentStockDelta(
                $companyId,
                (int) $row->warehouse_id,
                (int) $row->product_id,
                $delta,
                (bool) $settings['allow_negative_stock']
            );

            if ($row->lot_id !== null) {
                $this->applyLotStockDelta(
                    $companyId,
                    (int) $row->warehouse_id,
                    (int) $row->product_id,
                    (int) $row->lot_id,
                    $delta,
                    (bool) $settings['allow_negative_stock']
                );
            }

            DB::table('inventory.inventory_ledger')->insert([
                'company_id' => $companyId,
                'warehouse_id' => (int) $row->warehouse_id,
                'product_id' => (int) $row->product_id,
                'lot_id' => $row->lot_id !== null ? (int) $row->lot_id : null,
                'movement_type' => $reverseType,
                'quantity' => $qty,
                'unit_cost' => (float) ($row->unit_cost ?? 0),
                'ref_type' => 'COMMERCIAL_DOCUMENT_VOID',
                'ref_id' => $documentId,
                'notes' => 'Reversa por anulacion de doc comercial #' . $documentId,
                'moved_at' => $movedAt,
                'created_by' => $userId,
            ]);
        }
    }

    private function shouldAffectStock(string $documentKind, string $status): bool
    {
        if ($status !== 'ISSUED') {
            return false;
        }

        return in_array($documentKind, ['SALES_ORDER', 'INVOICE', 'RECEIPT', 'DEBIT_NOTE', 'CREDIT_NOTE'], true);
    }


    private function stockDirectionForDocument(string $documentKind): int
    {
        if (in_array($documentKind, ['SALES_ORDER', 'INVOICE', 'RECEIPT', 'DEBIT_NOTE'], true)) {
            return -1;
        }

        if ($documentKind === 'CREDIT_NOTE') {
            return 1;
        }

        return 0;
    }

    private function inventorySettingsForCompany(int $companyId): array
    {
        $row = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();

        if (!$row) {
            return [
                'complexity_mode' => 'BASIC',
                'inventory_mode' => 'KARDEX_SIMPLE',
                'lot_outflow_strategy' => 'MANUAL',
                'enable_inventory_pro' => false,
                'enable_lot_tracking' => false,
                'enable_expiry_tracking' => false,
                'enable_advanced_reporting' => false,
                'enable_graphical_dashboard' => false,
                'enable_location_control' => false,
                'allow_negative_stock' => false,
                'enforce_lot_for_tracked' => false,
            ];
        }

        return [
            'complexity_mode' => (string) ($row->complexity_mode ?? 'BASIC'),
            'inventory_mode' => (string) ($row->inventory_mode ?? 'KARDEX_SIMPLE'),
            'lot_outflow_strategy' => (string) ($row->lot_outflow_strategy ?? 'MANUAL'),
            'enable_inventory_pro' => (bool) ($row->enable_inventory_pro ?? false),
            'enable_lot_tracking' => (bool) ($row->enable_lot_tracking ?? false),
            'enable_expiry_tracking' => (bool) ($row->enable_expiry_tracking ?? false),
            'enable_advanced_reporting' => (bool) ($row->enable_advanced_reporting ?? false),
            'enable_graphical_dashboard' => (bool) ($row->enable_graphical_dashboard ?? false),
            'enable_location_control' => (bool) ($row->enable_location_control ?? false),
            'allow_negative_stock' => (bool) $row->allow_negative_stock,
            'enforce_lot_for_tracked' => (bool) $row->enforce_lot_for_tracked,
        ];
    }

    private function isCommerceFeatureEnabled(int $companyId, string $featureCode): bool
    {
        return DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->where('is_enabled', true)
            ->exists();
    }

    private function isCommerceFeatureEnabledForContext(int $companyId, ?int $branchId, string $featureCode): bool
    {
        return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, $featureCode, false);
    }

    private function isCommerceFeatureEnabledForContextWithDefault(int $companyId, ?int $branchId, string $featureCode, bool $defaultEnabled): bool
    {
        if ($branchId !== null) {
            $branchRow = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('feature_code', $featureCode)
                ->select('is_enabled')
                ->first();

            if ($branchRow && $branchRow->is_enabled !== null) {
                return (bool) $branchRow->is_enabled;
            }
        }

        $companyRow = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->select('is_enabled')
            ->first();

        if ($companyRow && $companyRow->is_enabled !== null) {
            return (bool) $companyRow->is_enabled;
        }

        return $defaultEnabled;
    }

    private function isSellerActor(string $roleProfile, string $roleCode): bool
    {
        if ($this->isAdminActor($roleCode)) {
            return false;
        }

        if ($roleProfile === 'SELLER') {
            return true;
        }

        if ($roleCode === '') {
            return false;
        }

        return strpos($roleCode, 'VENDED') !== false || strpos($roleCode, 'SELLER') !== false;
    }

    private function isCashierActor(string $roleProfile, string $roleCode): bool
    {
        if ($roleProfile === 'CASHIER') {
            return true;
        }

        if ($roleCode === '') {
            return false;
        }

        if ($this->isAdminActor($roleCode)) {
            return true;
        }

        return strpos($roleCode, 'CAJA') !== false || strpos($roleCode, 'CAJER') !== false || strpos($roleCode, 'CASHIER') !== false;
    }

    private function canActorVoidDocuments(int $companyId, ?int $branchId, string $roleProfile, string $roleCode): bool
    {
        if ($this->isAdminActor($roleCode)) {
            return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_ALLOW_VOID_FOR_ADMIN', true);
        }

        if ($this->isCashierActor($roleProfile, $roleCode)) {
            return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_ALLOW_VOID_FOR_CASHIER', true);
        }

        if ($this->isSellerActor($roleProfile, $roleCode)) {
            return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_ALLOW_VOID_FOR_SELLER', true);
        }

        return false;
    }

    private function isAdminActor(string $roleCode): bool
    {
        return $roleCode !== '' && strpos($roleCode, 'ADMIN') !== false;
    }

    private function resolveAuthRoleContext(int $userId, int $companyId): array
    {
        $this->ensureCompanyRoleProfilesTable();

        $row = DB::table('auth.user_roles as ur')
            ->join('auth.roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('appcfg.company_role_profiles as crp', function ($join) use ($companyId) {
                $join->on('crp.role_id', '=', 'r.id')
                    ->where('crp.company_id', '=', $companyId);
            })
            ->where('ur.user_id', $userId)
            ->where('r.company_id', $companyId)
            ->where('r.status', 1)
            ->orderBy('r.id')
            ->select('r.code as role_code', 'crp.functional_profile as role_profile')
            ->first();

        return [
            'role_code' => $row && $row->role_code !== null ? (string) $row->role_code : null,
            'role_profile' => $row && $row->role_profile !== null ? (string) $row->role_profile : null,
        ];
    }

    private function ensureCompanyRoleProfilesTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.company_role_profiles (
                company_id BIGINT NOT NULL,
                role_id BIGINT NOT NULL,
                functional_profile VARCHAR(20) NULL,
                updated_by BIGINT NULL,
                updated_at TIMESTAMP NULL,
                PRIMARY KEY (company_id, role_id)
            )'
        );
    }

    private function resolveLineConversion(int $companyId, $product, array $item, ?int $itemUnitId): array
    {
        $qty = (float) ($item['qty'] ?? 0);

        if (!$product || !$product->unit_id) {
            $factor = isset($item['conversion_factor']) ? (float) $item['conversion_factor'] : 1.0;
            if ($factor <= 0) {
                $factor = 1.0;
            }

            $qtyBase = isset($item['qty_base']) ? (float) $item['qty_base'] : ($qty * $factor);
            if ($qtyBase <= 0) {
                $qtyBase = $qty;
            }

            $baseUnitPrice = isset($item['base_unit_price']) ? (float) $item['base_unit_price'] : ((float) $item['unit_price'] / max($factor, 0.00000001));

            return [
                'conversion_factor' => $factor,
                'qty_base' => $qtyBase,
                'base_unit_price' => $baseUnitPrice,
            ];
        }

        $baseUnitId = (int) $product->unit_id;
        $lineUnitId = $itemUnitId ?: $baseUnitId;

        $factor = null;
        if (isset($item['conversion_factor']) && (float) $item['conversion_factor'] > 0) {
            $factor = (float) $item['conversion_factor'];
        } else {
            $factor = $this->resolveConversionFactor($companyId, (int) $product->id, $lineUnitId, $baseUnitId);
        }

        if ($factor <= 0) {
            throw new \RuntimeException('Invalid conversion factor for product #' . $product->id);
        }

        $qtyBase = isset($item['qty_base']) && (float) $item['qty_base'] > 0
            ? (float) $item['qty_base']
            : ($qty * $factor);

        $baseUnitPrice = isset($item['base_unit_price']) && (float) $item['base_unit_price'] >= 0
            ? (float) $item['base_unit_price']
            : ((float) $item['unit_price'] / max($factor, 0.00000001));

        return [
            'conversion_factor' => $factor,
            'qty_base' => $qtyBase,
            'base_unit_price' => $baseUnitPrice,
        ];
    }

    private function resolveConversionFactor(int $companyId, int $productId, int $lineUnitId, int $baseUnitId): float
    {
        if ($lineUnitId === $baseUnitId) {
            return 1.0;
        }

        $direct = DB::table('inventory.product_uom_conversions')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('from_unit_id', $lineUnitId)
            ->where('to_unit_id', $baseUnitId)
            ->where('status', 1)
            ->value('conversion_factor');

        if ($direct !== null && (float) $direct > 0) {
            return (float) $direct;
        }

        $inverse = DB::table('inventory.product_uom_conversions')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('from_unit_id', $baseUnitId)
            ->where('to_unit_id', $lineUnitId)
            ->where('status', 1)
            ->value('conversion_factor');

        if ($inverse !== null && (float) $inverse > 0) {
            return 1 / (float) $inverse;
        }

        throw new \RuntimeException('Missing conversion from unit ' . $lineUnitId . ' to base unit ' . $baseUnitId . ' for product #' . $productId);
    }

    private function applyCurrentStockDelta(
        int $companyId,
        int $warehouseId,
        int $productId,
        float $delta,
        bool $allowNegativeStock
    ): void {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId;

        if (!array_key_exists($projectionKey, $this->stockProjection)) {
            $row = DB::table('inventory.current_stock')
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->first();

            $this->stockProjection[$projectionKey] = $row ? (float) $row->stock : 0.0;
        }

        $current = $this->stockProjection[$projectionKey];
        $next = $current + $delta;

        if (!$allowNegativeStock && $next < -0.00000001) {
            throw new \RuntimeException('Insufficient stock for product #' . $productId);
        }

        $this->stockProjection[$projectionKey] = round($next, 8);
    }

    private function allocateOutboundLots(
        int $companyId,
        int $warehouseId,
        int $productId,
        float $qtyBase,
        float $conversionFactor,
        string $strategy,
        bool $allowNegativeStock,
        int $lineNumber
    ): array {
        $candidateLots = DB::table('inventory.product_lots as pl')
            ->leftJoin('inventory.current_stock_by_lot as csl', function ($join) use ($companyId, $warehouseId, $productId) {
                $join->on('csl.lot_id', '=', 'pl.id')
                    ->where('csl.company_id', '=', $companyId)
                    ->where('csl.warehouse_id', '=', $warehouseId)
                    ->where('csl.product_id', '=', $productId);
            })
            ->select([
                'pl.id',
                'pl.expires_at',
                'pl.received_at',
                DB::raw('COALESCE(csl.stock, 0) as stock'),
            ])
            ->where('pl.company_id', $companyId)
            ->where('pl.warehouse_id', $warehouseId)
            ->where('pl.product_id', $productId)
            ->where('pl.status', 1)
            ->orderByRaw($strategy === 'FEFO' ? 'CASE WHEN pl.expires_at IS NULL THEN 1 ELSE 0 END, pl.expires_at ASC, pl.received_at ASC, pl.id ASC' : 'pl.received_at ASC, pl.id ASC')
            ->get();

        if ($candidateLots->isEmpty()) {
            throw new \RuntimeException('No hay lotes disponibles para asignacion automatica en la linea ' . $lineNumber);
        }

        $remainingBase = round(max($qtyBase, 0), 8);
        $safeConversionFactor = max($conversionFactor, 0.00000001);
        $assignedLots = [];

        foreach ($candidateLots as $candidateLot) {
            if ($remainingBase <= 0.00000001) {
                break;
            }

            $availableBase = max(0, $this->projectedLotStock(
                $companyId,
                $warehouseId,
                $productId,
                (int) $candidateLot->id
            ));

            if ($availableBase <= 0.00000001) {
                continue;
            }

            $allocatedBase = min($availableBase, $remainingBase);

            $assignedLots[] = [
                'lot_id' => (int) $candidateLot->id,
                'qty' => round($allocatedBase / $safeConversionFactor, 8),
                'qty_base' => round($allocatedBase, 8),
            ];

            $remainingBase = round($remainingBase - $allocatedBase, 8);
        }

        if ($remainingBase > 0.00000001) {
            if (!$allowNegativeStock || empty($assignedLots)) {
                throw new \RuntimeException('Stock insuficiente por lotes para asignacion automatica en la linea ' . $lineNumber);
            }

            $lastIndex = count($assignedLots) - 1;
            $assignedLots[$lastIndex]['qty_base'] = round($assignedLots[$lastIndex]['qty_base'] + $remainingBase, 8);
            $assignedLots[$lastIndex]['qty'] = round($assignedLots[$lastIndex]['qty_base'] / $safeConversionFactor, 8);
        }

        return $assignedLots;
    }

    private function projectedLotStock(
        int $companyId,
        int $warehouseId,
        int $productId,
        int $lotId
    ): float {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId . ':' . $lotId;

        if (!array_key_exists($projectionKey, $this->lotStockProjection)) {
            $row = DB::table('inventory.current_stock_by_lot')
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('lot_id', $lotId)
                ->first();

            $this->lotStockProjection[$projectionKey] = $row ? (float) $row->stock : 0.0;
        }

        return (float) $this->lotStockProjection[$projectionKey];
    }

    private function applyLotStockDelta(
        int $companyId,
        int $warehouseId,
        int $productId,
        int $lotId,
        float $delta,
        bool $allowNegativeStock
    ): void {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId . ':' . $lotId;
        $current = $this->projectedLotStock($companyId, $warehouseId, $productId, $lotId);
        $next = $current + $delta;

        if (!$allowNegativeStock && $next < -0.00000001) {
            throw new \RuntimeException('Insufficient stock for lot #' . $lotId);
        }

        $this->lotStockProjection[$projectionKey] = round($next, 8);
    }

    /**
     * Endpoint para reintentar envío tributario de un documento.
     * Permite reenviar documentos que tuvieron rechazo o error a SUNAT.
     * 
     * Route: PUT /api/sales/commercial-documents/{id}/retry-tax-bridge
     */
    public function retryTaxBridgeSend(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        try {
            $result = $this->taxBridgeService->retry($companyId, $id);

            return response()->json([
                'message' => 'Tax bridge retry sent successfully',
                'document_id' => $id,
                'sunat_status' => $result['status'],
                'sunat_status_label' => $result['label'],
                'bridge_http_code' => $result['bridge_http_code'] ?? null,
                'bridge_response' => $result['response'] ?? null,
                'debug' => $result['debug'] ?? null,
            ], 200);
        } catch (TaxBridgeException $e) {
            $debug = $this->taxBridgeService->getLastDispatchDebug($companyId, $id);

            return response()->json([
                'message' => $e->getMessage(),
                'debug' => $debug,
            ], $e->httpStatus());
        }
    }

    public function sunatVoidCommunication(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            return response()->json([
                'message' => $errors->first() ?: 'Validation failed',
                'errors' => $errors,
            ], 422);
        }

        $payload = $validator->validated();

        try {
            $result = $this->taxBridgeService->sendVoidCommunication($companyId, $id, $payload['reason'] ?? null);

            if (($result['status'] ?? '') === 'ACCEPTED') {
                $this->voidCommercialDocumentUseCase->execute($authUser, $companyId, $id, [
                    'reason' => $payload['reason'] ?? 'Comunicacion de baja SUNAT',
                    'notes' => $payload['notes'] ?? 'Anulado por comunicacion de baja SUNAT',
                    'void_at' => now()->toDateTimeString(),
                    'sunat_void_status' => 'ACCEPTED',
                ]);
            }

            return response()->json([
                'message' => 'Comunicacion de baja SUNAT procesada',
                'document_id' => $id,
                'sunat_void_status' => $result['status'] ?? '',
                'sunat_void_label' => $result['label'] ?? '',
                'bridge_http_code' => $result['bridge_http_code'] ?? null,
                'bridge_response' => $result['response'] ?? null,
                'void_number' => $result['void_number'] ?? null,
                'debug' => $result['debug'] ?? null,
            ], 200);
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        } catch (TaxBridgeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }
    }

    public function previewTaxBridgePayload(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        try {
            $preview = $this->taxBridgeService->preview($companyId, $id);

            return response()->json([
                'message' => 'Tax bridge payload preview generated successfully',
                'document_id' => $id,
                'bridge_mode' => $preview['bridge_mode'],
                'endpoint' => $preview['endpoint'],
                'method' => $preview['method'],
                'content_type' => $preview['content_type'],
                'form_key' => $preview['form_key'],
                'payload' => $preview['payload'],
                'debug' => $preview,
            ], 200);
        } catch (TaxBridgeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }
    }

    public function downloadSunatXml(Request $request, int $id)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) ($authUser->company_id ?? 0);

        try {
            $result = $this->taxBridgeService->downloadDocument($companyId, $id, 'dowload_xml');

            return response($result['body'], 200)
                ->header('Content-Type', $result['content_type'])
                ->header('Content-Disposition', 'attachment; filename="' . addslashes($result['filename']) . '"')
                ->header('X-Bridge-Endpoint', $result['endpoint'])
                ->header('X-Bridge-Method', 'GET')
                ->header('X-Bridge-Http-Status', (string) ($result['http_status'] ?? 200))
                ->header('X-Bridge-Content-Type', (string) ($result['bridge_content_type'] ?? ''));
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error al descargar XML: ' . $e->getMessage()], 500);
        }
    }

    public function downloadSunatCdr(Request $request, int $id)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) ($authUser->company_id ?? 0);

        try {
            $result = $this->taxBridgeService->downloadDocument($companyId, $id, 'dowload_cdr');

            return response($result['body'], 200)
                ->header('Content-Type', $result['content_type'])
                ->header('Content-Disposition', 'attachment; filename="' . addslashes($result['filename']) . '"')
                ->header('X-Bridge-Endpoint', $result['endpoint'])
                ->header('X-Bridge-Method', 'GET')
                ->header('X-Bridge-Http-Status', (string) ($result['http_status'] ?? 200))
                ->header('X-Bridge-Content-Type', (string) ($result['bridge_content_type'] ?? ''));
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error al descargar CDR: ' . $e->getMessage()], 500);
        }
    }
}

