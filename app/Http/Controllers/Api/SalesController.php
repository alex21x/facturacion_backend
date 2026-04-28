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
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class SalesController extends Controller
{    
    private $stockProjection = [];
    private $lotStockProjection = [];
    private array $activeVerticalCache = [];
    private array $verticalFeaturePreferenceCache = [];
    private array $featureContextResolutionCache = [];
    private array $tableExistsCache = [];
    private array $companyFeatureToggleMap = [];  // companyId:FEATURE_CODE => stdClass|null
    private array $branchFeatureToggleMap = [];   // companyId:branchId:FEATURE_CODE => stdClass|null
    private array $featureTogglePrewarmed = [];    // companyId => true, companyId:branchId => true

    public function __construct(
        private CompanyIgvRateService $companyIgvRateService,
        private TaxBridgeService $taxBridgeService,
        private CreateCommercialDocumentUseCase $createCommercialDocumentUseCase,
        private UpdateCommercialDocumentDraftUseCase $updateCommercialDocumentDraftUseCase,
        private VoidCommercialDocumentUseCase $voidCommercialDocumentUseCase
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
            $documentsResponse = $this->commercialDocuments($request);
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

        // Pre-warm toggle maps once — all subsequent feature lookups use the in-memory cache (2 DB queries total)
        $this->prewarmFeatureToggles($companyId, $branchId);

        $currencies = DB::table('core.currencies')
            ->select('id', 'code', 'name', 'symbol', 'is_default')
            ->where('status', 1)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $paymentMethods = DB::table('master.payment_types')
            ->select([
                'id',
                DB::raw("COALESCE(NULLIF(TRIM(comment), ''), CONCAT('PM', id::text)) as code"),
                'name',
            ])
            ->where(function ($query) {
                $query->where('is_active', 1)
                    ->orWhereIn('status', [1, 2]);
            })
            ->orderBy('name')
            ->get();

        $catalog = $this->documentKindCatalog();

        // Use pre-warmed company toggle map instead of a separate query
        $ck = (string) $companyId;
        $enabledToggles = collect($this->companyFeatureToggleMap)
            ->filter(fn ($row, $key) => str_starts_with($key, $ck . ':DOC_KIND_'))
            ->mapWithKeys(fn ($row, $key) => [substr($key, strlen($ck) + 1) => $row->is_enabled]);

        $documentKinds = $catalog->filter(function ($row) use ($enabledToggles) {
            $featureCode = 'DOC_KIND_' . $row['code'];
            $tableEnabled = (bool) ($row['is_enabled'] ?? true);

            return $tableEnabled
                && (!$enabledToggles->has($featureCode) || (bool) $enabledToggles->get($featureCode));
        })->values();

        if ($documentKinds->isEmpty()) {
            $documentKinds = $catalog->values();
        }

        $commerceFeatureDefaults = [
            'SALES_SELLER_TO_CASHIER' => false,
            'SALES_CUSTOMER_PRICE_PROFILE' => false,
            'SALES_WORKSHOP_MULTI_VEHICLE' => false,
            'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL' => true,
            'SALES_ANTICIPO_ENABLED' => false,
            'SALES_TAX_BRIDGE' => false,
            'SALES_TAX_BRIDGE_DEBUG_VIEW' => false,
            'SALES_GLOBAL_DISCOUNT_ENABLED' => false,
            'SALES_ITEM_DISCOUNT_ENABLED' => false,
            'SALES_FREE_ITEMS_ENABLED' => false,
            'SALES_ALLOW_DRAFT_EDIT' => true,
            'SALES_ALLOW_DOCUMENT_VOID' => true,
            'SALES_ALLOW_VOID_FOR_SELLER' => true,
            'SALES_ALLOW_VOID_FOR_CASHIER' => true,
            'SALES_ALLOW_VOID_FOR_ADMIN' => true,
            'SALES_VOID_REVERSE_STOCK' => true,
        ];
        $commerceFeatureCodes = array_keys($commerceFeatureDefaults);

        $commerceFeatures = collect($commerceFeatureCodes)->map(function ($featureCode) use ($companyId, $branchId, $commerceFeatureDefaults) {
            $resolved = $this->resolveFeatureResolutionForContext(
                $companyId,
                $branchId,
                (string) $featureCode,
                (bool) ($commerceFeatureDefaults[$featureCode] ?? false)
            );

            return [
                'feature_code' => $featureCode,
                'is_enabled' => $resolved['is_enabled'],
                'company_enabled' => $resolved['company_enabled'],
                'branch_enabled' => $resolved['branch_enabled'],
                'config' => $resolved['config'],
                'vertical_source' => $resolved['vertical_source'],
            ];
        })->values();

        $salesDetraccionEnabled = $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_DETRACCION_ENABLED', false);
        $salesRetencionEnabled = $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_RETENCION_ENABLED', false);
        $salesPercepcionEnabled = $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_PERCEPCION_ENABLED', false);

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
            'company_profile' => $this->resolveCompanyPrintProfile($companyId),
        ]);
    }

    private function resolveCompanyPrintProfile(int $companyId): array
    {
        $companyColumns = $this->tableColumns('core.companies');
        $companyEmailColumn = $this->firstExistingColumn($companyColumns, ['email', 'contact_email']);

        $companySelect = ['tax_id', 'legal_name', 'trade_name'];
        if ($companyEmailColumn) {
            $companySelect[] = $companyEmailColumn;
        }

        $company = DB::table('core.companies')
            ->select($companySelect)
            ->where('id', $companyId)
            ->first();

        $settings = null;
        if ($this->tableExists('core.company_settings')) {
            $settingColumns = $this->tableColumns('core.company_settings');
            $settingEmailColumn = $this->firstExistingColumn($settingColumns, ['email', 'contact_email']);

            $settingsSelect = ['address', 'phone', 'logo_path'];
            if ($settingEmailColumn) {
                $settingsSelect[] = $settingEmailColumn;
            }

            $settings = DB::table('core.company_settings')
                ->select($settingsSelect)
                ->where('company_id', $companyId)
                ->first();
        }

        $companyEmail = null;
        if ($settings) {
            $companyEmail = (string) ($settings->email ?? $settings->contact_email ?? '');
        }
        if ($companyEmail === null || trim($companyEmail) === '') {
            $companyEmail = (string) ($company->email ?? $company->contact_email ?? '');
        }
        $companyEmail = trim($companyEmail) !== '' ? trim($companyEmail) : null;

        $logoUrl = null;
        if ($settings && !empty($settings->logo_path)) {
            try {
                $logoUrl = \Storage::disk('public')->exists($settings->logo_path)
                    ? '/storage/' . ltrim((string) $settings->logo_path, '/')
                    : null;
            } catch (\Throwable $e) {
                $logoUrl = null;
            }
        }

        return [
            'tax_id'     => $company->tax_id ?? null,
            'legal_name' => $company->legal_name ?? '',
            'trade_name' => $company->trade_name ?? null,
            'address'    => $settings->address ?? null,
            'phone'      => $settings->phone ?? null,
            'email'      => $companyEmail,
            'logo_url'   => $logoUrl,
        ];
    }

    private function isFeatureEnabled(int $companyId, $branchId, string $featureCode): bool
    {
        $resolvedBranchId = $branchId !== null ? (int) $branchId : null;
        $resolved = $this->resolveFeatureResolutionForContext($companyId, $resolvedBranchId, $featureCode, false);

        return (bool) $resolved['is_enabled'];
    }

    public function referenceDocuments(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $customerId = (int) $request->query('customer_id', 0);
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $documentKindId = (int) $request->query('document_kind_id', 0);
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
            ->whereNotIn('d.status', ['VOID', 'CANCELED']);

        $noteTargetKind = null;
        if ($documentKindId > 0) {
            $catalogRow = $this->findDocumentKindCatalogRowById($documentKindId);
            if (is_array($catalogRow) && !empty($catalogRow['note_target_kind'])) {
                $noteTargetKind = (string) $catalogRow['note_target_kind'];
            }
        }

        if ($noteTargetKind === null && in_array($noteKind, ['CREDIT_NOTE', 'DEBIT_NOTE'], true)) {
            $noteTargetKind = 'RECEIPT';
        }

        if ($noteTargetKind !== null) {
            $query->where('d.document_kind', $noteTargetKind);
        } else {
            $query->whereIn('d.document_kind', ['INVOICE', 'RECEIPT']);
        }

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
        $workshopVehicleSearchEnabled = $this->isWorkshopMultiVehicleEnabledForContext($companyId, null)
            && $this->tableExists('sales.customer_vehicles');

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
            $like = '%' . $search . '%';
            $normalizedDoc = preg_replace('/\D+/', '', $search);

            $query->where(function ($nested) use ($like, $normalizedDoc, $workshopVehicleSearchEnabled) {
                $nested->where('c.doc_number', 'ilike', $like)
                    ->orWhere('c.legal_name', 'ilike', $like)
                    ->orWhere('c.trade_name', 'ilike', $like)
                    ->orWhere('c.first_name', 'ilike', $like)
                    ->orWhere('c.last_name', 'ilike', $like)
                    ->orWhere('c.plate', 'ilike', $like)
                    ->orWhereRaw("CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) ILIKE ?", [$like]);

                if ($normalizedDoc !== '') {
                    $nested->orWhereRaw("REGEXP_REPLACE(COALESCE(c.doc_number, ''), '\\D', '', 'g') ILIKE ?", ['%' . $normalizedDoc . '%']);
                }

                if ($workshopVehicleSearchEnabled) {
                    $nested->orWhereExists(function ($vehicleQuery) use ($like, $normalizedDoc) {
                        $vehicleQuery->select(DB::raw('1'))
                            ->from('sales.customer_vehicles as cv')
                            ->whereColumn('cv.company_id', 'c.company_id')
                            ->whereColumn('cv.customer_id', 'c.id')
                            ->where('cv.status', 1)
                            ->where(function ($vehicleNested) use ($like, $normalizedDoc) {
                                $vehicleNested->where('cv.plate', 'ilike', $like)
                                    ->orWhere('cv.brand', 'ilike', $like)
                                    ->orWhere('cv.model', 'ilike', $like);

                                if ($normalizedDoc !== '') {
                                    $vehicleNested->orWhere('cv.plate_normalized', 'ilike', '%' . $normalizedDoc . '%');
                                }
                            });
                    });
                }
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

    public function resolveCustomerByDocument(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $document = preg_replace('/\D+/', '', (string) $request->query('document', ''));
        if (!is_string($document)) {
            $document = '';
        }

        if ($document === '' || !in_array(strlen($document), [8, 11], true)) {
            return response()->json([
                'message' => 'Debe enviar un DNI (8) o RUC (11) valido.',
            ], 422);
        }

        $this->ensureCustomerPriceProfilesTable();

        $existing = $this->fetchCustomerRowByDocument($companyId, $document);
        if ($existing) {
            return response()->json([
                'data' => $this->customerSuggestionFromRow($existing),
                'source' => 'local',
                'created' => false,
                'message' => 'Cliente encontrado en base local.',
            ]);
        }

        $isDni = strlen($document) === 8;
        $source = $isDni ? 'reniec' : 'sunat';

        try {
            if ($isDni) {
                $response = Http::timeout(10)
                    ->acceptJson()
                    ->get('https://mundosoftperu.com/reniec/consulta_reniec.php', ['dni' => $document]);

                if (!$response->ok()) {
                    return response()->json(['message' => 'No se pudo consultar RENIEC.'], 502);
                }

                $json = $response->json();
                if (!is_array($json) || !isset($json[0]) || (string) $json[0] !== $document) {
                    return response()->json(['message' => 'Numero no existe en RENIEC.'], 404);
                }

                $fullName = trim(implode(' ', array_filter([
                    (string) ($json[2] ?? ''),
                    (string) ($json[3] ?? ''),
                    (string) ($json[1] ?? ''),
                ])));

                if ($fullName === '') {
                    return response()->json(['message' => 'RENIEC no devolvio nombre valido.'], 404);
                }

                $customerId = DB::table('sales.customers')->insertGetId([
                    'company_id' => $companyId,
                    'doc_type' => '1',
                    'customer_type_id' => $this->resolveCustomerTypeIdBySunatCode(1),
                    'doc_number' => $document,
                    'legal_name' => $fullName,
                    'trade_name' => null,
                    'first_name' => null,
                    'last_name' => null,
                    'plate' => null,
                    'address' => 'LIMA',
                    'status' => 1,
                ]);

                $created = $this->fetchCustomerRowById($companyId, (int) $customerId);
                if (!$created) {
                    return response()->json(['message' => 'No se pudo registrar el cliente consultado.'], 500);
                }

                return response()->json([
                    'data' => $this->customerSuggestionFromRow($created),
                    'source' => $source,
                    'created' => true,
                    'message' => 'Cliente consultado y registrado correctamente.',
                ]);
            }

            $response = Http::timeout(10)
                ->acceptJson()
                ->get('https://mundosoftperu.com/sunat/sunat/consulta.php', ['nruc' => $document]);

            if (!$response->ok()) {
                return response()->json(['message' => 'No se pudo consultar SUNAT.'], 502);
            }

            $json = $response->json();
            $result = is_array($json) ? ($json['result'] ?? null) : null;
            $ruc = is_array($result) ? (string) ($result['RUC'] ?? '') : '';
            $razon = is_array($result) ? trim((string) ($result['RazonSocial'] ?? '')) : '';
            $direccion = is_array($result) ? trim((string) ($result['Direccion'] ?? '')) : '';

            if ($ruc !== $document || $razon === '') {
                return response()->json(['message' => 'Numero no existe en SUNAT.'], 404);
            }

            $customerId = DB::table('sales.customers')->insertGetId([
                'company_id' => $companyId,
                'doc_type' => '6',
                'customer_type_id' => $this->resolveCustomerTypeIdBySunatCode(6),
                'doc_number' => $document,
                'legal_name' => $razon,
                'trade_name' => null,
                'first_name' => null,
                'last_name' => null,
                'plate' => null,
                'address' => $direccion !== '' ? $direccion : null,
                'status' => 1,
            ]);

            $created = $this->fetchCustomerRowById($companyId, (int) $customerId);
            if (!$created) {
                return response()->json(['message' => 'No se pudo registrar el cliente consultado.'], 500);
            }

            return response()->json([
                'data' => $this->customerSuggestionFromRow($created),
                'source' => $source,
                'created' => true,
                'message' => 'Cliente consultado y registrado correctamente.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al consultar padron externo.',
                'detail' => $e->getMessage(),
            ], 502);
        }
    }

    private function resolveCustomerTypeIdBySunatCode(int $sunatCode): ?int
    {
        $row = DB::table('sales.customer_types')
            ->where('sunat_code', $sunatCode)
            ->where('is_active', true)
            ->select('id')
            ->first();

        return $row ? (int) $row->id : null;
    }

    private function fetchCustomerRowByDocument(int $companyId, string $document)
    {
        return DB::table('sales.customers as c')
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
            ->where('c.doc_number', $document)
            ->orderByDesc('c.id')
            ->first();
    }

    private function fetchCustomerRowById(int $companyId, int $id)
    {
        return DB::table('sales.customers as c')
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
            ->where('c.id', $id)
            ->first();
    }

    private function customerSuggestionFromRow($row): array
    {
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
    }

    public function customers(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $limit = (int) $request->query('limit', 100);
        $workshopVehicleSearchEnabled = $this->isWorkshopMultiVehicleEnabledForContext($companyId, null)
            && $this->tableExists('sales.customer_vehicles');

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
            $like = '%' . $search . '%';
            $normalizedDoc = preg_replace('/\D+/', '', $search);

            $query->where(function ($nested) use ($like, $normalizedDoc, $workshopVehicleSearchEnabled) {
                $nested->where('c.doc_number', 'ilike', $like)
                    ->orWhere('c.legal_name', 'ilike', $like)
                    ->orWhere('c.trade_name', 'ilike', $like)
                    ->orWhere('c.first_name', 'ilike', $like)
                    ->orWhere('c.last_name', 'ilike', $like)
                    ->orWhere('c.plate', 'ilike', $like)
                    ->orWhereRaw("CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) ILIKE ?", [$like]);

                if ($normalizedDoc !== '') {
                    $nested->orWhereRaw("REGEXP_REPLACE(COALESCE(c.doc_number, ''), '\\D', '', 'g') ILIKE ?", ['%' . $normalizedDoc . '%']);
                }

                if ($workshopVehicleSearchEnabled) {
                    $nested->orWhereExists(function ($vehicleQuery) use ($like, $normalizedDoc) {
                        $vehicleQuery->select(DB::raw('1'))
                            ->from('sales.customer_vehicles as cv')
                            ->whereColumn('cv.company_id', 'c.company_id')
                            ->whereColumn('cv.customer_id', 'c.id')
                            ->where('cv.status', 1)
                            ->where(function ($vehicleNested) use ($like, $normalizedDoc) {
                                $vehicleNested->where('cv.plate', 'ilike', $like)
                                    ->orWhere('cv.brand', 'ilike', $like)
                                    ->orWhere('cv.model', 'ilike', $like);

                                if ($normalizedDoc !== '') {
                                    $vehicleNested->orWhere('cv.plate_normalized', 'ilike', '%' . $normalizedDoc . '%');
                                }
                            });
                    });
                }
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

    public function customerVehicles(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$this->isWorkshopMultiVehicleEnabledForRequest($request, $companyId)) {
            return response()->json(['message' => 'Funcionalidad no habilitada para esta empresa'], 404);
        }

        if (!$this->tableExists('sales.customer_vehicles')) {
            return response()->json(['message' => 'La tabla de vehiculos aun no existe en esta instancia'], 503);
        }

        $customerExists = DB::table('sales.customers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$customerExists) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        $rows = DB::table('sales.customer_vehicles')
            ->select('id', 'customer_id', 'plate', 'brand', 'model', 'year', 'color', 'vin', 'is_default', 'status')
            ->where('company_id', $companyId)
            ->where('customer_id', $id)
            ->where('status', 1)
            ->orderByDesc('is_default')
            ->orderBy('brand')
            ->orderBy('model')
            ->orderBy('plate')
            ->get()
            ->map(fn($row) => [
                'id' => (int) $row->id,
                'customer_id' => (int) $row->customer_id,
                'plate' => (string) $row->plate,
                'brand' => $row->brand !== null ? (string) $row->brand : null,
                'model' => $row->model !== null ? (string) $row->model : null,
                'year' => $row->year !== null ? (int) $row->year : null,
                'color' => $row->color !== null ? (string) $row->color : null,
                'vin' => $row->vin !== null ? (string) $row->vin : null,
                'is_default' => (bool) ($row->is_default ?? false),
                'status' => (int) ($row->status ?? 1),
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function createCustomerVehicle(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$this->isWorkshopMultiVehicleEnabledForRequest($request, $companyId)) {
            return response()->json(['message' => 'Funcionalidad no habilitada para esta empresa'], 404);
        }

        if (!$this->tableExists('sales.customer_vehicles')) {
            return response()->json(['message' => 'La tabla de vehiculos aun no existe en esta instancia'], 503);
        }

        $customerExists = DB::table('sales.customers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$customerExists) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'plate' => 'required|string|max:20',
            'brand' => 'nullable|string|max:80',
            'model' => 'nullable|string|max:80',
            'year' => 'nullable|integer|min:1900|max:2100',
            'color' => 'nullable|string|max:40',
            'vin' => 'nullable|string|max:50',
            'is_default' => 'nullable|boolean',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $plateNormalized = $this->normalizeVehiclePlate((string) ($payload['plate'] ?? ''));
        if ($plateNormalized === '') {
            return response()->json(['message' => 'La placa ingresada no es valida'], 422);
        }

        $duplicate = DB::table('sales.customer_vehicles')
            ->where('company_id', $companyId)
            ->where('plate_normalized', $plateNormalized)
            ->where('status', 1)
            ->exists();

        if ($duplicate) {
            return response()->json(['message' => 'La placa ya esta registrada para otro cliente'], 422);
        }

        $isDefault = (bool) ($payload['is_default'] ?? false);
        if ($isDefault) {
            DB::table('sales.customer_vehicles')
                ->where('company_id', $companyId)
                ->where('customer_id', $id)
                ->update(['is_default' => false, 'updated_at' => now()]);
        }

        $newId = DB::table('sales.customer_vehicles')->insertGetId([
            'company_id' => $companyId,
            'customer_id' => $id,
            'plate' => strtoupper(trim((string) $payload['plate'])),
            'plate_normalized' => $plateNormalized,
            'brand' => $payload['brand'] ?? null,
            'model' => $payload['model'] ?? null,
            'year' => $payload['year'] ?? null,
            'color' => $payload['color'] ?? null,
            'vin' => $payload['vin'] ?? null,
            'is_default' => $isDefault,
            'status' => (int) ($payload['status'] ?? 1),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Vehicle created', 'id' => (int) $newId], 201);
    }

    public function updateCustomerVehicle(Request $request, int $id, int $vehicleId)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$this->isWorkshopMultiVehicleEnabledForRequest($request, $companyId)) {
            return response()->json(['message' => 'Funcionalidad no habilitada para esta empresa'], 404);
        }

        if (!$this->tableExists('sales.customer_vehicles')) {
            return response()->json(['message' => 'La tabla de vehiculos aun no existe en esta instancia'], 503);
        }

        $vehicle = DB::table('sales.customer_vehicles')
            ->where('id', $vehicleId)
            ->where('company_id', $companyId)
            ->where('customer_id', $id)
            ->first();

        if (!$vehicle) {
            return response()->json(['message' => 'Vehicle not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'plate' => 'nullable|string|max:20',
            'brand' => 'nullable|string|max:80',
            'model' => 'nullable|string|max:80',
            'year' => 'nullable|integer|min:1900|max:2100',
            'color' => 'nullable|string|max:40',
            'vin' => 'nullable|string|max:50',
            'is_default' => 'nullable|boolean',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $changes = $validator->validated();
        $update = [];

        if (array_key_exists('plate', $changes)) {
            $plateNormalized = $this->normalizeVehiclePlate((string) $changes['plate']);
            if ($plateNormalized === '') {
                return response()->json(['message' => 'La placa ingresada no es valida'], 422);
            }

            $duplicate = DB::table('sales.customer_vehicles')
                ->where('company_id', $companyId)
                ->where('plate_normalized', $plateNormalized)
                ->where('status', 1)
                ->where('id', '<>', $vehicleId)
                ->exists();

            if ($duplicate) {
                return response()->json(['message' => 'La placa ya esta registrada para otro cliente'], 422);
            }

            $update['plate'] = strtoupper(trim((string) $changes['plate']));
            $update['plate_normalized'] = $plateNormalized;
        }

        foreach (['brand', 'model', 'year', 'color', 'vin', 'status'] as $field) {
            if (array_key_exists($field, $changes)) {
                $update[$field] = $changes[$field];
            }
        }

        if (array_key_exists('is_default', $changes)) {
            $isDefault = (bool) $changes['is_default'];
            if ($isDefault) {
                DB::table('sales.customer_vehicles')
                    ->where('company_id', $companyId)
                    ->where('customer_id', $id)
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }
            $update['is_default'] = $isDefault;
        }

        if (empty($update)) {
            return response()->json(['message' => 'No changes provided'], 422);
        }

        $update['updated_at'] = now();

        DB::table('sales.customer_vehicles')
            ->where('id', $vehicleId)
            ->where('company_id', $companyId)
            ->where('customer_id', $id)
            ->update($update);

        return response()->json(['message' => 'Vehicle updated']);
    }

    public function deleteCustomerVehicle(Request $request, int $id, int $vehicleId)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$this->isWorkshopMultiVehicleEnabledForRequest($request, $companyId)) {
            return response()->json(['message' => 'Funcionalidad no habilitada para esta empresa'], 404);
        }

        if (!$this->tableExists('sales.customer_vehicles')) {
            return response()->json(['message' => 'La tabla de vehiculos aun no existe en esta instancia'], 503);
        }

        $vehicle = DB::table('sales.customer_vehicles')
            ->where('id', $vehicleId)
            ->where('company_id', $companyId)
            ->where('customer_id', $id)
            ->where('status', 1)
            ->first(['id', 'is_default']);

        if (!$vehicle) {
            return response()->json(['message' => 'Vehicle not found'], 404);
        }

        DB::table('sales.customer_vehicles')
            ->where('id', $vehicleId)
            ->where('company_id', $companyId)
            ->where('customer_id', $id)
            ->update([
                'status' => 0,
                'is_default' => false,
                'updated_at' => now(),
            ]);

        if ((bool) ($vehicle->is_default ?? false)) {
            $replacement = DB::table('sales.customer_vehicles')
                ->where('company_id', $companyId)
                ->where('customer_id', $id)
                ->where('status', 1)
                ->orderBy('id')
                ->first(['id']);

            if ($replacement) {
                DB::table('sales.customer_vehicles')
                    ->where('id', (int) $replacement->id)
                    ->update(['is_default' => true, 'updated_at' => now()]);
            }
        }

        return response()->json(['message' => 'Vehicle deleted']);
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
        $documentKindRule = 'required_without:document_kind_id|string|in:' . implode(',', $this->documentKindCodes());

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'cash_register_id' => 'nullable|integer|min:1',
            'document_kind_id' => 'nullable|integer|min:1',
            'document_kind' => $documentKindRule,
            'series' => 'required|string|max:10',
            'issue_at' => 'nullable|date',
            'due_at' => 'nullable|date',
            'customer_id' => 'required|integer|min:1',
            'customer_vehicle_id' => 'nullable|integer|min:1',
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
        $documentKindId = array_key_exists('document_kind_id', $payload) ? (int) $payload['document_kind_id'] : 0;
        if ($documentKindId > 0) {
            $catalogRow = $this->findDocumentKindCatalogRowById($documentKindId);
            if (!is_array($catalogRow)) {
                return response()->json([
                    'message' => 'document_kind_id invalido',
                ], 422);
            }

            $payload['document_kind'] = (string) ($catalogRow['code'] ?? '');
            $payload['document_kind_id'] = $documentKindId;
        }
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
        $noteBaseKind = $this->resolveNoteBaseKind($documentKind);
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $customerIdentity = $this->fetchCustomerIdentityForSalesValidation($companyId, (int) $payload['customer_id']);

        if (!$customerIdentity) {
            return response()->json([
                'message' => 'Cliente no encontrado para emitir documento',
            ], 422);
        }

        if ($this->documentKindRequiresRucCustomer($documentKind) && !$this->customerHasRucIdentity($customerIdentity)) {
            return response()->json([
                'message' => 'Para este tipo de documento el cliente debe tener RUC valido (11 digitos).',
            ], 422);
        }

        $workshopMultiVehicleEnabled = $this->isWorkshopMultiVehicleEnabledForRequest($request, $companyId)
            && $this->tableExists('sales.customer_vehicles');

        $selectedVehicleId = isset($payload['customer_vehicle_id']) ? (int) $payload['customer_vehicle_id'] : 0;
        if ($selectedVehicleId > 0 && !$workshopMultiVehicleEnabled) {
            return response()->json([
                'message' => 'La empresa no tiene habilitado el flujo de vehiculos por cliente.',
            ], 422);
        }

        if ($selectedVehicleId > 0) {
            $vehicle = DB::table('sales.customer_vehicles')
                ->select('id', 'plate', 'brand', 'model')
                ->where('id', $selectedVehicleId)
                ->where('company_id', $companyId)
                ->where('customer_id', (int) $payload['customer_id'])
                ->where('status', 1)
                ->first();

            if (!$vehicle) {
                return response()->json([
                    'message' => 'El vehiculo seleccionado no pertenece al cliente o no esta activo.',
                ], 422);
            }

            $payload['customer_vehicle_id'] = (int) $vehicle->id;
            $payload['vehicle_plate_snapshot'] = strtoupper(trim((string) ($vehicle->plate ?? '')));
            $payload['vehicle_brand_snapshot'] = trim((string) ($vehicle->brand ?? '')) !== '' ? trim((string) $vehicle->brand) : null;
            $payload['vehicle_model_snapshot'] = trim((string) ($vehicle->model ?? '')) !== '' ? trim((string) $vehicle->model) : null;
            $payload['metadata'] = array_merge($metadata, [
                'customer_vehicle_id' => (int) $vehicle->id,
                'vehicle_plate' => $payload['vehicle_plate_snapshot'],
                'vehicle_brand' => $payload['vehicle_brand_snapshot'],
                'vehicle_model' => $payload['vehicle_model_snapshot'],
            ]);
            $metadata = $payload['metadata'];
        }

        if ($noteBaseKind !== null) {
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

            $noteReasons = $this->resolveDocumentNoteReasons($noteBaseKind);
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
        $documentKindRule = 'sometimes|string|in:' . implode(',', $this->documentKindCodes());

        $validator = Validator::make($request->all(), [
            'document_kind_id' => 'nullable|integer|min:1',
            'document_kind' => $documentKindRule,
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
        $documentKindId = array_key_exists('document_kind_id', $payload) ? (int) $payload['document_kind_id'] : 0;
        if ($documentKindId > 0) {
            $catalogRow = $this->findDocumentKindCatalogRowById($documentKindId);
            if (!is_array($catalogRow)) {
                return response()->json([
                    'message' => 'document_kind_id invalido',
                ], 422);
            }

            $payload['document_kind'] = (string) ($catalogRow['code'] ?? '');
            $payload['document_kind_id'] = $documentKindId;
        }

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
        $branchIdFilter = $request->query('branch_id', $authUser->branch_id);
        $resolvedBranchId = ($branchIdFilter !== null && $branchIdFilter !== '') ? (int) $branchIdFilter : null;
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));
        $roleCode    = strtoupper(trim((string) ($authUser->role_code    ?? '')));
        $isSellerUser = !str_contains($roleCode, 'ADMIN')
            && ($roleProfile === 'SELLER'
                || str_contains($roleCode, 'VENDED')
                || str_contains($roleCode, 'SELLER'));
        $workshopVehicleSearchEnabled = $this->isWorkshopMultiVehicleEnabledForContext($companyId, $resolvedBranchId)
            && $this->tableExists('sales.customer_vehicles');

        $filters = [
            'branch_id' => $branchIdFilter,
            'warehouse_id' => $request->query('warehouse_id'),
            'cash_register_id' => $request->query('cash_register_id'),
            'document_kind' => $request->query('document_kind'),
            'document_kind_id' => $request->query('document_kind_id'),
            'status' => $request->query('status'),
            'conversion_state' => $request->query('conversion_state'),
            'customer' => trim((string) $request->query('customer', '')),
            'customer_id' => $request->query('customer_id'),
            'vehicle' => trim((string) $request->query('vehicle', '')),
            'customer_vehicle_id' => $request->query('customer_vehicle_id'),
            'issue_date_from' => $request->query('issue_date_from'),
            'issue_date_to' => $request->query('issue_date_to'),
            'series' => trim((string) $request->query('series', '')),
            'number' => trim((string) $request->query('number', '')),
            'seller_user_id' => $isSellerUser ? (int) $authUser->id : null,
            'workshop_vehicle_search_enabled' => $workshopVehicleSearchEnabled,
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
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'd.payment_method_id')
            ->select([
                'd.id',
                'd.company_id',
                'd.branch_id',
                'd.document_kind',
                'd.document_kind_id',
                DB::raw("COALESCE((SELECT dk.label FROM sales.document_kinds dk WHERE dk.id = d.document_kind_id LIMIT 1), (SELECT dk2.label FROM sales.document_kinds dk2 WHERE UPPER(dk2.code) = UPPER(d.document_kind) LIMIT 1), d.document_kind) as document_kind_label"),
                DB::raw("COALESCE((SELECT CASE WHEN UPPER(dk.code) LIKE 'CREDIT_NOTE_%' THEN 'CREDIT_NOTE' WHEN UPPER(dk.code) LIKE 'DEBIT_NOTE_%' THEN 'DEBIT_NOTE' ELSE UPPER(dk.code) END FROM sales.document_kinds dk WHERE dk.id = d.document_kind_id LIMIT 1), (SELECT CASE WHEN UPPER(dk2.code) LIKE 'CREDIT_NOTE_%' THEN 'CREDIT_NOTE' WHEN UPPER(dk2.code) LIKE 'DEBIT_NOTE_%' THEN 'DEBIT_NOTE' ELSE UPPER(dk2.code) END FROM sales.document_kinds dk2 WHERE UPPER(dk2.code) = UPPER(d.document_kind) LIMIT 1), CASE WHEN UPPER(d.document_kind) LIKE 'CREDIT_NOTE_%' THEN 'CREDIT_NOTE' WHEN UPPER(d.document_kind) LIKE 'DEBIT_NOTE_%' THEN 'DEBIT_NOTE' ELSE UPPER(d.document_kind) END) as document_kind_base"),
                DB::raw("CASE WHEN COALESCE((SELECT CASE WHEN UPPER(dk.code) LIKE 'CREDIT_NOTE_%' THEN 'CREDIT_NOTE' WHEN UPPER(dk.code) LIKE 'DEBIT_NOTE_%' THEN 'DEBIT_NOTE' ELSE UPPER(dk.code) END FROM sales.document_kinds dk WHERE dk.id = d.document_kind_id LIMIT 1), (SELECT CASE WHEN UPPER(dk2.code) LIKE 'CREDIT_NOTE_%' THEN 'CREDIT_NOTE' WHEN UPPER(dk2.code) LIKE 'DEBIT_NOTE_%' THEN 'DEBIT_NOTE' ELSE UPPER(dk2.code) END FROM sales.document_kinds dk2 WHERE UPPER(dk2.code) = UPPER(d.document_kind) LIMIT 1), CASE WHEN UPPER(d.document_kind) LIKE 'CREDIT_NOTE_%' THEN 'CREDIT_NOTE' WHEN UPPER(d.document_kind) LIKE 'DEBIT_NOTE_%' THEN 'DEBIT_NOTE' ELSE UPPER(d.document_kind) END) IN ('INVOICE','RECEIPT','CREDIT_NOTE','DEBIT_NOTE') THEN true ELSE false END as is_tributary_document"),
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
                DB::raw("COALESCE((d.metadata->>'sunat_void_status'), '') as sunat_void_status"),
                DB::raw("NULLIF((d.metadata->>'sunat_summary_id'), '')::BIGINT as sunat_summary_id"),
                DB::raw("NULLIF((d.metadata->>'sunat_void_summary_id'), '')::BIGINT as sunat_void_summary_id"),
                                DB::raw("(
                                        SELECT ds.status
                                        FROM sales.daily_summaries ds
                                        WHERE ds.company_id = d.company_id
                                            AND ds.id = NULLIF((d.metadata->>'sunat_summary_id'), '')::BIGINT
                                        LIMIT 1
                                ) as declaration_summary_status"),
                                DB::raw("(
                                        SELECT ds.status
                                        FROM sales.daily_summaries ds
                                        WHERE ds.company_id = d.company_id
                                            AND ds.id = NULLIF((d.metadata->>'sunat_void_summary_id'), '')::BIGINT
                                        LIMIT 1
                                ) as cancellation_summary_status"),
                'd.total',
                'd.balance_due',
                DB::raw('COALESCE(d.discount_total, 0) as global_discount_total'),
                DB::raw('COALESCE((SELECT SUM(COALESCE(di.discount_total, 0)) FROM sales.commercial_document_items di WHERE di.document_id = d.id), 0) as item_discount_total'),
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
                DB::raw("NULLIF(COALESCE((d.metadata->>'customer_vehicle_id'), (d.metadata->>'customerVehicleId')), '')::BIGINT as customer_vehicle_id"),
                DB::raw("NULLIF(COALESCE((d.metadata->>'vehicle_plate'), (d.metadata->>'vehiclePlateSnapshot')), '') as vehicle_plate_snapshot"),
                DB::raw("NULLIF(COALESCE((d.metadata->>'vehicle_brand'), (d.metadata->>'vehicleBrand')), '') as vehicle_brand_snapshot"),
                DB::raw("NULLIF(COALESCE((d.metadata->>'vehicle_model'), (d.metadata->>'vehicleModel')), '') as vehicle_model_snapshot"),
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
        $branchIdFilter = $request->query('branch_id', $authUser->branch_id);
        $resolvedBranchId = ($branchIdFilter !== null && $branchIdFilter !== '') ? (int) $branchIdFilter : null;
        $format = strtolower(trim((string) $request->query('format', 'csv')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));
        $roleCode    = strtoupper(trim((string) ($authUser->role_code    ?? '')));
        $isSellerUser = !str_contains($roleCode, 'ADMIN')
            && ($roleProfile === 'SELLER'
                || str_contains($roleCode, 'VENDED')
                || str_contains($roleCode, 'SELLER'));
        $workshopVehicleSearchEnabled = $this->isWorkshopMultiVehicleEnabledForContext($companyId, $resolvedBranchId)
            && $this->tableExists('sales.customer_vehicles');

        $filters = [
            'branch_id' => $branchIdFilter,
            'warehouse_id' => $request->query('warehouse_id'),
            'cash_register_id' => $request->query('cash_register_id'),
            'document_kind' => $request->query('document_kind'),
            'document_kind_id' => $request->query('document_kind_id'),
            'status' => $request->query('status'),
            'conversion_state' => $request->query('conversion_state'),
            'customer' => trim((string) $request->query('customer', '')),
            'customer_id' => $request->query('customer_id'),
            'vehicle' => trim((string) $request->query('vehicle', '')),
            'customer_vehicle_id' => $request->query('customer_vehicle_id'),
            'issue_date_from' => $request->query('issue_date_from'),
            'issue_date_to' => $request->query('issue_date_to'),
            'series' => trim((string) $request->query('series', '')),
            'number' => trim((string) $request->query('number', '')),
            'seller_user_id' => $isSellerUser ? (int) $authUser->id : null,
            'workshop_vehicle_search_enabled' => $workshopVehicleSearchEnabled,
        ];
        $detailMode = strtoupper(trim((string) $request->query('detail', 'SUMMARY')));

        $max = (int) $request->query('max', 5000);
        if ($max < 1) {
            $max = 1;
        }
        if ($max > 20000) {
            $max = 20000;
        }

        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'd.payment_method_id')
            ->select([
                'd.id',
                'd.document_kind',
                'd.document_kind_id',
                DB::raw("COALESCE((SELECT dk.label FROM sales.document_kinds dk WHERE dk.id = d.document_kind_id LIMIT 1), (SELECT dk2.label FROM sales.document_kinds dk2 WHERE UPPER(dk2.code) = UPPER(d.document_kind) LIMIT 1), d.document_kind) as document_kind_label"),
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
                                DB::raw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0) as source_document_id"),
                                DB::raw("(
                                        SELECT dsrc.document_kind
                                        FROM sales.commercial_documents dsrc
                                        WHERE dsrc.company_id = d.company_id
                                            AND dsrc.id = COALESCE((d.metadata->>'source_document_id')::BIGINT, 0)
                                        LIMIT 1
                                ) as source_document_kind"),
                                DB::raw("(
                                        SELECT CONCAT(dsrc.series, '-', dsrc.number)
                                        FROM sales.commercial_documents dsrc
                                        WHERE dsrc.company_id = d.company_id
                                            AND dsrc.id = COALESCE((d.metadata->>'source_document_id')::BIGINT, 0)
                                        LIMIT 1
                                ) as source_document_number"),
                DB::raw("COALESCE(pm.name, 'Sin metodo de pago') as payment_method_name"),
                DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
                DB::raw("NULLIF(COALESCE((d.metadata->>'customer_vehicle_id'), (d.metadata->>'customerVehicleId')), '')::BIGINT as customer_vehicle_id"),
                DB::raw("NULLIF(COALESCE((d.metadata->>'vehicle_plate'), (d.metadata->>'vehiclePlateSnapshot')), '') as vehicle_plate_snapshot"),
                DB::raw("NULLIF(COALESCE((d.metadata->>'vehicle_brand'), (d.metadata->>'vehicleBrand')), '') as vehicle_brand_snapshot"),
                DB::raw("NULLIF(COALESCE((d.metadata->>'vehicle_model'), (d.metadata->>'vehicleModel')), '') as vehicle_model_snapshot"),
            ])
            ->where('d.company_id', $companyId);

        $this->applyCommercialDocumentFilters($query, $filters);

        if ($detailMode === 'PRODUCT') {
            $detailRows = (clone $query)
                ->join('sales.commercial_document_items as di', 'di.document_id', '=', 'd.id')
                ->leftJoin('inventory.products as p', function ($join) {
                    $join->on('p.id', '=', 'di.product_id');
                })
                ->leftJoin('core.units as u', 'u.id', '=', 'di.unit_id')
                ->select([
                    'd.id',
                    'd.document_kind',
                    DB::raw("COALESCE((SELECT dk.label FROM sales.document_kinds dk WHERE dk.id = d.document_kind_id LIMIT 1), (SELECT dk2.label FROM sales.document_kinds dk2 WHERE UPPER(dk2.code) = UPPER(d.document_kind) LIMIT 1), d.document_kind) as document_kind_label"),
                    'd.series',
                    'd.number',
                    'd.issue_at',
                    'd.status',
                    DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
                    DB::raw("COALESCE(pm.name, 'Sin metodo de pago') as payment_method_name"),
                    DB::raw("NULLIF(COALESCE((d.metadata->>'customer_vehicle_id'), (d.metadata->>'customerVehicleId')), '')::BIGINT as customer_vehicle_id"),
                    DB::raw("NULLIF(COALESCE((d.metadata->>'vehicle_plate'), (d.metadata->>'vehiclePlateSnapshot')), '') as vehicle_plate_snapshot"),
                    DB::raw("NULLIF(COALESCE((d.metadata->>'vehicle_brand'), (d.metadata->>'vehicleBrand')), '') as vehicle_brand_snapshot"),
                    DB::raw("NULLIF(COALESCE((d.metadata->>'vehicle_model'), (d.metadata->>'vehicleModel')), '') as vehicle_model_snapshot"),
                    'di.product_id',
                    DB::raw("COALESCE(di.description, p.name, 'SIN DESCRIPCION') as product_description"),
                    DB::raw("COALESCE(u.code, '-') as unit_code"),
                    'di.qty',
                    'di.unit_price',
                    'di.total as line_total',
                ])
                ->orderBy('d.issue_at', 'desc')
                ->orderBy('d.id', 'desc')
                ->orderBy('di.line_no')
                ->limit($max)
                ->get();

            if ($format === 'json') {
                return response()->json([
                    'data' => $detailRows,
                    'meta' => [
                        'count' => (int) $detailRows->count(),
                        'max' => $max,
                        'detail' => 'PRODUCT',
                    ],
                ]);
            }

            $filename = 'reporte_ventas_producto_' . now()->format('Ymd_His') . '.csv';
            return response()->streamDownload(function () use ($detailRows) {
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    return;
                }

                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, [
                    'ID',
                    'Documento',
                    'Serie',
                    'Numero',
                    'Fecha Emision',
                    'Cliente',
                    'Vehiculo',
                    'Forma de Pago',
                    'Estado',
                    'Producto',
                    'Unidad',
                    'Cantidad',
                    'Precio Unitario',
                    'Total Linea',
                ], ';');

                foreach ($detailRows as $row) {
                    fputcsv($out, [
                        (int) $row->id,
                        (string) ($row->document_kind_label ?? $row->document_kind),
                        (string) $row->series,
                        (string) $row->number,
                        $row->issue_at ? (string) $row->issue_at : '',
                        (string) ($row->customer_name ?? ''),
                        trim(implode(' | ', array_values(array_filter([
                            (string) ($row->vehicle_plate_snapshot ?? ''),
                            (string) ($row->vehicle_brand_snapshot ?? ''),
                            (string) ($row->vehicle_model_snapshot ?? ''),
                        ], static function ($part) {
                            return trim($part) !== '';
                        })))),
                        (string) ($row->payment_method_name ?? 'Sin metodo de pago'),
                        (string) ($row->status ?? ''),
                        (string) ($row->product_description ?? ''),
                        (string) ($row->unit_code ?? '-'),
                        number_format((float) ($row->qty ?? 0), 3, '.', ''),
                        number_format((float) ($row->unit_price ?? 0), 2, '.', ''),
                        number_format((float) ($row->line_total ?? 0), 2, '.', ''),
                    ], ';');
                }

                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

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
                'Documento Afectado',
                'Fecha Emision',
                'Cliente',
                'Forma de Pago',
                'Estado',
                'Descuento Item',
                'Descuento Global',
                'Total',
                'Saldo',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($out, [
                    (int) $row->id,
                    (string) ($row->document_kind_label ?? $row->document_kind),
                    (string) $row->series,
                    (string) $row->number,
                    trim((string) (($row->source_document_kind ?? '') !== ''
                        ? (($row->source_document_kind ?? '') . ' ' . ($row->source_document_number ?? ''))
                        : ($row->source_document_number ?? ''))),
                    $row->issue_at ? (string) $row->issue_at : '',
                    (string) ($row->customer_name ?? ''),
                    (string) ($row->payment_method_name ?? 'Sin metodo de pago'),
                    (string) ($row->status_label ?? $row->status),
                    number_format((float) ($row->item_discount_total ?? 0), 2, '.', ''),
                    number_format((float) ($row->global_discount_total ?? 0), 2, '.', ''),
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
        $documentKindId = $filters['document_kind_id'] ?? null;
        $status = $filters['status'] ?? null;
        $conversionState = $filters['conversion_state'] ?? null;
        $customer = trim((string) ($filters['customer'] ?? ''));
        $customerId = (int) ($filters['customer_id'] ?? 0);
        $vehicle = trim((string) ($filters['vehicle'] ?? ''));
        $customerVehicleId = $filters['customer_vehicle_id'] ?? null;
        $issueDateFrom = $filters['issue_date_from'] ?? null;
        $issueDateTo = $filters['issue_date_to'] ?? null;
        $series = trim((string) ($filters['series'] ?? ''));
        $number = trim((string) ($filters['number'] ?? ''));
        $sellerUserId = isset($filters['seller_user_id']) ? (int) $filters['seller_user_id'] : null;
        $workshopVehicleSearchEnabled = (bool) ($filters['workshop_vehicle_search_enabled'] ?? false);

        if ($sellerUserId !== null && $sellerUserId > 0) {
            $query->where('d.created_by', $sellerUserId);
        }

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
            $normalizedKinds = array_values(array_filter(array_map(function ($kind) {
                return strtoupper(trim((string) $kind));
            }, $kinds)));

            if (count($normalizedKinds) > 0) {
                $query->where(function ($nested) use ($normalizedKinds) {
                    foreach ($normalizedKinds as $kind) {
                        if ($kind === 'CREDIT_NOTE' || $kind === 'DEBIT_NOTE') {
                            $nested->orWhereRaw('UPPER(d.document_kind) LIKE ?', [$kind . '%']);
                            continue;
                        }

                        $nested->orWhereRaw('UPPER(d.document_kind) = ?', [$kind]);
                    }
                });
            }
        }

        if ($documentKindId) {
            $ids = array_values(array_filter(array_map(function ($id) {
                $value = (int) trim((string) $id);
                return $value > 0 ? $value : null;
            }, explode(',', (string) $documentKindId))));

            if (count($ids) > 0) {
                $fallbackCodes = [];
                if ($this->tableExists('sales.document_kinds')) {
                    $fallbackCodes = DB::table('sales.document_kinds')
                        ->whereIn('id', $ids)
                        ->pluck('code')
                        ->map(function ($code) {
                            return strtoupper(trim((string) $code));
                        })
                        ->filter(function ($code) {
                            return $code !== '';
                        })
                        ->values()
                        ->all();
                }

                $query->where(function ($nested) use ($ids, $fallbackCodes) {
                    $nested->whereIn('d.document_kind_id', $ids);

                    if (!empty($fallbackCodes)) {
                        $nested->orWhere(function ($legacy) use ($fallbackCodes) {
                            $legacy->whereNull('d.document_kind_id')
                                ->whereIn(DB::raw('UPPER(d.document_kind)'), $fallbackCodes);
                        });
                    }
                });
            }
        }

        if ($status) {
            $query->where('d.status', (string) $status);
        }

        if ($customerId > 0) {
            $query->where('d.customer_id', $customerId);
        } elseif ($customer !== '') {
            $like = '%' . $customer . '%';
            $query->where(function ($nested) use ($like, $workshopVehicleSearchEnabled) {
                $nested->where('c.legal_name', 'ilike', $like)
                    ->orWhereRaw("CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) ILIKE ?", [$like])
                    ->orWhere('c.doc_number', 'ilike', $like);

                if ($workshopVehicleSearchEnabled) {
                    $nested->orWhereExists(function ($vehicleQuery) use ($like) {
                        $vehicleQuery->select(DB::raw('1'))
                            ->from('sales.customer_vehicles as cv')
                            ->whereColumn('cv.customer_id', 'c.id')
                            ->whereColumn('cv.company_id', 'd.company_id')
                            ->where('cv.status', 1)
                            ->where(function ($vehicleNested) use ($like) {
                                $vehicleNested->where('cv.plate', 'ilike', $like)
                                    ->orWhere('cv.brand', 'ilike', $like)
                                    ->orWhere('cv.model', 'ilike', $like);
                            });
                    });
                }
            });
        }

        if ($workshopVehicleSearchEnabled && $vehicle !== '') {
            $vehicleLike = '%' . $vehicle . '%';
            $query->where(function ($nested) use ($vehicleLike) {
                $nested->whereRaw("COALESCE((d.metadata->>'vehicle_plate'), (d.metadata->>'vehiclePlateSnapshot'), '') ILIKE ?", [$vehicleLike]);
            });
        }

        if ($workshopVehicleSearchEnabled && $customerVehicleId !== null && $customerVehicleId !== '') {
            $query->whereRaw("COALESCE((d.metadata->>'customer_vehicle_id'), (d.metadata->>'customerVehicleId'), '0')::BIGINT = ?", [(int) $customerVehicleId]);
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
            'defer_sunat_send' => 'nullable|boolean',
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

        $allProductIds = $sourceItems
            ->pluck('product_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $validProductMap = [];
        if (!empty($allProductIds)) {
            $validProductMap = DB::table('inventory.products')
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->whereIn('id', $allProductIds)
                ->pluck('id')
                ->mapWithKeys(fn ($id) => [(int) $id => true])
                ->all();
        }

        $sourceNumber = (string) $source->series . '-' . (string) $source->number;
        $resolvedPaymentMethodId = isset($payload['payment_method_id'])
            ? (int) $payload['payment_method_id']
            : ($source->payment_method_id !== null ? (int) $source->payment_method_id : null);

        if ($this->documentKindRequiresRucCustomer($targetDocumentKind)) {
            $sourceCustomerIdentity = $this->fetchCustomerIdentityForSalesValidation($companyId, (int) $source->customer_id);
            if (!$sourceCustomerIdentity || !$this->customerHasRucIdentity($sourceCustomerIdentity)) {
                return response()->json([
                    'message' => 'Para convertir a este tipo de documento el cliente debe tener RUC valido (11 digitos).',
                ], 422);
            }
        }

        $itemsPayload = $sourceItems->map(function ($item) use ($lotsByItem, $validProductMap) {
            $itemLots = $lotsByItem->get((int) $item->id, collect())->map(function ($lot) {
                return [
                    'lot_id' => (int) $lot->lot_id,
                    'qty' => (float) $lot->qty,
                ];
            })->values()->all();

            $productId = $item->product_id !== null ? (int) $item->product_id : null;
            if ($productId !== null && !isset($validProductMap[$productId])) {
                $productId = null;
            }

            return [
                'line_no' => (int) $item->line_no,
                'product_id' => $productId,
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
            'issue_at' => $this->resolveIssueAtForStorage($payload['issue_at'] ?? null),
            'due_at' => $payload['due_at'] ?? $source->due_at,
            'customer_id' => (int) $source->customer_id,
            'currency_id' => (int) $source->currency_id,
            'payment_method_id' => $resolvedPaymentMethodId,
            'exchange_rate' => $source->exchange_rate !== null ? (float) $source->exchange_rate : null,
            'notes' => $payload['notes'] ?? $source->notes,
            'metadata' => array_merge($sourceMetadata, [
                'source_document_id' => $sourceId,
                'source_document_kind' => (string) $source->document_kind,
                'source_document_number' => $sourceNumber,
                'conversion_origin' => 'SALES_MODULE',
                'stock_already_discounted' => $sourceHadStockImpact,
                'defer_sunat_send' => filter_var($payload['defer_sunat_send'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]),
            'status' => $targetStatus,
            'items' => $itemsPayload,
            'payments' => in_array($targetDocumentKind, ['INVOICE', 'RECEIPT'], true)
                && strtoupper($targetStatus) === 'ISSUED'
                && $resolvedPaymentMethodId !== null
                ? [[
                    'payment_method_id' => $resolvedPaymentMethodId,
                    'amount' => (float) $source->total,
                    'status' => 'PAID',
                    'paid_at' => now('America/Lima')->format('Y-m-d H:i:sP'),
                    'method' => 'REGISTERED',
                ]]
                : [],
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
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'd.payment_method_id')
            ->select([
                'd.id',
                'd.branch_id',
                'd.warehouse_id',
                'd.customer_id',
                'd.customer_vehicle_id',
                'd.currency_id',
                'd.payment_method_id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.due_at',
                'd.status',
                'd.subtotal',
                'd.tax_total',
                'd.total',
                'd.balance_due',
                'd.notes',
                'd.metadata',
                'd.vehicle_plate_snapshot',
                'd.vehicle_brand_snapshot',
                'd.vehicle_model_snapshot',
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
            $taxLabel = strtoupper(trim((string) (is_array($taxCat) ? ($taxCat['label'] ?? 'Sin IGV') : 'Sin IGV')));
            $taxCode = strtoupper(trim((string) (is_array($taxCat) ? ($taxCat['code'] ?? '') : '')));
            $taxRate = (float) (is_array($taxCat) ? ($taxCat['rate_percent'] ?? 0) : 0);
            $itemSubtotal = (float) ($item->subtotal ?? 0);
            $itemTaxTotal = (float) ($item->tax_total ?? 0);

            $isGravada = $itemTaxTotal > 0.00001
                || $taxRate > 0.00001
                || in_array($taxCode, ['10', '1000', 'IGV', 'VAT', 'GRAVADA'], true)
                || strpos($taxLabel, 'IGV') !== false
                || strpos($taxLabel, 'GRAV') !== false;

            $isExonerada = in_array($taxCode, ['20', '9997', 'EXONERADA'], true)
                || strpos($taxLabel, 'EXONER') !== false;

            $isInafecta = in_array($taxCode, ['30', '9998', 'INAFECTA'], true)
                || strpos($taxLabel, 'INAFECT') !== false;

            if ($isGravada) {
                $gravadaTotal += $itemSubtotal;
            } elseif ($isExonerada) {
                $exoneradaTotal += $itemSubtotal;
            } elseif ($isInafecta) {
                $inafectaTotal += $itemSubtotal;
            }

            $taxTotal += $itemTaxTotal;
        }

        if ($taxTotal <= 0.00001 && isset($doc->tax_total)) {
            $taxTotal = (float) ($doc->tax_total ?? 0);
        }

        if ($gravadaTotal <= 0.00001 && $taxTotal > 0.00001) {
            $docSubtotal = (float) ($doc->subtotal ?? 0);
            $gravadaTotal = max(0, $docSubtotal - $inafectaTotal - $exoneradaTotal);
        }

        $dueDate = null;
        if ($doc->due_at) {
            $dueText = trim((string) $doc->due_at);
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dueText, $matches) === 1) {
                $dueDate = $matches[1];
            } else {
                $dueDate = $dueText;
            }
        }

        return response()->json([
            'data' => [
                'id' => (int) $doc->id,
                'branchId' => $doc->branch_id !== null ? (int) $doc->branch_id : null,
                'warehouseId' => $doc->warehouse_id !== null ? (int) $doc->warehouse_id : null,
                'customerId' => (int) $doc->customer_id,
                'customerVehicleId' => $doc->customer_vehicle_id !== null ? (int) $doc->customer_vehicle_id : null,
                'currencyId' => (int) $doc->currency_id,
                'paymentMethodId' => $doc->payment_method_id !== null ? (int) $doc->payment_method_id : null,
                'documentKind' => (string) $doc->document_kind,
                'series' => (string) $doc->series,
                'number' => (int) $doc->number,
                'issueDate' => (string) ($doc->issue_at ?? ''),
                'dueDate' => $dueDate,
                'status' => (string) $doc->status,
                'currencyCode' => (string) ($doc->currency_code ?? 'PEN'),
                'currencySymbol' => (string) ($doc->currency_symbol ?? 'S/'),
                'paymentMethodName' => (string) ($doc->payment_method_name ?? '-'),
                'customerName' => (string) ($doc->customer_name ?? '-'),
                'customerDocNumber' => (string) ($doc->customer_doc_number ?? '-'),
                'customerAddress' => (string) ($doc->customer_address ?? '-'),
                'subtotal' => (float) (($doc->subtotal ?? 0) ?: ($gravadaTotal + $inafectaTotal + $exoneradaTotal)),
                'taxTotal' => (float) $taxTotal,
                'grandTotal' => (float) $doc->total,
                'metadata' => $docMetadata,
                'vehiclePlateSnapshot' => $doc->vehicle_plate_snapshot !== null ? (string) $doc->vehicle_plate_snapshot : null,
                'vehicleBrandSnapshot' => $doc->vehicle_brand_snapshot !== null ? (string) $doc->vehicle_brand_snapshot : null,
                'vehicleModelSnapshot' => $doc->vehicle_model_snapshot !== null ? (string) $doc->vehicle_model_snapshot : null,
                'gravadaTotal' => (float) $gravadaTotal,
                'inafectaTotal' => (float) $inafectaTotal,
                'exoneradaTotal' => (float) $exoneradaTotal,
                'company' => $this->resolveCompanyPrintProfile($companyId),
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
        $normalizedKind = $this->resolveNoteBaseKind($documentKind) ?? strtoupper($documentKind);
        $targetTable = $normalizedKind === 'DEBIT_NOTE'
            ? 'master.debit_note_reasons'
            : 'master.credit_note_reasons';

        if (!$this->tableExists($targetTable)) {
            return $this->defaultDocumentNoteReasons($normalizedKind);
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
                $query->whereIn($statusColumn, [1, 2]);
            } else {
                $query->where(function ($nested) use ($statusColumn) {
                    $nested->where($statusColumn, true)
                        ->orWhere($statusColumn, 1)
                        ->orWhere($statusColumn, '1');
                });
            }
        }

        $rows = $query->get()->map(function ($row) use ($idColumn, $codeColumn, $descriptionColumn) {
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

        if (count($rows) === 0) {
            return $this->defaultDocumentNoteReasons($normalizedKind);
        }

        return $rows;
    }

    private function resolveNoteBaseKind(string $documentKind): ?string
    {
        $normalized = strtoupper(trim($documentKind));
        if ($normalized === 'CREDIT_NOTE' || strpos($normalized, 'CREDIT_NOTE_') === 0) {
            return 'CREDIT_NOTE';
        }

        if ($normalized === 'DEBIT_NOTE' || strpos($normalized, 'DEBIT_NOTE_') === 0) {
            return 'DEBIT_NOTE';
        }

        return null;
    }

    private function defaultDocumentNoteReasons(string $documentKind): array
    {
        if ($documentKind === 'DEBIT_NOTE') {
            return [
                ['id' => 1, 'code' => '01', 'description' => 'Interés por mora'],
                ['id' => 2, 'code' => '02', 'description' => 'Aumento en el valor'],
                ['id' => 3, 'code' => '03', 'description' => 'Penalidades u otros conceptos'],
            ];
        }

        return [
            ['id' => 1, 'code' => '01', 'description' => 'Anulación de la operación'],
            ['id' => 2, 'code' => '02', 'description' => 'Anulación por error en el RUC'],
            ['id' => 3, 'code' => '03', 'description' => 'Corrección por error en la descripción'],
            ['id' => 4, 'code' => '04', 'description' => 'Descuento global'],
            ['id' => 5, 'code' => '05', 'description' => 'Descuento por ítem'],
            ['id' => 6, 'code' => '06', 'description' => 'Devolución total'],
            ['id' => 7, 'code' => '07', 'description' => 'Devolución por ítem'],
            ['id' => 8, 'code' => '08', 'description' => 'Bonificación'],
            ['id' => 9, 'code' => '09', 'description' => 'Disminución en el valor'],
            ['id' => 10, 'code' => '10', 'description' => 'Otros conceptos'],
        ];
    }

    private function getDetractionMinAmount(int $companyId, $branchId): float
    {
        // Read min_amount from toggle config JSON; fallback to SUNAT default 700 PEN
        $resolvedBranchId = $branchId !== null ? (int) $branchId : null;
        $row = $this->resolveFeatureToggleRow($companyId, $resolvedBranchId, 'SALES_DETRACCION_ENABLED');
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
        $resolvedBranchId = $branchId !== null ? (int) $branchId : null;
        $resolved = $this->resolveFeatureResolutionForContext($companyId, $resolvedBranchId, $featureCode, false);

        if (!$resolved['is_enabled'] && $resolved['config'] === null) {
            return null;
        }

        return (object) [
            'feature_code' => $featureCode,
            'is_enabled' => (bool) $resolved['is_enabled'],
            'config' => $resolved['config'],
            'vertical_source' => $resolved['vertical_source'],
        ];
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

    private function resolveFeatureResolutionForContext(int $companyId, ?int $branchId, string $featureCode, bool $defaultEnabled = false): array
    {
        $normalizedFeatureCode = strtoupper(trim($featureCode));
        $cacheKey = $companyId . ':' . ($branchId ?? 'null') . ':' . $normalizedFeatureCode . ':' . ($defaultEnabled ? '1' : '0');

        if (array_key_exists($cacheKey, $this->featureContextResolutionCache)) {
            return $this->featureContextResolutionCache[$cacheKey];
        }

        // Pre-warm toggle maps in 2 bulk queries (company + branch) instead of 1 query per feature
        $this->prewarmFeatureToggles($companyId, $branchId);

        $ck = (string) $companyId;
        $companyRow = $this->companyFeatureToggleMap[$ck . ':' . $normalizedFeatureCode] ?? null;

        $branchRow = null;
        if ($branchId !== null) {
            $bk = $ck . ':' . $branchId;
            $branchRow = $this->branchFeatureToggleMap[$bk . ':' . $normalizedFeatureCode] ?? null;
        }

        $branchEnabled = $branchRow && $branchRow->is_enabled !== null ? (bool) $branchRow->is_enabled : null;
        $companyEnabled = $companyRow && $companyRow->is_enabled !== null ? (bool) $companyRow->is_enabled : null;

        $isEnabled = $branchEnabled !== null
            ? $branchEnabled
            : ($companyEnabled !== null ? $companyEnabled : $defaultEnabled);

        $companyConfig = $companyRow ? $this->decodeFeatureConfig($companyRow->config) : [];
        $branchConfig = $branchRow ? $this->decodeFeatureConfig($branchRow->config) : [];
        $resolvedConfig = array_merge($companyConfig, $branchConfig);

        $verticalPreference = $this->resolveVerticalFeaturePreference($companyId, $normalizedFeatureCode);
        if ($verticalPreference['resolved']) {
            if ($verticalPreference['is_enabled'] !== null) {
                $isEnabled = (bool) $verticalPreference['is_enabled'];
            }
            if ($verticalPreference['config'] !== null) {
                $resolvedConfig = $this->decodeFeatureConfig($verticalPreference['config']);
            }
        }

        $result = [
            'is_enabled' => (bool) $isEnabled,
            'config' => !empty($resolvedConfig) ? $resolvedConfig : null,
            'company_enabled' => $companyEnabled,
            'branch_enabled' => $branchEnabled,
            'vertical_source' => $verticalPreference['source'],
        ];

        $this->featureContextResolutionCache[$cacheKey] = $result;
        return $result;
    }

    private function resolveVerticalFeaturePreference(int $companyId, string $featureCode): array
    {
        $normalizedFeatureCode = strtoupper(trim($featureCode));
        $cacheKey = $companyId . ':' . $normalizedFeatureCode;
        if (array_key_exists($cacheKey, $this->verticalFeaturePreferenceCache)) {
            return $this->verticalFeaturePreferenceCache[$cacheKey];
        }

        $default = [
            'resolved' => false,
            'is_enabled' => null,
            'config' => null,
            'source' => null,
        ];

        if (!$this->tableExists('appcfg.verticals')
            || !$this->tableExists('appcfg.company_verticals')
            || !$this->tableExists('appcfg.vertical_feature_templates')
            || !$this->tableExists('appcfg.company_vertical_feature_overrides')) {
            $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
            return $default;
        }

        $activeVertical = $this->resolveActiveCompanyVertical($companyId);
        if ($activeVertical === null) {
            $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
            return $default;
        }

        $override = DB::table('appcfg.company_vertical_feature_overrides')
            ->where('company_id', $companyId)
            ->where('vertical_id', (int) $activeVertical['id'])
            ->whereRaw('UPPER(feature_code) = ?', [$normalizedFeatureCode])
            ->first(['is_enabled', 'config']);

        if ($override && ($override->is_enabled !== null || $override->config !== null)) {
            $resolved = [
                'resolved' => true,
                'is_enabled' => $override->is_enabled !== null ? (bool) $override->is_enabled : null,
                'config' => $override->config,
                'source' => 'COMPANY_VERTICAL_OVERRIDE',
            ];
            $this->verticalFeaturePreferenceCache[$cacheKey] = $resolved;
            return $resolved;
        }

        $template = DB::table('appcfg.vertical_feature_templates')
            ->where('vertical_id', (int) $activeVertical['id'])
            ->whereRaw('UPPER(feature_code) = ?', [$normalizedFeatureCode])
            ->first(['is_enabled', 'config']);

        if ($template) {
            $resolved = [
                'resolved' => true,
                'is_enabled' => $template->is_enabled !== null ? (bool) $template->is_enabled : null,
                'config' => $template->config,
                'source' => 'VERTICAL_TEMPLATE',
            ];
            $this->verticalFeaturePreferenceCache[$cacheKey] = $resolved;
            return $resolved;
        }

        $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
        return $default;
    }

    private function resolveActiveCompanyVertical(int $companyId): ?array
    {
        if (array_key_exists($companyId, $this->activeVerticalCache)) {
            return $this->activeVerticalCache[$companyId];
        }

        if (!$this->tableExists('appcfg.verticals') || !$this->tableExists('appcfg.company_verticals')) {
            $this->activeVerticalCache[$companyId] = null;
            return null;
        }

        $row = DB::table('appcfg.company_verticals as cv')
            ->join('appcfg.verticals as v', 'v.id', '=', 'cv.vertical_id')
            ->where('cv.company_id', $companyId)
            ->where('cv.status', 1)
            ->where('v.status', 1)
            ->where('cv.is_primary', true)
            ->select('v.id', 'v.code', 'v.name')
            ->first();

        if (!$row) {
            $this->activeVerticalCache[$companyId] = null;
            return null;
        }

        $resolved = [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
        ];

        $this->activeVerticalCache[$companyId] = $resolved;
        return $resolved;
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
        if (array_key_exists($qualifiedTable, $this->tableExistsCache)) {
            return $this->tableExistsCache[$qualifiedTable];
        }

        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        $row = DB::selectOne(
            'select exists (select 1 from information_schema.tables where table_schema = ? and table_name = ?) as present',
            [$schema, $table]
        );

        $result = isset($row->present) && (bool) $row->present;
        $this->tableExistsCache[$qualifiedTable] = $result;
        return $result;
    }

    private function prewarmFeatureToggles(int $companyId, ?int $branchId): void
    {
        $ck = (string) $companyId;

        if (!isset($this->featureTogglePrewarmed[$ck])) {
            $rows = DB::table('appcfg.company_feature_toggles')
                ->where('company_id', $companyId)
                ->get(['feature_code', 'is_enabled', 'config']);

            foreach ($rows as $row) {
                $fk = $ck . ':' . strtoupper(trim($row->feature_code));
                $this->companyFeatureToggleMap[$fk] = $row;
            }
            $this->featureTogglePrewarmed[$ck] = true;
        }

        if ($branchId !== null) {
            $bk = $ck . ':' . $branchId;
            if (!isset($this->featureTogglePrewarmed[$bk])) {
                $rows = DB::table('appcfg.branch_feature_toggles')
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->get(['feature_code', 'is_enabled', 'config']);

                foreach ($rows as $row) {
                    $fk = $bk . ':' . strtoupper(trim($row->feature_code));
                    $this->branchFeatureToggleMap[$fk] = $row;
                }
                $this->featureTogglePrewarmed[$bk] = true;
            }
        }
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
        // Table is now guaranteed by migration 2026_04_18_000006. No-op.
    }

    private function ensureCustomerPriceProfilesTable(): void
    {
        // Table is now guaranteed by migration 2026_04_18_000006. No-op.
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
            return now('America/Lima')->format('Y-m-d H:i:sP');
        }

        $text = trim((string) $issueAt);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            $limaNow = now('America/Lima');
            return $text . ' ' . $limaNow->format('H:i:sP');
        }

        try {
            return Carbon::parse($text)->setTimezone('America/Lima')->format('Y-m-d H:i:sP');
        } catch (\Throwable $e) {
            return $issueAt;
        }
    }

    private function documentKindCatalog()
    {
        if ($this->tableExists('sales.document_kinds')) {
            $rows = DB::table('sales.document_kinds')
                ->select('id', 'code', 'label', 'is_enabled')
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get();

            if (!$rows->isEmpty()) {
                return $rows
                    ->map(function ($row) {
                        $meta = $this->documentKindMeta((string) $row->code, (string) $row->label);
                        return [
                            'id' => (int) $row->id,
                            'code' => (string) $row->code,
                            'label' => (string) $row->label,
                            'is_enabled' => (bool) $row->is_enabled,
                            'base_kind' => $meta['base_kind'],
                            'kind_group' => $meta['kind_group'],
                            'note_target_kind' => $meta['note_target_kind'],
                        ];
                    })
                    ->values();
            }
        }

        return collect([
            ['id' => 1, 'code' => 'QUOTATION', 'label' => 'Cotizacion', 'is_enabled' => true],
            ['id' => 2, 'code' => 'SALES_ORDER', 'label' => 'Pedido de Venta', 'is_enabled' => true],
            ['id' => 3, 'code' => 'INVOICE', 'label' => 'Factura', 'is_enabled' => true],
            ['id' => 4, 'code' => 'RECEIPT', 'label' => 'Boleta', 'is_enabled' => true],
            ['id' => 5, 'code' => 'CREDIT_NOTE', 'label' => 'Nota de Credito', 'is_enabled' => true],
            ['id' => 6, 'code' => 'DEBIT_NOTE', 'label' => 'Nota de Debito', 'is_enabled' => true],
        ])->map(function ($row) {
            $meta = $this->documentKindMeta((string) $row['code'], (string) $row['label']);
            return array_merge($row, $meta);
        })->values();
    }

    private function documentKindMeta(string $code, string $label = ''): array
    {
        $normalizedCode = strtoupper(trim($code));
        $normalizedLabel = strtoupper(trim($label));

        $baseKind = $normalizedCode;
        $kindGroup = 'TRIBUTARY';
        $noteTargetKind = null;

        if ($normalizedCode === 'QUOTATION' || $normalizedCode === 'SALES_ORDER') {
            $kindGroup = 'PRE_DOCUMENT';
        }

        if ($normalizedCode === 'CREDIT_NOTE' || strpos($normalizedCode, 'CREDIT_NOTE_') === 0) {
            $baseKind = 'CREDIT_NOTE';
            $kindGroup = 'NOTE_CREDIT';
        }

        if ($normalizedCode === 'DEBIT_NOTE' || strpos($normalizedCode, 'DEBIT_NOTE_') === 0) {
            $baseKind = 'DEBIT_NOTE';
            $kindGroup = 'NOTE_DEBIT';
        }

        if ($kindGroup === 'NOTE_CREDIT' || $kindGroup === 'NOTE_DEBIT') {
            $targetHint = $normalizedCode . ' ' . $normalizedLabel;
            if (strpos($targetHint, 'RECEIPT') !== false || strpos($targetHint, 'BOLETA') !== false) {
                $noteTargetKind = 'RECEIPT';
            } elseif (strpos($targetHint, 'INVOICE') !== false || strpos($targetHint, 'FACTURA') !== false) {
                $noteTargetKind = 'INVOICE';
            }
        }

        return [
            'base_kind' => $baseKind,
            'kind_group' => $kindGroup,
            'note_target_kind' => $noteTargetKind,
        ];
    }

    private function documentKindCodes(): array
    {
        return $this->documentKindCatalog()
            ->map(function ($row) {
                return (string) ($row['code'] ?? '');
            })
            ->filter(function ($code) {
                return $code !== '';
            })
            ->values()
            ->all();
    }

    private function fetchCustomerIdentityForSalesValidation(int $companyId, int $customerId)
    {
        return DB::table('sales.customers as c')
            ->leftJoin('sales.customer_types as ct', 'ct.id', '=', 'c.customer_type_id')
            ->select([
                'c.id',
                'c.doc_type',
                'c.doc_number',
                'ct.sunat_code as customer_type_sunat_code',
            ])
            ->where('c.company_id', $companyId)
            ->where('c.id', $customerId)
            ->first();
    }

    private function documentKindRequiresRucCustomer(string $documentKind): bool
    {
        return in_array(strtoupper(trim($documentKind)), ['INVOICE', 'CREDIT_NOTE', 'DEBIT_NOTE'], true);
    }

    private function customerHasRucIdentity($customer): bool
    {
        if (!$customer) {
            return false;
        }

        $docType = strtoupper(trim((string) ($customer->doc_type ?? '')));
        $docDigits = preg_replace('/\D+/', '', (string) ($customer->doc_number ?? ''));
        $sunatCode = isset($customer->customer_type_sunat_code) ? (int) $customer->customer_type_sunat_code : null;

        $hasRucDocType = in_array($docType, ['6', '06', 'RUC'], true);
        $hasRucCustomerType = $sunatCode === 6;
        $hasValidRucNumber = is_string($docDigits) && strlen($docDigits) === 11;

        return $hasValidRucNumber && ($hasRucDocType || $hasRucCustomerType);
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

    private function findDocumentKindCatalogRowById(int $id): ?array
    {
        return $this->documentKindCatalog()
            ->first(function ($row) use ($id) {
                return (int) ($row['id'] ?? 0) === $id;
            });
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

    private function isWorkshopMultiVehicleEnabledForRequest(Request $request, int $companyId): bool
    {
        $authUser = $request->attributes->get('auth_user');
        $branchIdRaw = $request->query('branch_id', $request->input('branch_id', $authUser->branch_id ?? null));
        $branchId = ($branchIdRaw !== null && $branchIdRaw !== '') ? (int) $branchIdRaw : null;

        return $this->isWorkshopMultiVehicleEnabledForContext($companyId, $branchId);
    }

    private function isWorkshopMultiVehicleEnabledForContext(int $companyId, ?int $branchId): bool
    {
        return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_WORKSHOP_MULTI_VEHICLE', false);
    }

    private function normalizeVehiclePlate(string $plate): string
    {
        $value = strtoupper(trim($plate));

        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }

    private function isCommerceFeatureEnabled(int $companyId, string $featureCode): bool
    {
        return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, null, $featureCode, false);
    }

    private function isCommerceFeatureEnabledForContext(int $companyId, ?int $branchId, string $featureCode): bool
    {
        return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, $featureCode, false);
    }

    private function isCommerceFeatureEnabledForContextWithDefault(int $companyId, ?int $branchId, string $featureCode, bool $defaultEnabled): bool
    {
        $resolved = $this->resolveFeatureResolutionForContext($companyId, $branchId, $featureCode, $defaultEnabled);

        return (bool) $resolved['is_enabled'];
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

    private function isTechnicalActor(string $roleProfile, string $roleCode): bool
    {
        if ($this->isAdminActor($roleCode)) {
            return true;
        }

        if ($roleProfile === 'TECHNICAL' || $roleProfile === 'SYSTEM') {
            return true;
        }

        if ($roleCode === '') {
            return false;
        }

        return strpos($roleCode, 'SOPORTE') !== false
            || strpos($roleCode, 'TECH') !== false
            || strpos($roleCode, 'TECNIC') !== false
            || strpos($roleCode, 'SISTEM') !== false
            || strpos($roleCode, 'DEV') !== false;
    }

    private function canActorViewTaxBridgeDebug(int $companyId, ?int $branchId, string $roleProfile, string $roleCode): bool
    {
        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_TAX_BRIDGE_DEBUG_VIEW');
        if (!$featureRow || !(bool) ($featureRow->is_enabled ?? false)) {
            return false;
        }

        $allowedRoleCodes = $this->resolveFeatureAllowedRoleCodes($featureRow->config ?? null);
        if (!empty($allowedRoleCodes)) {
            return in_array($roleCode, $allowedRoleCodes, true)
                || in_array($roleProfile, $allowedRoleCodes, true);
        }

        return $this->isTechnicalActor($roleProfile, $roleCode);
    }

    private function resolveFeatureAllowedRoleCodes($rawConfig): array
    {
        $config = $this->decodeFeatureConfig($rawConfig);
        $rawRoles = $config['allowed_role_codes'] ?? null;

        if (is_string($rawRoles)) {
            $rawRoles = preg_split('/[;,\r\n]+/', $rawRoles) ?: [];
        }

        if (!is_array($rawRoles)) {
            return [];
        }

        $normalized = [];
        foreach ($rawRoles as $value) {
            $roleCode = strtoupper(trim((string) $value));
            if ($roleCode === '') {
                continue;
            }

            $normalized[$roleCode] = true;
        }

        return array_keys($normalized);
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
    public function taxBridgeDebug(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        $document = DB::table('sales.commercial_documents')
            ->select('id', 'company_id', 'branch_id')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$document) {
            return response()->json([
                'message' => 'Documento no encontrado',
            ], 404);
        }

        $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));

        if ($roleCode === '' && $roleProfile === '') {
            $roleContext = $this->resolveAuthRoleContext((int) $authUser->id, $companyId);
            $roleCode = strtoupper(trim((string) ($roleContext['role_code'] ?? '')));
            $roleProfile = strtoupper(trim((string) ($roleContext['role_profile'] ?? '')));
        }

        $branchId = $document->branch_id !== null ? (int) $document->branch_id : null;
        if (!$this->canActorViewTaxBridgeDebug($companyId, $branchId, $roleProfile, $roleCode)) {
            return response()->json([
                'message' => 'No autorizado para ver el detalle tecnico del puente SUNAT',
            ], 403);
        }

        return response()->json([
            'message' => 'Tax bridge debug loaded',
            'document_id' => (int) $document->id,
            'debug' => $this->taxBridgeService->getLastDispatchDebug($companyId, (int) $document->id),
        ]);
    }

    public function retryTaxBridgeSend(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        try {
            $result = $this->taxBridgeService->retry($companyId, $id);
            $diagnostic = $this->taxBridgeService->summarizeBridgeDiagnostic($result['response'] ?? null);

            return response()->json([
                'message' => 'Tax bridge retry sent successfully',
                'document_id' => $id,
                'sunat_status' => $result['status'],
                'sunat_status_label' => $result['label'],
                'bridge_http_code' => $result['bridge_http_code'] ?? null,
                'bridge_response' => $result['response'] ?? null,
                'sunat_error_code' => $diagnostic['code'] ?? null,
                'sunat_error_message' => $diagnostic['message'] ?? null,
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
            $diagnostic = $this->taxBridgeService->summarizeBridgeDiagnostic($result['response'] ?? null);

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
                'sunat_error_code' => $diagnostic['code'] ?? null,
                'sunat_error_message' => $diagnostic['message'] ?? null,
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

