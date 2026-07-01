<?php

namespace App\Services\Sales;

use App\Application\Factories\Sales\CreateCommercialDocumentCommandFactory;
use App\Application\UseCases\Sales\CreateCommercialDocumentUseCase;
use App\Application\UseCases\Sales\PrepareConvertCommercialDocumentUseCase;
use App\Application\UseCases\Sales\PrepareCreateCommercialDocumentUseCase;
use App\Application\UseCases\Sales\ResolveCompanyPrintProfileUseCase;
use App\Application\UseCases\Sales\PrepareUpdateCommercialDocumentUseCase;
use App\Application\UseCases\Sales\PrepareVoidCommercialDocumentUseCase;
use App\Application\UseCases\Sales\UpdateCommercialDocumentDraftUseCase;
use App\Application\UseCases\Sales\VoidCommercialDocumentUseCase;
use App\Contracts\Sales\SalesDocumentApplicationServiceInterface;
use App\Contracts\TaxBridgeGateway;
use App\Infrastructure\Repositories\Sales\Documents\SalesDocumentSupportService;
use App\Services\AppConfig\CompanyIgvRateService;
use App\Services\Sales\CustomerVehicleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use App\Services\Sales\Documents\SalesDocumentConversionService;
use App\Services\Sales\Documents\SalesDocumentException;
use App\Services\Sales\Documents\SalesDocumentReadService;
use Illuminate\Support\Collection;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;

class SalesDocumentApplicationService implements SalesDocumentApplicationServiceInterface
{
    private const SALES_PRINT_TEMPLATE_SIGNATURE = 'tpl3';

    public function __construct(
        private SalesDocumentReadService $salesDocumentReadService,
        private SalesLookupService $salesLookupService,
        private ResolveCompanyPrintProfileUseCase $resolveCompanyPrintProfileUseCase,
        private SalesDocumentSupportService $supportService,
        private CreateCommercialDocumentCommandFactory $createCommercialDocumentCommandFactory,
        private PrepareCreateCommercialDocumentUseCase $prepareCreateCommercialDocumentUseCase,
        private CreateCommercialDocumentUseCase $createCommercialDocumentUseCase,
        private PrepareConvertCommercialDocumentUseCase $prepareConvertCommercialDocumentUseCase,
        private SalesDocumentConversionService $salesDocumentConversionService,
        private PrepareUpdateCommercialDocumentUseCase $prepareUpdateCommercialDocumentUseCase,
        private UpdateCommercialDocumentDraftUseCase $updateCommercialDocumentDraftUseCase,
        private PrepareVoidCommercialDocumentUseCase $prepareVoidCommercialDocumentUseCase,
        private VoidCommercialDocumentUseCase $voidCommercialDocumentUseCase,
        private TaxBridgeGateway $taxBridgeService,
        private CompanyIgvRateService $companyIgvRateService,
        private CustomerVehicleService $customerVehicleService
    ) {
    }

    // -------------------------------------------------------------------------
    // READS
    // -------------------------------------------------------------------------

    public function paginateCommercialDocuments(
        object $authUser,
        int $companyId,
        array $queryParams,
        int $page,
        int $limit
    ): array {
        $branchIdRaw = $queryParams['branch_id'] ?? $authUser->branch_id ?? null;
        $resolvedBranchId = ($branchIdRaw !== null && $branchIdRaw !== '') ? (int) $branchIdRaw : null;

        $roleCode    = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));

        $isSellerUser  = $this->supportService->isSellerActor($roleProfile, $roleCode);
        $isAdminUser   = !$isSellerUser && $this->supportService->isAdminActor($roleCode);
        $isCashierUser = $this->supportService->isCashierActor($roleProfile, $roleCode);

        $conversionStateParam   = strtoupper(trim((string) ($queryParams['conversion_state'] ?? '')));
        $cashierBranchScope     = $isCashierUser && $conversionStateParam === 'PENDING';
        $canViewAllPendingQueue = !$isSellerUser && $cashierBranchScope;

        $sellerToCashierEnabled = $this->supportService->isCommerceFeatureEnabledForContextWithDefault(
            $companyId, $resolvedBranchId, 'SALES_SELLER_TO_CASHIER', false
        );

        $workshopVehicleSearchEnabled = $this->supportService->isCommerceFeatureEnabledForContextWithDefault(
            $companyId, $resolvedBranchId, 'SALES_WORKSHOP_MULTI_VEHICLE', false
        ) && $this->salesLookupService->tableExists('sales.customer_vehicles');

        $filters = [
            'branch_id'                       => $branchIdRaw,
            'warehouse_id'                    => $queryParams['warehouse_id'] ?? null,
            'cash_register_id'                => $queryParams['cash_register_id'] ?? null,
            'source_origin'                   => $queryParams['source_origin'] ?? null,
            'document_kind'                   => $queryParams['document_kind'] ?? null,
            'document_kind_id'                => $queryParams['document_kind_id'] ?? null,
            'status'                          => $queryParams['status'] ?? null,
            'sunat_status'                    => $queryParams['sunat_status'] ?? null,
            'conversion_state'                => $queryParams['conversion_state'] ?? null,
            'customer'                        => trim((string) ($queryParams['customer'] ?? '')),
            'customer_id'                     => $queryParams['customer_id'] ?? null,
            'vehicle'                         => trim((string) ($queryParams['vehicle'] ?? '')),
            'customer_vehicle_id'             => $queryParams['customer_vehicle_id'] ?? null,
            'issue_date_from'                 => $queryParams['issue_date_from'] ?? null,
            'issue_date_to'                   => $queryParams['issue_date_to'] ?? null,
            'series'                          => trim((string) ($queryParams['series'] ?? '')),
            'number'                          => trim((string) ($queryParams['number'] ?? '')),
            'seller_user_id'                  => ((!$isSellerUser && $isAdminUser) || ($sellerToCashierEnabled && $canViewAllPendingQueue))
                                                    ? null
                                                    : (int) $authUser->id,
            'workshop_vehicle_search_enabled' => $workshopVehicleSearchEnabled,
        ];

        return $this->salesLookupService->paginateCommercialDocuments($companyId, $filters, $page, $limit);
    }

    public function generateCommercialDocumentShareLink(
        int $companyId,
        int $documentId,
        string $format,
        bool $isHttpsContext
    ): array {
        $format  = in_array($format, ['a4', 'ticket'], true) ? $format : 'a4';
        $ttlDays = max(1, (int) env('COMMERCIAL_DOCUMENT_SHARE_LINK_TTL_DAYS', 7));

        $expiresAt = now()->addDays($ttlDays);
        $url = (string) URL::temporarySignedRoute(
            'sales.commercial-documents.public-pdf',
            $expiresAt,
            ['id' => $documentId, 'company_id' => $companyId, 'format' => $format]
        );

        if (stripos($url, 'http://') === 0 && !app()->environment('local', 'development', 'testing')) {
            $appUrlHost    = strtolower((string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST));
            $isRailwayHost = str_contains($appUrlHost, '.up.railway.app');
            $isCloudHost   = str_contains($appUrlHost, 'fycticonsulting.com');

            if ($isHttpsContext || $isRailwayHost || $isCloudHost) {
                $url = 'https://' . ltrim(substr($url, strlen('http://')), '/');
            }
        }

        return [
            'url'       => $url,
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
    }

    public function getTaxBridgeDebug(object $authUser, int $companyId, int $documentId): array
    {
        $document = $this->salesLookupService->findTaxBridgeDocumentForDebug($companyId, $documentId);

        if (!$document) {
            throw new SalesDocumentException('Documento no encontrado', 404);
        }

        $roleCode    = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));

        if ($roleCode === '' && $roleProfile === '') {
            $roleContext = $this->supportService->resolveAuthRoleContext((int) $authUser->id, $companyId);
            $roleCode    = strtoupper(trim((string) ($roleContext['role_code'] ?? '')));
            $roleProfile = strtoupper(trim((string) ($roleContext['role_profile'] ?? '')));
        }

        $branchId = $document->branch_id !== null ? (int) $document->branch_id : null;

        if (!$this->canActorViewTaxBridgeDebug($companyId, $branchId, $roleProfile, $roleCode)) {
            throw new SalesDocumentException('No autorizado para ver el detalle tecnico del puente SUNAT', 403);
        }

        return [
            'document_id' => (int) $document->id,
            'debug'       => $this->taxBridgeService->getLastDispatchDebug($companyId, (int) $document->id),
        ];
    }

    private function canActorViewTaxBridgeDebug(
        int $companyId,
        ?int $branchId,
        string $roleProfile,
        string $roleCode
    ): bool {
        if (!$this->supportService->isCommerceFeatureEnabledForContextWithDefault(
            $companyId, $branchId, 'SALES_TAX_BRIDGE_DEBUG_VIEW', false
        )) {
            return false;
        }

        if ($this->supportService->isAdminActor($roleCode)) {
            return true;
        }

        if (in_array($roleProfile, ['TECHNICAL', 'SYSTEM'], true)) {
            return true;
        }

        foreach (['SOPORTE', 'TECH', 'TECNIC', 'SISTEM', 'DEV'] as $marker) {
            if (str_contains($roleCode, $marker)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // WRITES
    // -------------------------------------------------------------------------

    public function buildCommercialDocumentDetail(int $companyId, int $documentId): array
    {
        $doc = $this->salesDocumentReadService->findDocumentForShow($companyId, $documentId);

        if (!$doc) {
            throw new SalesDocumentException('Documento no encontrado', 404);
        }

        $items     = $this->salesDocumentReadService->resolveDocumentItemsWithFallback($companyId, $documentId);
        $itemIds   = $items->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $lotsByItem = $this->salesDocumentReadService->getLotsGroupedByItemIds($itemIds);

        $allTaxCategories = collect($this->companyIgvRateService->applyActiveRateToTaxCategories(
            $companyId,
            $this->salesLookupService->resolveTaxCategoriesRows($companyId)
        ));

        $docMetadata = [];
        if ($doc->metadata !== null && $doc->metadata !== '') {
            $decoded = json_decode((string) $doc->metadata, true);
            if (is_array($decoded)) {
                $docMetadata = $decoded;
            }
        }

        // Tax totals breakdown
        $gravadaTotal  = 0.0;
        $inafectaTotal = 0.0;
        $exoneradaTotal = 0.0;
        $taxTotal      = 0.0;

        foreach ($items as $item) {
            $taxCat   = $item->tax_category_id ? $allTaxCategories->firstWhere('id', $item->tax_category_id) : null;
            $taxLabel = strtoupper(trim((string) (is_array($taxCat) ? ($taxCat['label'] ?? 'Sin IGV') : 'Sin IGV')));
            $taxCode  = strtoupper(trim((string) (is_array($taxCat) ? ($taxCat['code'] ?? '') : '')));
            $taxRate  = (float) (is_array($taxCat) ? ($taxCat['rate_percent'] ?? 0) : 0);

            $itemSubtotal = (float) ($item->subtotal ?? 0);
            $itemTaxTotal = (float) ($item->tax_total ?? 0);

            $isGravada   = $itemTaxTotal > 0.00001 || $taxRate > 0.00001
                || in_array($taxCode, ['10', '1000', 'IGV', 'VAT', 'GRAVADA'], true)
                || str_contains($taxLabel, 'IGV') || str_contains($taxLabel, 'GRAV');
            $isExonerada = in_array($taxCode, ['20', '9997', 'EXONERADA'], true) || str_contains($taxLabel, 'EXONER');
            $isInafecta  = in_array($taxCode, ['30', '9998', 'INAFECTA'], true) || str_contains($taxLabel, 'INAFECT');

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
            $gravadaTotal = max(0, (float) ($doc->subtotal ?? 0) - $inafectaTotal - $exoneradaTotal);
        }

        // Mapped items
        $mappedItems = $items->map(function ($item) use ($allTaxCategories, $lotsByItem) {
            $taxCat  = $item->tax_category_id ? $allTaxCategories->firstWhere('id', $item->tax_category_id) : null;
            $taxLabel = is_array($taxCat) ? (string) ($taxCat['label'] ?? 'Sin IGV') : 'Sin IGV';
            $taxRate  = is_array($taxCat) ? (float) ($taxCat['rate_percent'] ?? 0) : 0.0;
            $itemLots = $lotsByItem->get((int) $item->id, collect())->map(fn ($lot) => [
                'lot_id' => (int) $lot->lot_id,
                'qty'    => (float) $lot->qty,
            ])->values();

            $itemMetadata = null;
            if ($item->metadata !== null && $item->metadata !== '') {
                $d = json_decode((string) $item->metadata, true);
                if (is_array($d)) {
                    $itemMetadata = $d;
                }
            }

            $productCode = trim((string) ($item->product_code ?? ''));
            if ($productCode === '' && is_array($itemMetadata)) {
                $productCode = trim((string) ($itemMetadata['product_code'] ?? $itemMetadata['productCode'] ?? $itemMetadata['code'] ?? ''));
            }
            if ($productCode === '' && $item->product_id !== null) {
                $productCode = 'ID-' . (int) $item->product_id;
            }

            return [
                'lineNo'                 => (int) $item->line_no,
                'productId'              => $item->product_id !== null ? (int) $item->product_id : null,
                'productCode'            => $productCode !== '' ? $productCode : null,
                'unitId'                 => $item->unit_id !== null ? (int) $item->unit_id : null,
                'priceTierId'            => $item->price_tier_id !== null ? (int) $item->price_tier_id : null,
                'qty'                    => (float) $item->qty,
                'qtyBase'                => (float) ($item->qty_base ?? 0),
                'conversionFactor'       => (float) ($item->conversion_factor ?? 1),
                'baseUnitPrice'          => (float) ($item->base_unit_price ?? 0),
                'unitLabel'              => (string) ($item->unit_code ?? ''),
                'description'            => (string) $item->description,
                'unitPrice'              => (float) $item->unit_price,
                'unitCost'               => (float) ($item->unit_cost ?? 0),
                'wholesaleDiscountPercent' => (float) ($item->wholesale_discount_percent ?? 0),
                'priceSource'            => $item->price_source ?: 'MANUAL',
                'discountTotal'          => (float) ($item->discount_total ?? 0),
                'lineTotal'              => (float) $item->total,
                'taxCategoryId'          => $item->tax_category_id,
                'taxLabel'               => $taxLabel,
                'taxRate'                => $taxRate,
                'taxAmount'              => (float) $item->tax_total,
                'metadata'               => $itemMetadata,
                'lots'                   => $itemLots,
            ];
        })->values();

        // Due date
        $dueDate = null;
        if ($doc->due_at) {
            $dueText = trim((string) $doc->due_at);
            $dueDate = preg_match('/^(\d{4}-\d{2}-\d{2})/', $dueText, $m) === 1 ? $m[1] : $dueText;
        }

        // Vehicle snapshot with fallback
        $branchId            = $doc->branch_id !== null ? (int) $doc->branch_id : null;
        $vehiclePlateSnapshot = trim((string) ($doc->vehicle_plate_snapshot ?? $docMetadata['vehicle_plate'] ?? $docMetadata['vehiclePlateSnapshot'] ?? ''));
        $vehicleBrandSnapshot = trim((string) ($doc->vehicle_brand_snapshot ?? $docMetadata['vehicle_brand'] ?? $docMetadata['vehicleBrand'] ?? ''));
        $vehicleModelSnapshot = trim((string) ($doc->vehicle_model_snapshot ?? $docMetadata['vehicle_model'] ?? $docMetadata['vehicleModel'] ?? ''));

        $customerVehicleId = $doc->customer_vehicle_id !== null ? (int) $doc->customer_vehicle_id : 0;
        if ($customerVehicleId <= 0) {
            $metaVehicleId = $docMetadata['customer_vehicle_id'] ?? $docMetadata['customerVehicleId'] ?? null;
            if (is_numeric($metaVehicleId)) {
                $customerVehicleId = (int) $metaVehicleId;
            }
        }

        $workshopEnabled = $this->supportService->isCommerceFeatureEnabledForContextWithDefault(
            $companyId, $branchId, 'SALES_WORKSHOP_MULTI_VEHICLE', false
        ) && $this->salesLookupService->tableExists('sales.customer_vehicles');

        if ($workshopEnabled && $customerVehicleId > 0) {
            if ($vehiclePlateSnapshot === '' || $vehicleBrandSnapshot === '' || $vehicleModelSnapshot === '') {
                $vehicle = $this->customerVehicleService->findVehicleSnapshotById(
                    $companyId, (int) $doc->customer_id, $customerVehicleId
                );
                if ($vehicle) {
                    if ($vehiclePlateSnapshot === '') {
                        $vehiclePlateSnapshot = strtoupper(trim((string) ($vehicle->plate ?? '')));
                    }
                    if ($vehicleBrandSnapshot === '') {
                        $vehicleBrandSnapshot = trim((string) ($vehicle->brand ?? ''));
                    }
                    if ($vehicleModelSnapshot === '') {
                        $vehicleModelSnapshot = trim((string) ($vehicle->model ?? ''));
                    }
                }
            }
        }

        // Payments (direct query — stays here as infrastructure read)
        $payments = \Illuminate\Support\Facades\DB::table('sales.commercial_document_payments as p')
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'p.payment_method_id')
            ->where('p.document_id', (int) $doc->id)
            ->orderBy('p.id')
            ->get(['p.payment_method_id', 'p.amount', 'p.status', 'p.paid_at', 'p.due_at', 'p.notes', 'pm.name as payment_method_name'])
            ->map(fn ($row) => [
                'payment_method_id'   => $row->payment_method_id !== null ? (int) $row->payment_method_id : null,
                'payment_method_name' => $row->payment_method_name !== null ? trim((string) $row->payment_method_name) : null,
                'amount'              => round((float) ($row->amount ?? 0), 2),
                'status'              => strtoupper(trim((string) ($row->status ?? 'PENDING'))),
                'paid_at'             => $row->paid_at !== null ? (string) $row->paid_at : null,
                'due_at'              => $row->due_at !== null ? (string) $row->due_at : null,
                'notes'               => $row->notes !== null ? trim((string) $row->notes) : null,
            ])->values()->all();

        return [
            'id'                   => (int) $doc->id,
            'branchId'             => $doc->branch_id !== null ? (int) $doc->branch_id : null,
            'warehouseId'          => $doc->warehouse_id !== null ? (int) $doc->warehouse_id : null,
            'customerId'           => (int) $doc->customer_id,
            'customerVehicleId'    => $customerVehicleId > 0 ? $customerVehicleId : null,
            'currencyId'           => (int) $doc->currency_id,
            'paymentMethodId'      => $doc->payment_method_id !== null ? (int) $doc->payment_method_id : null,
            'documentKind'         => (string) $doc->document_kind,
            'series'               => (string) $doc->series,
            'number'               => (int) $doc->number,
            'issueDate'            => (string) ($doc->issue_at ?? ''),
            'dueDate'              => $dueDate,
            'status'               => (string) $doc->status,
            'currencyCode'         => (string) ($doc->currency_code ?? 'PEN'),
            'currencySymbol'       => (string) ($doc->currency_symbol ?? 'S/'),
            'paymentMethodName'    => (string) ($doc->payment_method_name ?? '-'),
            'customerName'         => (string) ($doc->customer_name ?? '-'),
            'customerDocNumber'    => (string) ($doc->customer_doc_number ?? '-'),
            'customerAddress'      => (string) ($doc->customer_address ?? '-'),
            'customerPhone'        => (string) ($doc->customer_phone ?? ($docMetadata['customer_phone'] ?? $docMetadata['customerPhone'] ?? '')),
            'customerEmail'        => (string) ($doc->customer_email ?? ''),
            'notes'                => isset($doc->notes) && trim((string) $doc->notes) !== '' ? (string) $doc->notes : null,
            'subtotal'             => (float) (($doc->subtotal ?? 0) ?: ($gravadaTotal + $inafectaTotal + $exoneradaTotal)),
            'taxTotal'             => (float) $taxTotal,
            'grandTotal'           => (float) $doc->total,
            'metadata'             => $docMetadata,
            'payments'             => $payments,
            'vehiclePlateSnapshot' => $vehiclePlateSnapshot !== '' ? $vehiclePlateSnapshot : null,
            'vehicleBrandSnapshot' => $vehicleBrandSnapshot !== '' ? $vehicleBrandSnapshot : null,
            'vehicleModelSnapshot' => $vehicleModelSnapshot !== '' ? $vehicleModelSnapshot : null,
            'gravadaTotal'         => (float) $gravadaTotal,
            'inafectaTotal'        => (float) $inafectaTotal,
            'exoneradaTotal'       => (float) $exoneradaTotal,
            'company'              => $this->resolveCompanyPrintProfileUseCase->execute($companyId),
            'items'                => $mappedItems,
        ];
    }

    public function buildCommercialDocumentPdfBinary(
        int $companyId,
        int $documentId,
        string $format,
        bool $isPublicPdfLink
    ): array {
        $normalizedFormat = in_array($format, ['ticket', 'a4'], true) ? $format : 'a4';

        if (!$this->shouldUseSalesPrintCache()) {
            return $this->buildCommercialDocumentPdfBinaryFresh($companyId, $documentId, $normalizedFormat, $isPublicPdfLink);
        }

        $cacheKey = $this->buildSalesPrintPdfCacheKey($companyId, $documentId, $normalizedFormat, $isPublicPdfLink);

        return Cache::remember($cacheKey, $this->salesPrintCacheTtlSeconds(), function () use ($companyId, $documentId, $normalizedFormat, $isPublicPdfLink) {
            return $this->buildCommercialDocumentPdfBinaryFresh($companyId, $documentId, $normalizedFormat, $isPublicPdfLink);
        });
    }

    private function buildCommercialDocumentPdfBinaryFresh(
        int $companyId,
        int $documentId,
        string $format,
        bool $isPublicPdfLink
    ): array {
        $format  = in_array($format, ['ticket', 'a4'], true) ? $format : 'a4';
        $html    = $this->buildPrintableCommercialDocumentHtml($companyId, $documentId, $format);

        $options = new Options();
        $options->set('isRemoteEnabled', $isPublicPdfLink);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', false);
        $options->set('defaultMediaType', 'print');
        $options->set('dpi', 96);

        $renderPdf = function (string $htmlContent) use ($options, $format): string {
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($htmlContent, 'UTF-8');
            if ($format === 'ticket') {
                $dompdf->setPaper([0, 0, 226.77, 1800], 'portrait');
            } else {
                $dompdf->setPaper('A4', 'portrait');
            }
            $dompdf->render();
            return $dompdf->output();
        };

        try {
            $pdfBinary = $renderPdf($html);
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());
            $isGdFailure = str_contains($message, 'gd extension is required')
                || str_contains($message, 'addpngfromfile')
                || str_contains($message, 'png');

            if (!$isGdFailure) {
                throw $e;
            }

            Log::warning('PDF render fallback without logo due to GD/image failure', [
                'company_id'  => $companyId,
                'document_id' => $documentId,
                'message'     => $e->getMessage(),
            ]);

            // Retry without logo
            $fallbackHtml = preg_replace('/<img[^>]+logo[^>]*>/i', '', $html) ?? $html;
            $pdfBinary    = $renderPdf($fallbackHtml);
        }

        // Resolve filename from document data (best-effort without re-querying)
        $doc      = $this->salesDocumentReadService->findDocumentForShow($companyId, $documentId);
        $series   = $doc ? preg_replace('/[^A-Za-z0-9\-_]/', '', trim((string) ($doc->series ?? 'DOC'))) : 'DOC';
        $number   = $doc ? preg_replace('/[^0-9]/', '', trim((string) ($doc->number ?? '0'))) : '0';
        $fileName = ($series !== '' ? $series : 'DOC') . '-' . ($number !== '' ? $number : '0') . '.pdf';

        return [
            'binary'   => $pdfBinary,
            'filename' => $fileName,
        ];
    }

    public function exportCommercialDocumentsData(
        object $authUser,
        int $companyId,
        array $queryParams,
        string $detailMode,
        int $max
    ): array {
        $branchIdRaw      = $queryParams['branch_id'] ?? $authUser->branch_id ?? null;
        $resolvedBranchId = ($branchIdRaw !== null && $branchIdRaw !== '') ? (int) $branchIdRaw : null;

        $roleCode    = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));
        $isSellerUser = $this->supportService->isSellerActor($roleProfile, $roleCode);
        $isAdminUser  = !$isSellerUser && $this->supportService->isAdminActor($roleCode);

        $workshopEnabled = $this->supportService->isCommerceFeatureEnabledForContextWithDefault(
            $companyId, $resolvedBranchId, 'SALES_WORKSHOP_MULTI_VEHICLE', false
        ) && $this->salesLookupService->tableExists('sales.customer_vehicles');

        $filters = [
            'branch_id'                       => $branchIdRaw,
            'warehouse_id'                    => $queryParams['warehouse_id'] ?? null,
            'cash_register_id'                => $queryParams['cash_register_id'] ?? null,
            'source_origin'                   => $queryParams['source_origin'] ?? null,
            'document_kind'                   => $queryParams['document_kind'] ?? null,
            'document_kind_id'                => $queryParams['document_kind_id'] ?? null,
            'status'                          => $queryParams['status'] ?? null,
            'sunat_status'                    => $queryParams['sunat_status'] ?? null,
            'conversion_state'                => $queryParams['conversion_state'] ?? null,
            'customer'                        => trim((string) ($queryParams['customer'] ?? '')),
            'customer_id'                     => $queryParams['customer_id'] ?? null,
            'vehicle'                         => trim((string) ($queryParams['vehicle'] ?? '')),
            'customer_vehicle_id'             => $queryParams['customer_vehicle_id'] ?? null,
            'issue_date_from'                 => $queryParams['issue_date_from'] ?? null,
            'issue_date_to'                   => $queryParams['issue_date_to'] ?? null,
            'series'                          => trim((string) ($queryParams['series'] ?? '')),
            'number'                          => trim((string) ($queryParams['number'] ?? '')),
            'seller_user_id'                  => (!$isSellerUser && $isAdminUser) ? null : (int) $authUser->id,
            'workshop_vehicle_search_enabled' => $workshopEnabled,
        ];

        $max = max(1, min(20000, $max));

        if ($detailMode === 'PRODUCT') {
            $rawRows = $this->salesLookupService->listCommercialDocumentProductsForExport($companyId, $filters, $max);

            return [
                'mode'      => 'PRODUCT',
                'filename'  => 'reporte_ventas_producto_' . now()->format('Ymd_His') . '.csv',
                'json_rows' => $rawRows,
                'count'     => $rawRows->count(),
                'max'       => $max,
                'headers'   => ['ID','Documento','Serie','Numero','Solicita','Emite','Actor','Fecha Emision','Cliente','Vehiculo','Forma de Pago','Estado','Estado SUNAT','Estado Baja SUNAT','Producto','Unidad','Cantidad','Precio Unitario','Total Linea','SENATI'],
                'rows'      => $rawRows->map(function ($row) {
                    $issuer = trim((string) ($row->created_by_user_name ?? ''));
                    $seller = trim((string) ($row->origin_seller_user_name ?? ''));
                    $actor  = ($seller !== '' && $issuer !== '' && strtoupper($seller) !== strtoupper($issuer))
                        ? ('Solicita: ' . $seller . ' | Emite: ' . $issuer)
                        : ($issuer !== '' ? $issuer : ($seller !== '' ? $seller : '-'));
                    return [
                        (int) $row->id,
                        (string) ($row->document_kind_label ?? $row->document_kind),
                        (string) $row->series,
                        (string) $row->number,
                        $seller !== '' ? $seller : ($issuer !== '' ? $issuer : '-'),
                        $issuer !== '' ? $issuer : ($seller !== '' ? $seller : '-'),
                        $actor,
                        $row->issue_at ? (string) $row->issue_at : '',
                        (string) ($row->customer_name ?? ''),
                        trim(implode(' | ', array_filter([
                            (string) ($row->vehicle_plate_snapshot ?? ''),
                            (string) ($row->vehicle_brand_snapshot ?? ''),
                            (string) ($row->vehicle_model_snapshot ?? ''),
                        ], fn ($v) => trim($v) !== ''))),
                        (string) ($row->payment_method_name ?? 'Sin metodo de pago'),
                        (string) ($row->status_label ?? $row->status),
                        (string) ($row->sunat_status ?? ''),
                        (string) ($row->sunat_void_status ?? ''),
                        (string) ($row->product_description ?? ''),
                        (string) ($row->unit_code ?? '-'),
                        number_format((float) ($row->qty ?? 0), 3, '.', ''),
                        number_format((float) ($row->unit_price ?? 0), 2, '.', ''),
                        number_format((float) ($row->line_total ?? 0), 2, '.', ''),
                        number_format((float) ($row->igv ?? 0), 2, '.', ''),
                    ];
                })->all(),
            ];
        }

        $rawRows = $this->salesLookupService->listCommercialDocumentsForExport($companyId, $filters, $max);

        return [
            'mode'      => 'SUMMARY',
            'filename'  => 'reporte_ventas_' . now()->format('Ymd_His') . '.csv',
            'json_rows' => $rawRows,
            'count'     => $rawRows->count(),
            'max'       => $max,
            'headers'   => ['ID','Documento','Serie','Numero','Documento Afectado','Solicita','Emite','Actor','Fecha Emision','Cliente','Forma de Pago','Estado','Estado SUNAT','Estado Baja SUNAT','Descuento Item','Descuento Global','Total','Saldo','SENATI'],
            'rows'      => $rawRows->map(function ($row) {
                $issuer = trim((string) ($row->created_by_user_name ?? ''));
                $seller = trim((string) ($row->origin_seller_user_name ?? ''));
                $actor  = ($seller !== '' && $issuer !== '' && strtoupper($seller) !== strtoupper($issuer))
                    ? ('Solicita: ' . $seller . ' | Emite: ' . $issuer)
                    : ($issuer !== '' ? $issuer : ($seller !== '' ? $seller : '-'));
                return [
                    (int) $row->id,
                    (string) ($row->document_kind_label ?? $row->document_kind),
                    (string) $row->series,
                    (string) $row->number,
                    trim((string) (($row->source_document_kind ?? '') !== ''
                        ? (($row->source_document_kind ?? '') . ' ' . ($row->source_document_number ?? ''))
                        : ($row->source_document_number ?? ''))),
                    $seller !== '' ? $seller : ($issuer !== '' ? $issuer : '-'),
                    $issuer !== '' ? $issuer : ($seller !== '' ? $seller : '-'),
                    $actor,
                    $row->issue_at ? (string) $row->issue_at : '',
                    (string) ($row->customer_name ?? ''),
                    (string) ($row->payment_method_name ?? 'Sin metodo de pago'),
                    (string) ($row->status_label ?? $row->status),
                    (string) ($row->sunat_status ?? ''),
                    (string) ($row->sunat_void_status ?? ''),
                    number_format((float) ($row->item_discount_total ?? 0), 2, '.', ''),
                    number_format((float) ($row->global_discount_total ?? 0), 2, '.', ''),
                    number_format((float) ($row->total ?? 0), 2, '.', ''),
                    number_format((float) ($row->balance_due ?? 0), 2, '.', ''),
                    number_format((float) ($row->igv ?? 0), 2, '.', ''),
                ];
            })->all(),
        ];
    }

    public function sendCommercialDocumentShareEmail(
        int $companyId,
        int $documentId,
        array $emailParams,
        bool $isHttpsContext
    ): array {
        $format = in_array($emailParams['format'] ?? 'a4', ['a4', 'ticket'], true)
            ? (string) ($emailParams['format'] ?? 'a4')
            : 'a4';

        $shareLink = $this->generateCommercialDocumentShareLink($companyId, $documentId, $format, $isHttpsContext);
        $pdfUrl    = $shareLink['url'];
        $expiresAt = $shareLink['expiresAt'];

        $detail  = $this->buildCommercialDocumentDetail($companyId, $documentId);
        $series  = trim((string) ($detail['series'] ?? ''));
        $number  = trim((string) ($detail['number'] ?? ''));
        $docKind = trim((string) ($detail['documentKind'] ?? 'Comprobante'));
        $docLabel = trim($docKind . ' ' . ($series !== '' ? $series : '-') . '-' . ($number !== '' ? $number : '0'));

        $company = $this->resolveCompanyPrintProfileUseCase->execute($companyId);

        // Resolve SMTP profile from company settings
        $smtpProfile     = $this->resolveCompanySmtpProfile($companyId);
        $companyName     = trim((string) ($smtpProfile['from_name'] ?? $company['trade_name'] ?? $company['legal_name'] ?? config('mail.from.name', config('app.name', 'Facturacion'))));
        $configuredFrom  = trim((string) ($smtpProfile['from_email'] ?? $company['email'] ?? ''));
        $defaultFrom     = trim((string) config('mail.from.address', ''));
        $fromEmail       = filter_var($configuredFrom, FILTER_VALIDATE_EMAIL) ? $configuredFrom : $defaultFrom;

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new SalesDocumentException('No hay correo remitente configurado para la empresa.', 422);
        }

        $toEmail       = trim((string) ($emailParams['to_email'] ?? ''));
        $subject       = trim((string) ($emailParams['subject'] ?? ''));
        $customMessage = trim((string) ($emailParams['message'] ?? ''));

        if ($subject === '') {
            $subject = $docLabel . ' emitido';
        }
        if ($customMessage === '') {
            $customMessage = "Hola,\n\nTe compartimos tu {$docLabel}.\n\nDescarga PDF: {$pdfUrl}\n\nGracias por tu compra.";
        }

        $shouldUseCustomSmtp = $smtpProfile['host'] !== '' && $smtpProfile['port'] > 0;
        $originalConfig = $shouldUseCustomSmtp ? $this->snapshotMailConfig() : [];

        if ($shouldUseCustomSmtp) {
            config([
                'mail.driver'         => 'smtp',
                'mail.host'           => $smtpProfile['host'],
                'mail.port'           => $smtpProfile['port'],
                'mail.encryption'     => $smtpProfile['encryption'],
                'mail.username'       => $smtpProfile['username'] !== '' ? $smtpProfile['username'] : null,
                'mail.password'       => $smtpProfile['password'] !== '' ? $smtpProfile['password'] : null,
                'mail.from.address'   => $fromEmail,
                'mail.from.name'      => $companyName !== '' ? $companyName : config('app.name', 'Facturacion'),
            ]);
        }

        try {
            Mail::html(
                nl2br(htmlspecialchars($customMessage, ENT_QUOTES, 'UTF-8'), false),
                function ($message) use ($toEmail, $fromEmail, $companyName, $subject) {
                    $message->to($toEmail)
                        ->from($fromEmail, $companyName !== '' ? $companyName : null)
                        ->subject($subject);
                }
            );
        } finally {
            if ($shouldUseCustomSmtp && $originalConfig !== []) {
                config($originalConfig);
            }
        }

        return [
            'to'        => $toEmail,
            'from'      => $fromEmail,
            'subject'   => $subject,
            'url'       => $pdfUrl,
            'expiresAt' => $expiresAt,
        ];
    }

    private function resolveCompanySmtpProfile(int $companyId): array
    {
        $empty = ['host' => '', 'port' => 0, 'encryption' => null, 'username' => '', 'password' => '', 'from_email' => '', 'from_name' => ''];

        if (!$this->salesLookupService->tableExists('core.company_settings')) {
            return $empty;
        }

        $columns = $this->salesLookupService->tableColumns('core.company_settings');
        if (!in_array('extra_data', $columns, true)) {
            return $empty;
        }

        $settings = $this->salesLookupService->findLatestCompanySettings(
            $companyId,
            ['extra_data'],
            false,
            in_array('updated_at', $columns, true),
            in_array('created_at', $columns, true)
        );

        if (!$settings || !isset($settings->extra_data)) {
            return $empty;
        }

        $extraData = json_decode((string) $settings->extra_data, true);
        if (!is_array($extraData)) {
            return $empty;
        }

        $host = trim((string) ($extraData['smtp_host'] ?? ''));
        $port = (int) ($extraData['smtp_port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $port = 0;
        }

        $encRaw     = strtolower(trim((string) ($extraData['smtp_encryption'] ?? '')));
        $encryption = in_array($encRaw, ['tls', 'ssl', 'starttls'], true) ? $encRaw : null;
        $username   = trim((string) ($extraData['smtp_username'] ?? ''));
        $password   = '';
        $encPw      = trim((string) ($extraData['smtp_password_enc'] ?? ''));

        if ($encPw !== '') {
            try {
                $password = (string) Crypt::decryptString($encPw);
            } catch (\Throwable $e) {
                $password = '';
            }
        }

        $fromEmail = trim((string) ($extraData['smtp_from_email'] ?? ''));
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = '';
        }

        return [
            'host'       => $host,
            'port'       => $port,
            'encryption' => $encryption,
            'username'   => $username,
            'password'   => $password,
            'from_email' => $fromEmail,
            'from_name'  => trim((string) ($extraData['smtp_from_name'] ?? '')),
        ];
    }

    private function snapshotMailConfig(): array
    {
        return [
            'mail.driver'       => config('mail.driver'),
            'mail.host'         => config('mail.host'),
            'mail.port'         => config('mail.port'),
            'mail.encryption'   => config('mail.encryption'),
            'mail.username'     => config('mail.username'),
            'mail.password'     => config('mail.password'),
            'mail.from.address' => config('mail.from.address'),
            'mail.from.name'    => config('mail.from.name'),
        ];
    }

    // -------------------------------------------------------------------------
    // WRITES
    // -------------------------------------------------------------------------

    public function createCommercialDocument(object $authUser, int $companyId, array $payload, ?int $branchId = null): array
    {
        $workshopMultiVehicleEnabled = $this->supportService->isCommerceFeatureEnabledForContextWithDefault(
            $companyId,
            $branchId,
            'SALES_WORKSHOP_MULTI_VEHICLE',
            false
        ) && $this->salesLookupService->tableExists('sales.customer_vehicles');

        $prepared = $this->prepareCreateCommercialDocumentUseCase->execute(
            $authUser,
            $payload,
            $companyId,
            $workshopMultiVehicleEnabled
        );

        $command = $this->createCommercialDocumentCommandFactory->fromPreparedPayload(
            $authUser,
            $prepared['payload'],
            $companyId,
            $prepared['branch_id'],
            $prepared['warehouse_id'],
            $prepared['cash_register_id']
        );

        return $this->createCommercialDocumentUseCase->executeCommand($command);
    }

    public function convertCommercialDocument(object $authUser, int $companyId, int $sourceId, array $payload): array
    {
        $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));

        if ($roleCode === '' && $roleProfile === '') {
            $roleContext = $this->supportService->resolveAuthRoleContext((int) $authUser->id, $companyId);
            $roleCode = strtoupper(trim((string) ($roleContext['role_code'] ?? '')));
            $roleProfile = strtoupper(trim((string) ($roleContext['role_profile'] ?? '')));
        }

        $source = $this->salesDocumentConversionService->findSourceDocument($companyId, $sourceId);

        if (!$source) {
            throw new SalesDocumentException('Documento origen no encontrado', 404);
        }

        if (!in_array((string) $source->document_kind, ['QUOTATION', 'SALES_ORDER'], true)) {
            throw new SalesDocumentException('Solo se puede convertir cotizacion o pedido de venta', 422);
        }

        $sourceBranchId = $source->branch_id !== null ? (int) $source->branch_id : null;
        $sellerToCashierEnabled = $this->supportService->isCommerceFeatureEnabledForContextWithDefault(
            $companyId,
            $sourceBranchId,
            'SALES_SELLER_TO_CASHIER',
            false
        );

        if ($sellerToCashierEnabled && !$this->supportService->isCashierActor($roleProfile, $roleCode)) {
            throw new SalesDocumentException('Solo caja puede convertir pedidos en este modo de venta.', 403);
        }

        if ($sellerToCashierEnabled
            && $this->supportService->isCashierActor($roleProfile, $roleCode)
            && (!isset($payload['cash_register_id']) || (int) $payload['cash_register_id'] <= 0)) {
            throw new SalesDocumentException('Debes seleccionar la estacion de caja activa antes de convertir en este modo.', 422);
        }

        $prepared = $this->prepareConvertCommercialDocumentUseCase->execute(
            $source,
            $payload,
            $companyId,
            $sourceId,
            $sellerToCashierEnabled
        );

        $command = $this->createCommercialDocumentCommandFactory->fromPreparedPayload(
            $authUser,
            $prepared['forward_payload'],
            $companyId,
            $prepared['forward_payload']['branch_id'] ?? null,
            $prepared['forward_payload']['warehouse_id'] ?? null,
            $prepared['forward_payload']['cash_register_id'] ?? null
        );

        return $this->createCommercialDocumentUseCase->executeCommand($command);
    }

    public function updateCommercialDocument(object $authUser, int $companyId, int $documentId, array $payload): array
    {
        $preparedPayload = $this->prepareUpdateCommercialDocumentUseCase->execute($payload);

        $result = $this->updateCommercialDocumentDraftUseCase->execute(
            $authUser,
            $companyId,
            $documentId,
            $preparedPayload
        );

        $this->invalidateSalesPrintCache($companyId, $documentId);

        return $result;
    }

    public function voidCommercialDocument(object $authUser, int $companyId, int $documentId, array $payload): array
    {
        $featureBranchId = $this->salesLookupService->findCommercialDocumentBranchId($companyId, $documentId);
        $requireVoidPassword = $this->supportService->isCommerceFeatureEnabledForContextWithDefault(
            $companyId,
            $featureBranchId,
            'SALES_VOID_REQUIRE_PASSWORD',
            false
        );

        $preparedPayload = $this->prepareVoidCommercialDocumentUseCase->execute($authUser, $payload, $requireVoidPassword);

        $result = $this->voidCommercialDocumentUseCase->execute($authUser, $companyId, $documentId, $preparedPayload);

        $this->invalidateSalesPrintCache($companyId, $documentId);

        return $result;
    }

    public function applyAcceptedSunatVoid(
        object $authUser,
        int $companyId,
        int $documentId,
        ?string $reason = null,
        ?string $notes = null
    ): void {
        $document = $this->salesDocumentReadService->findDocumentForShow($companyId, $documentId);
        if (!$document) {
            throw new SalesDocumentException('Documento no encontrado', 404);
        }

        $currentStatus = strtoupper(trim((string) ($document->status ?? '')));
        if (in_array($currentStatus, ['VOID', 'VOIDED', 'CANCELED'], true)) {
            $this->invalidateSalesPrintCache($companyId, $documentId);
            return;
        }

        $this->voidCommercialDocumentUseCase->execute($authUser, $companyId, $documentId, [
            'reason' => $reason ?? 'Comunicacion de baja SUNAT',
            'notes' => $notes ?? 'Anulado por comunicacion de baja SUNAT',
            'void_at' => now()->toDateTimeString(),
            'sunat_void_status' => 'ACCEPTED',
        ]);

        $this->invalidateSalesPrintCache($companyId, $documentId);
    }

    public function bulkSunatAnnulmentFromReport(object $authUser, int $companyId, array $payload): array
    {
        $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));

        if ($roleCode === '' && $roleProfile === '') {
            $roleContext = $this->supportService->resolveAuthRoleContext((int) $authUser->id, $companyId);
            $roleCode = strtoupper(trim((string) ($roleContext['role_code'] ?? '')));
            $roleProfile = strtoupper(trim((string) ($roleContext['role_profile'] ?? '')));
        }

        if (!$this->supportService->isAdminActor($roleCode)) {
            throw new SalesDocumentException('Solo un administrador puede ejecutar anulaciones masivas.', 403);
        }

        $featureBranchId = null;
        if (isset($payload['branch_id']) && (int) $payload['branch_id'] > 0) {
            $featureBranchId = (int) $payload['branch_id'];
        } elseif (isset($authUser->branch_id) && (int) $authUser->branch_id > 0) {
            $featureBranchId = (int) $authUser->branch_id;
        }

        $bulkEnabled = $this->supportService->isCommerceFeatureEnabledForContextWithDefault(
            $companyId,
            $featureBranchId,
            'SALES_BULK_VOID_REPORT_ENABLED',
            false
        );

        if (!$bulkEnabled) {
            throw new SalesDocumentException('La anulacion masiva en reporte no esta habilitada para este contexto.', 403);
        }

        $configuredMax = max(1, min(500, (int) env('SALES_BULK_VOID_MAX_DOCS', 200)));
        $maxDocuments = max(1, min(500, (int) ($payload['max_documents'] ?? $configuredMax)));
        $configuredPauseMs = max(0, min(10000, (int) env('SALES_BULK_VOID_PAUSE_MS', 700)));
        $pauseMs = max(0, min(10000, (int) ($payload['pause_ms'] ?? $configuredPauseMs)));
        $perPage = 100;

        $queryParams = [
            'branch_id' => $payload['branch_id'] ?? null,
            'warehouse_id' => $payload['warehouse_id'] ?? null,
            'cash_register_id' => $payload['cash_register_id'] ?? null,
            'source_origin' => $payload['source_origin'] ?? null,
            'document_kind' => $payload['document_kind'] ?? null,
            'document_kind_id' => $payload['document_kind_id'] ?? null,
            'conversion_state' => $payload['conversion_state'] ?? null,
            'customer' => $payload['customer'] ?? null,
            'customer_id' => $payload['customer_id'] ?? null,
            'customer_vehicle_id' => $payload['customer_vehicle_id'] ?? null,
            'issue_date_from' => $payload['issue_date_from'] ?? null,
            'issue_date_to' => $payload['issue_date_to'] ?? null,
            'series' => $payload['series'] ?? null,
            'number' => $payload['number'] ?? null,
            'status' => 'ISSUED',
        ];

        $page = 1;
        $scannedCount = 0;
        $candidates = [];

        do {
            $pageResult = $this->paginateCommercialDocuments($authUser, $companyId, $queryParams, $page, $perPage);
            $pageRows = $pageResult['data'] ?? [];
            if ($pageRows instanceof Collection) {
                $rows = $pageRows->all();
            } elseif (is_array($pageRows)) {
                $rows = $pageRows;
            } else {
                $rows = [];
            }
            $lastPage = (int) (($pageResult['meta']['last_page'] ?? $page) ?: $page);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $scannedCount++;
                $candidate = $this->classifyBulkSunatAnnulmentCandidate($row);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                    if (count($candidates) >= $maxDocuments) {
                        break 2;
                    }
                }
            }

            $page++;
        } while ($page <= $lastPage);

        $reason = trim((string) ($payload['reason'] ?? 'Anulacion masiva desde reporte de ventas'));
        $notes = trim((string) ($payload['notes'] ?? 'Proceso masivo de anulacion'));
        $voidPassword = isset($payload['void_password']) ? (string) $payload['void_password'] : null;

        $processed = [];
        $summary = [
            'invoices_attempted' => 0,
            'invoices_accepted' => 0,
            'invoices_pending' => 0,
            'receipts_attempted' => 0,
            'receipts_queued_to_ra' => 0,
            'errors' => 0,
        ];

        $totalCandidates = count($candidates);
        foreach ($candidates as $index => $candidate) {
            $documentId = (int) $candidate['document_id'];
            $operation = (string) $candidate['operation'];
            $documentKind = (string) $candidate['document_kind'];

            try {
                if ($operation === 'RA_SUMMARY') {
                    $summary['receipts_attempted']++;

                    $voidPayload = [
                        'reason' => $reason,
                        'notes' => $notes,
                        'void_at' => now()->toDateTimeString(),
                    ];
                    if ($voidPassword !== null && trim($voidPassword) !== '') {
                        $voidPayload['void_password'] = $voidPassword;
                    }

                    $voidResult = $this->voidCommercialDocument($authUser, $companyId, $documentId, $voidPayload);
                    $summaryId = isset($voidResult['daily_summary_id']) ? (int) $voidResult['daily_summary_id'] : null;

                    $summary['receipts_queued_to_ra']++;
                    $processed[] = [
                        'document_id' => $documentId,
                        'document_kind' => $documentKind,
                        'operation' => 'RA_SUMMARY',
                        'status' => 'PENDING_SUMMARY',
                        'daily_summary_id' => $summaryId,
                    ];
                } else {
                    $summary['invoices_attempted']++;

                    $bridgeResult = $this->taxBridgeService->sendVoidCommunication($companyId, $documentId, $reason !== '' ? $reason : null);
                    $bridgeStatus = strtoupper(trim((string) ($bridgeResult['status'] ?? '')));

                    if ($bridgeStatus === 'ACCEPTED') {
                        $this->applyAcceptedSunatVoid($authUser, $companyId, $documentId, $reason !== '' ? $reason : null, $notes !== '' ? $notes : null);
                        $summary['invoices_accepted']++;
                    } else {
                        $summary['invoices_pending']++;
                    }

                    $processed[] = [
                        'document_id' => $documentId,
                        'document_kind' => $documentKind,
                        'operation' => 'SUNAT_VOID',
                        'status' => $bridgeStatus !== '' ? $bridgeStatus : 'SENT',
                        'void_number' => isset($bridgeResult['void_number']) ? (int) $bridgeResult['void_number'] : null,
                        'http_code' => isset($bridgeResult['http_code']) ? (int) $bridgeResult['http_code'] : null,
                    ];
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                $processed[] = [
                    'document_id' => $documentId,
                    'document_kind' => $documentKind,
                    'operation' => $operation,
                    'status' => 'ERROR',
                    'message' => $e->getMessage(),
                ];
            }

            if ($pauseMs > 0 && ($index + 1) < $totalCandidates) {
                usleep($pauseMs * 1000);
            }
        }

        return [
            'scanned_count' => $scannedCount,
            'eligible_count' => $totalCandidates,
            'processed_count' => count($processed),
            'max_documents' => $maxDocuments,
            'pause_ms' => $pauseMs,
            'summary' => $summary,
            'items' => $processed,
        ];
    }

    private function classifyBulkSunatAnnulmentCandidate($row): ?array
    {
        $documentId = (int) ($this->readDocumentRowValue($row, 'id') ?? 0);
        if ($documentId <= 0) {
            return null;
        }

        $documentKind = strtoupper(trim((string) ($this->readDocumentRowValue($row, 'document_kind') ?? '')));
        $status = strtoupper(trim((string) ($this->readDocumentRowValue($row, 'status') ?? '')));
        $sunatStatus = strtoupper(trim((string) ($this->readDocumentRowValue($row, 'sunat_status') ?? '')));
        $sunatVoidStatus = strtoupper(trim((string) ($this->readDocumentRowValue($row, 'sunat_void_status') ?? '')));

        if ($status !== 'ISSUED' || $sunatStatus !== 'ACCEPTED') {
            return null;
        }

        if ($documentKind === 'INVOICE') {
            if (in_array($sunatVoidStatus, ['ACCEPTED', 'SENDING', 'SENT', 'PENDING_SUMMARY'], true)) {
                return null;
            }

            return [
                'document_id' => $documentId,
                'document_kind' => $documentKind,
                'operation' => 'SUNAT_VOID',
            ];
        }

        if ($documentKind === 'RECEIPT') {
            if (in_array($sunatVoidStatus, ['ACCEPTED', 'PENDING_SUMMARY'], true)) {
                return null;
            }

            return [
                'document_id' => $documentId,
                'document_kind' => $documentKind,
                'operation' => 'RA_SUMMARY',
            ];
        }

        return null;
    }

    private function readDocumentRowValue($row, string $key)
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        if (is_object($row) && isset($row->{$key})) {
            return $row->{$key};
        }

        return null;
    }

    public function buildPrintableCommercialDocumentHtml(int $companyId, int $documentId, string $format = 'ticket'): string
    {
        $normalizedFormat = in_array($format, ['ticket', 'a4'], true) ? $format : 'ticket';

        // First attempt: retrieve from document-level cache
        if (class_exists(\App\Infrastructure\Repositories\Sales\Documents\CommercialDocumentPrintCacheService::class)) {
            try {
                $printCacheService = app(\App\Infrastructure\Repositories\Sales\Documents\CommercialDocumentPrintCacheService::class);
                $cachedHtml = $printCacheService->getCachedHtml($documentId, $normalizedFormat);
                if ($cachedHtml !== null) {
                    if (str_contains($cachedHtml, '<!--sales-print-template:' . self::SALES_PRINT_TEMPLATE_SIGNATURE . '-->')) {
                        return $cachedHtml;
                    }

                    // Evita seguir sirviendo HTML persistido por una plantilla antigua.
                    $printCacheService->invalidateDocumentCache($documentId);
                }
            } catch (\Throwable $e) {
                \Log::debug('Document print cache lookup failed', ['error' => $e->getMessage()]);
                // Fallthrough to application cache / fresh build
            }
        }

        if (!$this->shouldUseSalesPrintCache()) {
            return $this->buildPrintableCommercialDocumentHtmlFresh($companyId, $documentId, $normalizedFormat);
        }

        $cacheKey = $this->buildSalesPrintHtmlCacheKey($companyId, $documentId, $normalizedFormat);

        return Cache::remember($cacheKey, $this->salesPrintCacheTtlSeconds(), function () use ($companyId, $documentId, $normalizedFormat) {
            return $this->buildPrintableCommercialDocumentHtmlFresh($companyId, $documentId, $normalizedFormat);
        });
    }

    private function buildPrintableCommercialDocumentHtmlFresh(int $companyId, int $documentId, string $format = 'ticket'): string
    {
        $doc = $this->salesDocumentReadService->findDocumentForShow($companyId, $documentId);

        if (!$doc) {
            throw new SalesDocumentException('Documento no encontrado', 404);
        }

        $items = $this->salesDocumentReadService->resolveDocumentItemsWithFallback($companyId, $documentId);

        $companyProfile = $this->resolveCompanyPrintProfileUseCase->execute($companyId);
        $companyName = trim((string) (
            $companyProfile['trade_name']
            ?? $companyProfile['legal_name']
            ?? 'EMPRESA'
        ));
        $companyTaxId = trim((string) ($companyProfile['tax_id'] ?? ''));

        $normalizedFormat = in_array($format, ['ticket', 'a4'], true) ? $format : 'ticket';

        $metadata = [];
        if ($doc->metadata !== null && trim((string) $doc->metadata) !== '') {
            $decoded = json_decode((string) $doc->metadata, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $documentKindRaw = strtoupper(trim((string) ($doc->document_kind ?? 'DOCUMENTO')));
        $noteBaseKind = null;
        if ($documentKindRaw === 'CREDIT_NOTE' || str_starts_with($documentKindRaw, 'CREDIT_NOTE_')) {
            $noteBaseKind = 'CREDIT_NOTE';
        } elseif ($documentKindRaw === 'DEBIT_NOTE' || str_starts_with($documentKindRaw, 'DEBIT_NOTE_')) {
            $noteBaseKind = 'DEBIT_NOTE';
        }

        $documentKindLabel = [
            'INVOICE' => 'FACTURA ELECTRONICA',
            'RECEIPT' => 'BOLETA DE VENTA ELECTRONICA',
            'SALES_ORDER' => 'PEDIDO DE VENTA',
            'QUOTATION' => 'COTIZACION',
        ][$documentKindRaw] ?? ($documentKindRaw !== '' ? $documentKindRaw : 'DOCUMENTO');

        if ($noteBaseKind === 'CREDIT_NOTE') {
            $documentKindLabel = 'NOTA DE CREDITO';
        } elseif ($noteBaseKind === 'DEBIT_NOTE') {
            $documentKindLabel = 'NOTA DE DEBITO';
        }

        $isNoteDocument = $noteBaseKind !== null;

        $sourceDocumentKind = trim((string) ($metadata['source_document_kind'] ?? ''));
        $sourceDocumentNumber = trim((string) ($metadata['source_document_number'] ?? ''));
        $sourceDocumentId = (int) (
            $metadata['source_document_id']
            ?? ($doc->source_document_id ?? null)
            ?? ($doc->reference_document_id ?? null)
            ?? 0
        );

        if (($sourceDocumentKind === '' || $sourceDocumentNumber === '') && $sourceDocumentId > 0) {
            $sourceDocument = DB::table('sales.commercial_documents')
                ->select(['document_kind', 'series', 'number'])
                ->where('company_id', $companyId)
                ->where('id', $sourceDocumentId)
                ->first();

            if ($sourceDocument) {
                if ($sourceDocumentKind === '') {
                    $sourceDocumentKind = strtoupper(trim((string) ($sourceDocument->document_kind ?? '')));
                }
                if ($sourceDocumentNumber === '') {
                    $sourceSeries = trim((string) ($sourceDocument->series ?? ''));
                    $sourceNumber = trim((string) ($sourceDocument->number ?? ''));
                    $sourceDocumentNumber = trim($sourceSeries . ($sourceSeries !== '' && $sourceNumber !== '' ? '-' : '') . $sourceNumber);
                }
            }
        }

        $sourceDocumentLabel = [
            'INVOICE' => 'Factura',
            'RECEIPT' => 'Boleta',
            'SALES_ORDER' => 'Pedido',
            'QUOTATION' => 'Cotizacion',
            'CREDIT_NOTE' => 'N. Credito',
            'DEBIT_NOTE' => 'N. Debito',
        ][strtoupper($sourceDocumentKind)] ?? ($sourceDocumentKind !== '' ? $sourceDocumentKind : '-');

        $noteReasonCode = trim((string) (
            $metadata['note_reason_code']
            ?? ($doc->reference_reason_code ?? '')
        ));
        $noteReasonDescription = trim((string) ($metadata['note_reason_description'] ?? ''));

        if ($isNoteDocument && $noteReasonDescription === '' && $noteReasonCode !== '') {
            $noteReasons = $this->salesLookupService->resolveDocumentNoteReasonsRows($noteBaseKind);
            foreach ($noteReasons as $noteReason) {
                $candidateCode = strtoupper(trim((string) ($noteReason['code'] ?? '')));
                if ($candidateCode === strtoupper($noteReasonCode)) {
                    $noteReasonDescription = trim((string) ($noteReason['description'] ?? ''));
                    break;
                }
            }
        }

        $issueDate = $this->formatDisplayDate((string) ($doc->issue_at ?? ''));
        $dueDate = $this->formatDisplayDate((string) ($doc->due_at ?? ''));
        $guideNo = trim((string) (
            $metadata['guia']
            ?? $metadata['nro_guia']
            ?? $metadata['guide_number']
            ?? $metadata['guideNumber']
            ?? ''
        ));

        $electronicSignature = $this->normalizeElectronicSignatureValue($this->findFirstMetaStringValue($metadata, [
            'sunat_electronic_signature',
            'sunat_signature',
            'firma_electronica',
            'firma',
            'signature',
            'hash_cpe',
            'codigo_hash',
            'digest_value',
            'digestValue',
        ]));

        $documentNotes = trim((string) ($doc->notes ?? ''));
        if ($documentNotes === '') {
            $documentNotes = $this->findFirstMetaStringValue($metadata, [
                'observations',
                'observation',
                'observacion',
                'observaciones',
                'notes',
                'note',
                'glosa',
                'comment',
                'comments',
            ]);
        }

        $branchId = isset($doc->branch_id) && $doc->branch_id !== null ? (int) $doc->branch_id : null;
        $salesOrderMultiPaymentEnabled = $this->supportService->isCommerceFeatureEnabledForContextWithDefault(
            $companyId,
            $branchId,
            'SALES_ORDER_MULTI_PAYMENT_ENABLED',
            false
        );
        $isSalesOrderDocument = $documentKindRaw === 'SALES_ORDER';
        $showPaymentBreakdown = $isSalesOrderDocument && $salesOrderMultiPaymentEnabled;

        $paymentBreakdown = [];
        $paymentRows = $metadata['payment_breakdown'] ?? null;
        if ($showPaymentBreakdown && is_array($paymentRows)) {
            foreach ($paymentRows as $paymentRow) {
                if (!is_array($paymentRow)) {
                    continue;
                }

                $amount = (float) ($paymentRow['amount'] ?? 0);
                if ($amount <= 0) {
                    continue;
                }

                $paymentBreakdown[] = [
                    'method' => trim((string) (
                        $paymentRow['payment_method_name']
                        ?? $paymentRow['method_name']
                        ?? $paymentRow['name']
                        ?? 'Metodo de pago'
                    )),
                    'amount' => number_format($amount, 2, '.', ''),
                ];
            }
        }

        $rows = [];
        foreach ($items as $item) {
            $itemMeta = [];
            $itemMetadataRaw = data_get($item, 'metadata');
            if ($itemMetadataRaw !== null && trim((string) $itemMetadataRaw) !== '') {
                $itemDecoded = json_decode((string) $itemMetadataRaw, true);
                if (is_array($itemDecoded)) {
                    $itemMeta = $itemDecoded;
                }
            }

            $productCode = trim((string) (
                data_get($item, 'product_code')
                ?? $itemMeta['product_code']
                ?? $itemMeta['productCode']
                ?? $itemMeta['code']
                ?? ''
            ));

            $rows[] = [
                'line_no' => (int) (data_get($item, 'line_no') ?? 0),
                'description' => (string) (data_get($item, 'description') ?? '-'),
                'product_code' => $productCode,
                'unit_label' => (string) (data_get($item, 'unit_code') ?? 'NIU'),
                'qty' => number_format((float) (data_get($item, 'qty') ?? 0), 2, '.', ''),
                'unit_price' => number_format((float) (data_get($item, 'unit_price') ?? 0), 2, '.', ''),
                'total' => number_format((float) (data_get($item, 'total') ?? 0), 2, '.', ''),
            ];
        }

        $subtotal = (float) ($doc->subtotal ?? 0);
        $taxTotal = (float) ($doc->tax_total ?? 0);
        $gravadaTotal = $subtotal;
        $inafectaTotal = 0.0;
        $exoneradaTotal = 0.0;

        if (($subtotal <= 0.00001 || $taxTotal <= 0.00001) && $items->count() > 0) {
            $allTaxCategories = collect($this->companyIgvRateService->applyActiveRateToTaxCategories(
                $companyId,
                $this->salesLookupService->resolveTaxCategoriesRows($companyId)
            ));

            $computedGravada = 0.0;
            $computedInafecta = 0.0;
            $computedExonerada = 0.0;
            $computedTaxTotal = 0.0;

            foreach ($items as $item) {
                $taxCat = $item->tax_category_id ? $allTaxCategories->firstWhere('id', $item->tax_category_id) : null;
                $taxLabel = strtoupper(trim((string) (is_array($taxCat) ? ($taxCat['label'] ?? 'Sin IGV') : 'Sin IGV')));
                $taxCode = strtoupper(trim((string) (is_array($taxCat) ? ($taxCat['code'] ?? '') : '')));
                $taxRate = (float) (is_array($taxCat) ? ($taxCat['rate_percent'] ?? 0) : 0);

                $itemSubtotal = (float) ($item->subtotal ?? 0);
                if ($itemSubtotal <= 0.00001) {
                    $itemSubtotal = max(0.0, (float) ($item->total ?? 0) - (float) ($item->tax_total ?? 0));
                }
                $itemTaxTotal = (float) ($item->tax_total ?? 0);

                $isGravada = $itemTaxTotal > 0.00001 || $taxRate > 0.00001
                    || in_array($taxCode, ['10', '1000', 'IGV', 'VAT', 'GRAVADA'], true)
                    || str_contains($taxLabel, 'IGV') || str_contains($taxLabel, 'GRAV');
                $isExonerada = in_array($taxCode, ['20', '9997', 'EXONERADA'], true) || str_contains($taxLabel, 'EXONER');
                $isInafecta = in_array($taxCode, ['30', '9998', 'INAFECTA'], true) || str_contains($taxLabel, 'INAFECT');

                if ($isGravada) {
                    $computedGravada += $itemSubtotal;
                } elseif ($isExonerada) {
                    $computedExonerada += $itemSubtotal;
                } elseif ($isInafecta) {
                    $computedInafecta += $itemSubtotal;
                }

                $computedTaxTotal += $itemTaxTotal;
            }

            if ($computedTaxTotal > 0.00001) {
                $taxTotal = $computedTaxTotal;
            }
            if ($computedGravada > 0.00001 || $computedTaxTotal > 0.00001) {
                $gravadaTotal = $computedGravada;
                $inafectaTotal = $computedInafecta;
                $exoneradaTotal = $computedExonerada;
                $subtotal = max($subtotal, $computedGravada + $computedInafecta + $computedExonerada);
            }
        }

        if ($gravadaTotal <= 0.00001 && $subtotal > 0.00001) {
            $gravadaTotal = max(0.0, $subtotal - $inafectaTotal - $exoneradaTotal);
        }

        $grandTotal = (float) ($doc->total ?? 0);
        $showProductCodes = $this->supportService->isCommerceFeatureEnabledForContextWithDefault(
            $companyId,
            $branchId,
            'SALES_PRINT_SHOW_PRODUCT_CODES',
            true
        );
        $showVehicleInfo = $this->supportService->isCommerceFeatureEnabledForContextWithDefault(
            $companyId,
            $branchId,
            'SALES_WORKSHOP_MULTI_VEHICLE',
            false
        );
        $showPaymentBrandsRaw = $companyProfile['show_payment_brand_icons'] ?? $companyProfile['showPaymentBrandIcons'] ?? true;
        $showPaymentBrandIcons = filter_var($showPaymentBrandsRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($showPaymentBrandIcons === null) {
            $showPaymentBrandIcons = (bool) $showPaymentBrandsRaw;
        }
        $paymentBrandIcons = $showPaymentBrandIcons ? $this->resolvePaymentBrandLogoSources() : [];
        $customerPhone = trim((string) ($doc->customer_phone ?? ''));
        $vehicleInfo = trim(implode(' ', array_filter([
            trim((string) ($doc->vehicle_plate_snapshot ?? '')),
            trim((string) ($doc->vehicle_brand_snapshot ?? '')),
            trim((string) ($doc->vehicle_model_snapshot ?? '')),
        ], static fn ($value) => $value !== '')));
        $totalWords = $this->amountToSpanishWords($grandTotal, (string) ($doc->currency_code ?? 'PEN'));

        logger()->info('SalesDocumentApplicationService printable flags', [
            'company_id' => $companyId,
            'document_id' => $documentId,
            'branch_id' => $branchId,
            'show_product_codes' => $showProductCodes,
            'show_vehicle_info' => $showVehicleInfo,
            'show_payment_brand_icons' => $showPaymentBrandIcons,
            'format' => $normalizedFormat,
        ]);

        $html = view('sales.documents.printable_commercial_document', [
            'format' => $normalizedFormat,
            'companyName' => $companyName,
            'companyTaxId' => $companyTaxId,
            'companyAddress' => (string) ($companyProfile['address'] ?? ''),
            'companyPhone' => (string) ($companyProfile['phone'] ?? ''),
            'companyEmail' => (string) ($companyProfile['email'] ?? ''),
            'companyDescription' => (string) ($companyProfile['company_description'] ?? ''),
            'companyLogo' => (string) ($companyProfile['logo_data_uri'] ?? $companyProfile['logo_url'] ?? ''),
            'companyBankAccounts' => is_array($companyProfile['bank_accounts'] ?? null)
                ? $companyProfile['bank_accounts']
                : [],
            'documentKindLabel' => $documentKindLabel,
            'series' => (string) ($doc->series ?? ''),
            'number' => (string) ($doc->number ?? ''),
            'issueDate' => $issueDate,
            'issueDateOnly' => $this->formatDisplayDateOnly((string) ($doc->issue_at ?? '')),
            'dueDate' => $dueDate,
            'customer' => (string) ($doc->customer_name ?? '-'),
            'customerDoc' => (string) ($doc->customer_doc_number ?? '-'),
            'customerAddress' => (string) ($doc->customer_address ?? '-'),
            'customerPhone' => $customerPhone,
            'showVehicleInfo' => $showVehicleInfo,
            'vehicleInfo' => $vehicleInfo,
            'documentNotes' => $documentNotes,
            'paymentMethod' => (string) ($doc->payment_method_name ?? '-'),
            'paymentBreakdown' => $paymentBreakdown,
            'guideNo' => $guideNo,
            'electronicSignature' => $electronicSignature,
            'isNoteDocument' => $isNoteDocument,
            'sourceDocumentLabel' => $sourceDocumentLabel,
            'sourceDocumentNumber' => $sourceDocumentNumber,
            'noteReasonCode' => $noteReasonCode,
            'noteReasonDescription' => $noteReasonDescription,
            'currency' => (string) ($doc->currency_symbol ?? 'S/'),
            'currencyCode' => (string) ($doc->currency_code ?? 'PEN'),
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'gravadaTotal' => number_format($gravadaTotal, 2, '.', ''),
            'inafectaTotal' => number_format($inafectaTotal, 2, '.', ''),
            'exoneradaTotal' => number_format($exoneradaTotal, 2, '.', ''),
            'taxTotal' => number_format($taxTotal, 2, '.', ''),
            'grandTotal' => number_format($grandTotal, 2, '.', ''),
            'total' => number_format((float) ($doc->total ?? 0), 2, '.', ''),
            'totalWords' => $totalWords,
            'showProductCodes' => $showProductCodes,
            'showPaymentBrandIcons' => $showPaymentBrandIcons,
            'paymentBrandIcons' => $paymentBrandIcons,
            'rows' => $rows,
        ])->render();

        return '<!--sales-print-template:' . self::SALES_PRINT_TEMPLATE_SIGNATURE . '-->' . $html;
    }

    private function resolvePaymentBrandLogoSources(): array
    {
        $definitions = [
            ['file' => 'yape-official.png', 'alt' => 'Yape'],
            ['file' => 'plin-official.png', 'alt' => 'Plin'],
            ['file' => 'culqi-official.png', 'alt' => 'Culqi'],
        ];

        $sources = [];
        foreach ($definitions as $definition) {
            $src = $this->resolvePaymentBrandImageSource((string) $definition['file']);
            if ($src === '') {
                continue;
            }

            $sources[] = [
                'alt' => (string) $definition['alt'],
                'src' => $src,
            ];
        }

        return $sources;
    }

    private function resolvePaymentBrandImageSource(string $fileName): string
    {
        $safeName = basename(trim($fileName));
        if ($safeName === '') {
            return '';
        }

        $relativePath = '/assets/payment-logos/' . $safeName;
        $candidates = [
            public_path('assets/payment-logos/' . $safeName),
            dirname(base_path()) . DIRECTORY_SEPARATOR . 'facturacion_frontend' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'payment-logos' . DIRECTORY_SEPARATOR . $safeName,
        ];

        foreach ($candidates as $path) {
            if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
                continue;
            }

            $dataUri = $this->filePathToImageDataUri($path);
            if ($dataUri !== null) {
                return $dataUri;
            }
        }

        $assetUrl = $this->resolveAssetUrl($relativePath);
        return preg_match('#^https?://#i', $assetUrl) === 1 ? $assetUrl : '';
    }

    private function shouldUseSalesPrintCache(): bool
    {
        return filter_var(env('SALES_PRINT_CACHE_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function salesPrintCacheTtlSeconds(): int
    {
        return max(30, (int) env('SALES_PRINT_CACHE_TTL_SECONDS', 300));
    }

    private function salesPrintCacheVersionKey(int $companyId, int $documentId): string
    {
        return "sales:print:version:{$companyId}:{$documentId}";
    }

    private function resolveSalesPrintCacheVersion(int $companyId, int $documentId): int
    {
        return max(1, (int) Cache::get($this->salesPrintCacheVersionKey($companyId, $documentId), 1));
    }

    private function buildSalesPrintHtmlCacheKey(int $companyId, int $documentId, string $format): string
    {
        $version = $this->resolveSalesPrintCacheVersion($companyId, $documentId);
        return "sales:print:html:" . self::SALES_PRINT_TEMPLATE_SIGNATURE . ":{$companyId}:{$documentId}:{$format}:v{$version}";
    }

    private function buildSalesPrintPdfCacheKey(int $companyId, int $documentId, string $format, bool $isPublicPdfLink): string
    {
        $version = $this->resolveSalesPrintCacheVersion($companyId, $documentId);
        $visibility = $isPublicPdfLink ? 'public' : 'private';
        return "sales:print:pdf:" . self::SALES_PRINT_TEMPLATE_SIGNATURE . ":{$companyId}:{$documentId}:{$format}:{$visibility}:v{$version}";
    }

    private function invalidateSalesPrintCache(int $companyId, int $documentId): void
    {
        $key = $this->salesPrintCacheVersionKey($companyId, $documentId);
        $next = $this->resolveSalesPrintCacheVersion($companyId, $documentId) + 1;
        Cache::forever($key, $next);

        if (!class_exists(\App\Infrastructure\Repositories\Sales\Documents\CommercialDocumentPrintCacheService::class)) {
            return;
        }

        try {
            $printCacheService = app(\App\Infrastructure\Repositories\Sales\Documents\CommercialDocumentPrintCacheService::class);
            $printCacheService->invalidateDocumentCache($documentId);
        } catch (\Throwable $e) {
            \Log::warning('Could not invalidate persisted commercial document print cache', [
                'company_id' => $companyId,
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveAssetUrl(string $relativePath): string
    {
        $normalizedPath = '/' . ltrim(trim($relativePath), '/');
        $candidates = [
            (string) env('FRONTEND_ACCESS_URL', ''),
            (string) env('FRONTEND_URL', ''),
            (string) env('FRONTEND_APP_URL', ''),
            'https://www.fycticonsulting.com',
        ];

        foreach ($candidates as $baseUrl) {
            $base = trim($baseUrl);
            if ($base === '' || preg_match('#^https?://#i', $base) !== 1) {
                continue;
            }

            return rtrim($base, '/') . $normalizedPath;
        }

        return '';
    }

    private function filePathToImageDataUri(string $path): ?string
    {
        try {
            $contents = @file_get_contents($path);
            if (!is_string($contents) || $contents === '') {
                return null;
            }

            $mime = $this->guessImageMimeType($path, $contents);
            if ($mime === null) {
                return null;
            }

            return 'data:' . $mime . ';base64,' . base64_encode($contents);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function guessImageMimeType(string $path, string $contents): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = @finfo_buffer($finfo, $contents);
                @finfo_close($finfo);
                if (is_string($detected) && str_starts_with($detected, 'image/')) {
                    return $detected;
                }
            }
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => null,
        };
    }

    private function findFirstMetaStringValue(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            $candidate = $source[$key] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        foreach ($source as $value) {
            if (is_array($value)) {
                $nested = $this->findFirstMetaStringValue($value, $keys);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function normalizeElectronicSignatureValue(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        if ((str_starts_with($value, '{') && str_ends_with($value, '}')) || (str_starts_with($value, '[') && str_ends_with($value, ']'))) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $first = $this->extractFirstScalarValue($decoded);
                if ($first !== '') {
                    return $first;
                }
            }
        }

        return $value;
    }

    private function extractFirstScalarValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            foreach ($value as $nested) {
                $scalar = $this->extractFirstScalarValue($nested);
                if ($scalar !== '') {
                    return $scalar;
                }
            }
        }

        return '';
    }

    private function formatDisplayDate(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y H:i:s');
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function formatDisplayDateOnly(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function amountToSpanishWords(float $amount, string $currencyCode = 'PEN'): string
    {
        $safeAmount = max(0, $amount);
        $integerPart = (int) floor($safeAmount);
        $decimalPart = (int) round(($safeAmount - $integerPart) * 100);

        if ($decimalPart >= 100) {
            $integerPart += 1;
            $decimalPart = 0;
        }

        $currencyName = strtoupper(trim($currencyCode)) === 'USD' ? 'DOLARES' : 'SOLES';
        $decimalText = str_pad((string) $decimalPart, 2, '0', STR_PAD_LEFT);
        $words = strtoupper($this->numberToSpanishWords($integerPart));

        return $words . ' CON ' . $decimalText . '/100 ' . $currencyName;
    }

    private function numberToSpanishWords(int $number): string
    {
        if ($number === 0) {
            return 'cero';
        }

        $millions = intdiv($number, 1000000);
        $thousands = intdiv($number % 1000000, 1000);
        $hundreds = $number % 1000;
        $parts = [];

        if ($millions > 0) {
            if ($millions === 1) {
                $parts[] = 'un millon';
            } else {
                $parts[] = $this->numberToSpanishWords($millions) . ' millones';
            }
        }

        if ($thousands > 0) {
            if ($thousands === 1) {
                $parts[] = 'mil';
            } else {
                $parts[] = $this->convertThreeDigitsToSpanishWords($thousands) . ' mil';
            }
        }

        if ($hundreds > 0) {
            $parts[] = $this->convertThreeDigitsToSpanishWords($hundreds);
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }

    private function convertThreeDigitsToSpanishWords(int $number): string
    {
        $units = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve'];
        $teens = ['diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciseis', 'diecisiete', 'dieciocho', 'diecinueve'];
        $tens = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
        $hundreds = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

        if ($number === 0) {
            return '';
        }

        if ($number === 100) {
            return 'cien';
        }

        $c = intdiv($number, 100);
        $rest = $number % 100;
        $parts = [];

        if ($c > 0) {
            $parts[] = $hundreds[$c];
        }

        if ($rest >= 10 && $rest <= 19) {
            $parts[] = $teens[$rest - 10];
        } else {
            $d = intdiv($rest, 10);
            $u = $rest % 10;

            if ($d === 2 && $u > 0) {
                $parts[] = 'veinti' . $units[$u];
            } else {
                if ($d > 0) {
                    $parts[] = $tens[$d];
                }

                if ($u > 0) {
                    if ($d > 2) {
                        $parts[] = 'y ' . $units[$u];
                    } elseif ($d === 0) {
                        $parts[] = $units[$u];
                    }
                }
            }
        }

        return trim(implode(' ', $parts));
    }
}