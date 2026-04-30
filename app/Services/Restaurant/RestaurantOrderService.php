<?php

namespace App\Services\Restaurant;

use App\Application\UseCases\Sales\CreateCommercialDocumentUseCase;
use App\Contracts\VerticalSalesPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant vertical implementation of VerticalSalesPolicy.
 *
 * Adds restaurant-specific rules (mesa validation, kitchen status, table anchoring)
 * on top of the shared sales nucleus — without touching retail or any other vertical.
 *
 * Adding another vertical is as simple as:
 *   class PharmacySalesService  implements VerticalSalesPolicy { ... }
 *   class WorkshopSalesService  implements VerticalSalesPolicy { ... }
 */
class RestaurantOrderService implements VerticalSalesPolicy
{
    public function __construct(
        private CreateCommercialDocumentUseCase $createDocumentUseCase
    ) {}

    // =========================================================================
    // VerticalSalesPolicy implementation
    // =========================================================================

    /**
     * {@inheritdoc}
     *
     * Restaurant-specific: block orders to DISABLED mesas.
     */
    public function validateBeforeCreate(array $input, object $authUser, int $companyId): void
    {
        $tableId = isset($input['table_id']) ? (int) $input['table_id'] : null;

        if ($tableId !== null && $this->tablesStorageExists()) {
            $table = DB::table('restaurant.tables')
                ->where('id', $tableId)
                ->where('company_id', $companyId)
                ->first(['id', 'status']);

            if (!$table) {
                throw new \RuntimeException('Mesa no encontrada', 404);
            }

            if (strtoupper((string) $table->status) === 'DISABLED') {
                throw new \RuntimeException('La mesa está fuera de servicio y no puede recibir pedidos', 422);
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * Restaurant-specific: populate table_label, restaurant_order_status and
     * restaurant_table_id in metadata; force DRAFT + no payment at order time.
     */
    public function buildPayload(array $input, object $authUser, int $companyId): array
    {
        $tableId    = isset($input['table_id']) ? (int) $input['table_id'] : null;
        $tableLabel = trim((string) ($input['table_label'] ?? ''));
        $normalizedItems = array_map(function ($item) {
            if (!is_array($item)) {
                return [];
            }

            $qty = array_key_exists('qty', $item)
                ? $item['qty']
                : ($item['quantity'] ?? 0);

            return array_merge($item, [
                // Shared sales nucleus expects `qty`; restaurant UI sends `quantity`.
                'qty' => (float) $qty,
            ]);
        }, is_array($input['items'] ?? null) ? $input['items'] : []);

        // Prefer the stored table name if the caller did not provide a label
        if ($tableId !== null && $tableLabel === '' && $this->tablesStorageExists()) {
            $table = DB::table('restaurant.tables')
                ->where('id', $tableId)
                ->where('company_id', $companyId)
                ->first(['name']);
            if ($table) {
                $tableLabel = trim((string) $table->name);
            }
        }

        return [
            'document_kind'     => 'SALES_ORDER',
            'document_kind_id'  => $this->resolveDocumentKindId('SALES_ORDER'),
            'series'            => trim((string) ($input['series'] ?? '')),
            'issue_at'          => trim((string) ($input['issue_at'] ?? now('America/Lima')->toDateString())),
            'due_at'            => null,
            'customer_id'       => (int) ($input['customer_id']),
            'currency_id'       => (int) ($input['currency_id']),
            'payment_method_id' => (int) ($input['payment_method_id']),
            'notes'             => trim((string) ($input['notes'] ?? '')),
            // Restaurant orders start as DRAFT; kitchen is notified via comanda board
            'status'            => 'DRAFT',
            'metadata'          => [
                'table_label'             => $tableLabel !== '' ? $tableLabel : null,
                'restaurant_order_status' => 'PENDING',
                'restaurant_table_id'     => $tableId,
            ],
            'items'    => $normalizedItems,
            'payments' => [], // Payment is settled at checkout, not at order creation
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Restaurant-specific: mark the mesa as OCCUPIED after the order is persisted.
     */
    public function onDocumentCreated(array $document, int $companyId): void
    {
        $tableId = $document['metadata']['restaurant_table_id'] ?? null;

        if ($tableId !== null && $this->tablesStorageExists()) {
            DB::table('restaurant.tables')
                ->where('id', (int) $tableId)
                ->where('company_id', $companyId)
                ->where('status', '!=', 'DISABLED')
                ->update([
                    'status'     => 'OCCUPIED',
                    'updated_at' => now(),
                ]);
        }
    }

    // =========================================================================
    // Public entry-point used by the controller
    // =========================================================================

    /**
     * Orchestrates the vertical policy lifecycle for a new restaurant order:
     * validate → build payload → create via nucleus → post-create hook.
     */
    public function createOrder(
        object $authUser,
        int $companyId,
        ?int $branchId,
        ?int $warehouseId,
        array $input
    ): array {
        $this->validateBeforeCreate($input, $authUser, $companyId);

        $payload = $this->buildPayload($input, $authUser, $companyId);

        // Delegate entirely to the shared transactional nucleus
        $document = $this->createDocumentUseCase->execute(
            $authUser,
            $payload,
            $companyId,
            $branchId,
            $warehouseId,
            null  // cash register assigned at checkout, not at order time
        );

        $this->onDocumentCreated($document, $companyId);

        return $document;
    }

    public function fetchOrders(
        int $companyId,
        ?int $branchId,
        string $kitchenStatus,
        string $search,
        int $page,
        int $perPage,
        bool $includeItems = false,
        bool $includeMeta = false
    ): array {
        $base = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->where('d.company_id', $companyId)
            // Issued orders are final for comanda operations and should not stay in the kitchen board.
            ->whereNotIn('d.status', ['VOID', 'CANCELED', 'ISSUED'])
            ->when($branchId !== null, fn ($q) => $q->where('d.branch_id', $branchId))
            ->when($kitchenStatus !== '', fn ($q) => $q->whereRaw(
                "UPPER(COALESCE(d.metadata->>'restaurant_order_status', 'PENDING')) = ?",
                [strtoupper($kitchenStatus)]
            ))
            ->when($search !== '', function ($q) use ($search) {
                $needle = '%' . mb_strtolower($search) . '%';
                $q->where(function ($n) use ($needle) {
                    $n->whereRaw("LOWER(COALESCE(d.series,'')) LIKE ?", [$needle])
                      ->orWhereRaw("CAST(d.number AS TEXT) LIKE ?", [$needle])
                      ->orWhereRaw("LOWER(COALESCE(c.legal_name, c.first_name || ' ' || c.last_name,'')) LIKE ?", [$needle])
                      ->orWhereRaw("LOWER(COALESCE(d.metadata->>'table_label','')) LIKE ?", [$needle]);
                });
            });

        $this->applyDocumentKindFilter($base, 'd.document_kind', 'd.document_kind_id', 'SALES_ORDER');

        $total = null;
        if ($includeMeta) {
            $total = (clone $base)->count();
        }

        $rows = $base
            ->select([
                'd.id',
                'd.branch_id',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.status',
                'd.total',
                'd.notes',
                'd.customer_id',
                DB::raw("COALESCE(c.legal_name, TRIM(COALESCE(c.first_name,'') || ' ' || COALESCE(c.last_name,''))) AS customer_name"),
                DB::raw("COALESCE(d.metadata->>'restaurant_order_status','PENDING') AS kitchen_status"),
                DB::raw("COALESCE(d.metadata->>'table_label','') AS table_label"),
                DB::raw("(d.metadata->>'restaurant_table_id') AS table_id"),
            ])
            ->orderByDesc('d.id')
            ->forPage($page, $perPage)
            ->get();

        // Enrich with per-order aggregates (lightweight for initial screen load)
        $ids = $rows->pluck('id')->all();
        $aggregateMap = [];
        $itemMap = [];
        if (!empty($ids)) {
            DB::table('sales.commercial_document_items')
                ->whereIn('document_id', $ids)
                ->selectRaw('document_id, COUNT(*) as line_count, COALESCE(SUM(qty), 0) as total_qty')
                ->groupBy('document_id')
                ->get()
                ->each(function ($row) use (&$aggregateMap) {
                    $aggregateMap[(int) $row->document_id] = [
                        'line_count' => (int) $row->line_count,
                        'total_qty' => (float) $row->total_qty,
                    ];
                });

            if ($includeItems) {
            DB::table('sales.commercial_document_items')
                ->whereIn('document_id', $ids)
                ->orderBy('document_id')
                ->orderBy('line_no')
                ->get([
                    'document_id',
                    'line_no',
                    'product_id',
                    'unit_id',
                    'description',
                    'qty',
                    'unit_price',
                    'tax_total',
                    'subtotal',
                    'total',
                ])
                ->each(function ($row) use (&$itemMap) {
                    $documentId = (int) $row->document_id;
                    $itemMap[$documentId][] = [
                        'line_no'    => (int) $row->line_no,
                        'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                        'unit_id'    => $row->unit_id !== null ? (int) $row->unit_id : null,
                        'description' => (string) $row->description,
                        'quantity'   => (float) $row->qty,
                        'unit_price' => (float) $row->unit_price,
                        'tax_total'  => (float) $row->tax_total,
                        'subtotal'   => (float) $row->subtotal,
                        'total'      => (float) $row->total,
                    ];
                });
            }
        }

        $data = $rows->map(function ($row) use ($itemMap, $aggregateMap, $includeItems) {
            $arr   = (array) $row;
            $agg = $aggregateMap[(int) $row->id] ?? ['line_count' => 0, 'total_qty' => 0.0];
            $arr['line_count'] = (int) $agg['line_count'];
            $arr['total_qty']  = (float) $agg['total_qty'];
            if ($includeItems) {
                $arr['items'] = $itemMap[(int) $row->id] ?? [];
            }
            return $arr;
        });

        return [
            'data' => $data,
            'meta' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $includeMeta ? (int) $total : count($data),
                'last_page' => $includeMeta ? (int) ceil(max(1, (int) $total) / $perPage) : 1,
            ],
        ];
    }

    public function fetchOrderDetail(int $companyId, int $orderId): array
    {
        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->where('d.company_id', $companyId)
            ->where('d.id', $orderId)
            ->whereNotIn('d.status', ['VOID', 'CANCELED', 'ISSUED'])
            ->select([
                'd.id',
                'd.branch_id',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.status',
                'd.total',
                'd.notes',
                'd.customer_id',
                DB::raw("COALESCE(c.legal_name, TRIM(COALESCE(c.first_name,'') || ' ' || COALESCE(c.last_name,''))) AS customer_name"),
                DB::raw("COALESCE(d.metadata->>'restaurant_order_status','PENDING') AS kitchen_status"),
                DB::raw("COALESCE(d.metadata->>'table_label','') AS table_label"),
                DB::raw("(d.metadata->>'restaurant_table_id') AS table_id"),
            ]);

        $this->applyDocumentKindFilter($query, 'd.document_kind', 'd.document_kind_id', 'SALES_ORDER');

        $row = $query->first();

        if (!$row) {
            throw new \RuntimeException('Pedido no encontrado', 404);
        }

        $items = DB::table('sales.commercial_document_items')
            ->where('document_id', $orderId)
            ->orderBy('line_no')
            ->get([
                'line_no',
                'product_id',
                'unit_id',
                'description',
                'qty',
                'unit_price',
                'tax_total',
                'subtotal',
                'total',
            ])
            ->map(function ($item) {
                return [
                    'line_no' => (int) $item->line_no,
                    'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                    'unit_id' => $item->unit_id !== null ? (int) $item->unit_id : null,
                    'description' => (string) $item->description,
                    'quantity' => (float) $item->qty,
                    'unit_price' => (float) $item->unit_price,
                    'tax_total' => (float) $item->tax_total,
                    'subtotal' => (float) $item->subtotal,
                    'total' => (float) $item->total,
                ];
            })
            ->all();

        $arr = (array) $row;
        $arr['items'] = $items;
        $arr['line_count'] = count($items);
        $arr['total_qty'] = array_sum(array_column($items, 'quantity'));

        return $arr;
    }

    // =========================================================================
    // Checkout: convert SALES_ORDER → INVOICE or RECEIPT
    // Releases the mesa to AVAILABLE after successful conversion.
    // =========================================================================

    /**
     * Convert a restaurant SALES_ORDER to an invoiceable document (INVOICE or RECEIPT).
     *
     * Steps:
     *  1. Load and validate the source order (must belong to company, must be SALES_ORDER).
     *  2. Find or use the provided series for the target document kind.
     *  3. Clone items from the source order into the new payload.
     *  4. Call the shared nucleus (CreateCommercialDocumentUseCase).
     *  5. Release the mesa to AVAILABLE.
     *
     * @param  int     $orderId           sales.commercial_documents.id
     * @param  int     $companyId
     * @param  object  $authUser
     * @param  string  $targetDocumentKind  'INVOICE' or 'RECEIPT'
     * @param  string|null $series         explicit series; auto-resolved when null
     * @param  int|null    $cashRegisterId
     * @param  int|null    $paymentMethodId override payment method; keeps source value if null
     * @param  string|null $notes          override notes
     * @throws \RuntimeException
     */
    public function checkoutOrder(
        int $orderId,
        int $companyId,
        object $authUser,
        string $targetDocumentKind = 'RECEIPT',
        ?string $series = null,
        ?int $cashRegisterId = null,
        ?int $paymentMethodId = null,
        ?string $notes = null
    ): array {
        $allowed = ['INVOICE', 'RECEIPT', 'SALES_ORDER'];
        if (!in_array(strtoupper($targetDocumentKind), $allowed, true)) {
            throw new \RuntimeException('Tipo de documento no válido para cobro. Use SALES_ORDER, INVOICE o RECEIPT.', 422);
        }

        $targetDocumentKind = strtoupper($targetDocumentKind);

        // ── 1. Load source order ─────────────────────────────────────────────
        $source = DB::table('sales.commercial_documents')
            ->where('id', $orderId)
            ->where('company_id', $companyId)
            ->first();

        if (!$source) {
            throw new \RuntimeException('Pedido no encontrado', 404);
        }

        if (!$this->documentMatchesKind($source, 'SALES_ORDER')) {
            throw new \RuntimeException('Solo se puede cobrar un pedido de venta (SALES_ORDER)', 422);
        }

        if (in_array((string) $source->status, ['VOID', 'CANCELED'], true)) {
            throw new \RuntimeException('No se puede cobrar un pedido anulado o cancelado', 422);
        }

        $sourceBranchId = $source->branch_id !== null ? (int) $source->branch_id : null;
        $sellerToCashierEnabled = $this->isCommerceFeatureEnabledForContextWithDefault(
            $companyId,
            $sourceBranchId,
            'SALES_SELLER_TO_CASHIER',
            false
        );

        // Flag only sets the default in the UI; all three document kinds are always allowed.
        // $sellerToCashierEnabled is retained for future logic (e.g. audit trails) but no longer restricts.
        unset($sellerToCashierEnabled);

        if ($targetDocumentKind === 'SALES_ORDER') {
            $alreadyIssued = strtoupper((string) ($source->status ?? '')) === 'ISSUED';
            if ($alreadyIssued) {
                return [
                    'id' => (int) $source->id,
                    'document_kind' => 'SALES_ORDER',
                    'series' => (string) $source->series,
                    'number' => (int) $source->number,
                    'total' => (float) $source->total,
                    'status' => 'ISSUED',
                    'pending_cashier_checkout' => true,
                ];
            }

            $sourceMetadata = [];
            if (isset($source->metadata) && $source->metadata !== null) {
                $decoded = json_decode((string) $source->metadata, true);
                if (is_array($decoded)) {
                    $sourceMetadata = $decoded;
                }
            }

            $requestMetadata = array_merge($sourceMetadata, [
                'restaurant_checkout_request' => [
                    'requested_by' => (int) ($authUser->id ?? 0),
                    'requested_at' => now('America/Lima')->toIso8601String(),
                    'mode' => 'SELLER_TO_CASHIER',
                ],
                'restaurant_order_status' => 'CHECKED_OUT',
                'pending_cashier_checkout' => true,
                'checkout_document_id' => (int) $source->id,
                'checkout_document_kind' => 'SALES_ORDER',
                'checkout_document_number' => (string) $source->series . '-' . (string) $source->number,
                'checked_out_at' => now('America/Lima')->toIso8601String(),
                'conversion_origin' => 'RESTAURANT_REQUEST',
            ]);

            DB::table('sales.commercial_documents')
                ->where('id', $orderId)
                ->where('company_id', $companyId)
                ->update([
                    'status' => 'ISSUED',
                    'notes' => $notes ?? $source->notes,
                    'metadata' => json_encode($requestMetadata, JSON_UNESCAPED_UNICODE),
                    'updated_by' => (int) ($authUser->id ?? 0),
                    'updated_at' => now(),
                ]);

            return [
                'id' => (int) $source->id,
                'document_kind' => 'SALES_ORDER',
                'series' => (string) $source->series,
                'number' => (int) $source->number,
                'total' => (float) $source->total,
                'status' => 'ISSUED',
                'pending_cashier_checkout' => true,
            ];
        }

        // Guard: if any non-void target exists for this source, avoid double billing.
        $alreadyQuery = DB::table('sales.commercial_documents')
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['VOID', 'CANCELED'])
            ->whereRaw("COALESCE((metadata->>'source_document_id')::BIGINT, 0) = ?", [$orderId]);

        $already = $alreadyQuery->exists();

        if ($already) {
            throw new \RuntimeException('Este pedido ya fue cobrado', 409);
        }

        // ── 2. Resolve series ────────────────────────────────────────────────
        if ($series === null || trim($series) === '') {
            $candidateSeriesQuery = DB::table('sales.series_numbers')
                ->where('company_id', $companyId)
                ->where('is_enabled', true)
                ->when($source->branch_id !== null, fn ($q) => $q->where(function ($n) use ($source) {
                    $n->where('branch_id', (int) $source->branch_id)->orWhereNull('branch_id');
                }))
                ->orderByDesc('branch_id')
                ->orderBy('series');

            $this->applyDocumentKindFilter($candidateSeriesQuery, 'document_kind', 'document_kind_id', $targetDocumentKind);

            $candidateSeries = $candidateSeriesQuery->value('series');

            if (!$candidateSeries) {
                throw new \RuntimeException('No existe serie habilitada para ' . $targetDocumentKind, 422);
            }

            $series = $candidateSeries;
        }

        // ── 3. Clone items ───────────────────────────────────────────────────
        $sourceItems = DB::table('sales.commercial_document_items')
            ->where('document_id', $orderId)
            ->orderBy('line_no')
            ->get();

        if ($sourceItems->isEmpty()) {
            throw new \RuntimeException('El pedido no tiene líneas para facturar', 422);
        }

        $itemsPayload = $sourceItems->map(function ($item) {
            return [
                'line_no'                    => (int) $item->line_no,
                'product_id'                 => $item->product_id !== null ? (int) $item->product_id : null,
                'unit_id'                    => $item->unit_id !== null ? (int) $item->unit_id : null,
                'tax_category_id'            => $item->tax_category_id !== null ? (int) $item->tax_category_id : null,
                'description'                => (string) $item->description,
                'qty'                        => (float) $item->qty,
                'qty_base'                   => (float) $item->qty_base,
                'conversion_factor'          => (float) $item->conversion_factor,
                'base_unit_price'            => (float) $item->base_unit_price,
                'unit_price'                 => (float) $item->unit_price,
                'unit_cost'                  => (float) $item->unit_cost,
                'wholesale_discount_percent' => (float) $item->wholesale_discount_percent,
                'price_source'               => $item->price_source ?: 'MANUAL',
                'discount_total'             => (float) $item->discount_total,
                'tax_total'                  => (float) $item->tax_total,
                'subtotal'                   => (float) $item->subtotal,
                'total'                      => (float) $item->total,
                'metadata'                   => null,
                'lots'                       => null,
            ];
        })->values()->all();

        // ── 4. Build payload for nucleus ─────────────────────────────────────
        $sourceMetadata = [];
        if (isset($source->metadata) && $source->metadata !== null) {
            $decoded = json_decode((string) $source->metadata, true);
            if (is_array($decoded)) {
                $sourceMetadata = $decoded;
            }
        }

        $checkoutPayload = [
            'document_kind'     => $targetDocumentKind,
            'document_kind_id'  => $this->resolveDocumentKindId($targetDocumentKind),
            'series'            => $series,
            'issue_at'          => now('America/Lima')->toDateString(),
            'due_at'            => null,
            'customer_id'       => (int) $source->customer_id,
            'currency_id'       => (int) $source->currency_id,
            'payment_method_id' => $paymentMethodId ?? ($source->payment_method_id !== null ? (int) $source->payment_method_id : null),
            'notes'             => $notes ?? $source->notes,
            'status'            => 'ISSUED',
            'metadata'          => array_merge($sourceMetadata, [
                'source_document_id'     => $orderId,
                'source_document_kind'   => 'SALES_ORDER',
                'source_document_number' => (string) $source->series . '-' . (string) $source->number,
                'conversion_origin'      => 'RESTAURANT_CHECKOUT',
                // Restaurant menu items should not trigger product-stock discount again at checkout.
                // Real inventory consumption happens via recipe depletion on kitchen flow when enabled.
                'stock_already_discounted' => true,
            ]),
            'items'    => $itemsPayload,
            'payments' => [],
        ];

        $resolvedPaymentMethodId = $checkoutPayload['payment_method_id'] !== null
            ? (int) $checkoutPayload['payment_method_id']
            : null;

        if ($resolvedPaymentMethodId === null || $resolvedPaymentMethodId <= 0) {
            throw new \RuntimeException('Selecciona un metodo de pago para registrar el cobro.', 422);
        }

        $checkoutPayload['payment_method_id'] = $resolvedPaymentMethodId;
        $checkoutPayload['payments'] = [[
            'payment_method_id' => $resolvedPaymentMethodId,
            'amount' => round((float) $source->total, 2),
            'status' => 'PAID',
            'paid_at' => now('America/Lima')->toIso8601String(),
            'notes' => 'Cobro desde comandas',
        ]];

        $document = $this->createDocumentUseCase->execute(
            $authUser,
            $checkoutPayload,
            $companyId,
            $source->branch_id !== null ? (int) $source->branch_id : null,
            $source->warehouse_id !== null ? (int) $source->warehouse_id : null,
            $cashRegisterId
        );

        // Mark source order as final to prevent re-checkout from Comandas.
        $finalSourceMetadata = array_merge($sourceMetadata, [
            'restaurant_order_status' => 'CHECKED_OUT',
            'pending_cashier_checkout' => false,
            'checkout_document_id' => (int) ($document['id'] ?? 0),
            'checkout_document_kind' => (string) ($document['document_kind'] ?? $targetDocumentKind),
            'checkout_document_number' => (string) (($document['series'] ?? '') . '-' . ($document['number'] ?? '')),
            'checked_out_at' => now('America/Lima')->toIso8601String(),
        ]);

        DB::table('sales.commercial_documents')
            ->where('id', $orderId)
            ->where('company_id', $companyId)
            ->update([
                'status' => 'ISSUED',
                'metadata' => json_encode($finalSourceMetadata, JSON_UNESCAPED_UNICODE),
                'updated_by' => (int) ($authUser->id ?? 0),
                'updated_at' => now(),
            ]);

        // ── 5. Release mesa ──────────────────────────────────────────────────
        $tableId = $sourceMetadata['restaurant_table_id'] ?? null;
        if ($tableId !== null && $this->tablesStorageExists()) {
            DB::table('restaurant.tables')
                ->where('id', (int) $tableId)
                ->where('company_id', $companyId)
                ->where('status', '!=', 'DISABLED')
                ->update(['status' => 'AVAILABLE', 'updated_at' => now()]);
        }

        return $document;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function tablesStorageExists(): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'restaurant')
            ->where('table_name', 'tables')
            ->exists();
    }

    private function applyDocumentKindFilter($query, string $documentKindColumn, string $documentKindIdColumn, string $documentKindCode): void
    {
        $documentKindId = $this->resolveDocumentKindId($documentKindCode);
        $aliases = $this->resolveDocumentKindAliases($documentKindCode);

        $query->where(function ($nested) use ($documentKindColumn, $documentKindIdColumn, $documentKindId, $aliases) {
            if ($documentKindId !== null) {
                $nested->where($documentKindIdColumn, $documentKindId)
                    ->orWhere(function ($legacy) use ($documentKindColumn, $documentKindIdColumn, $aliases) {
                        $legacy->whereNull($documentKindIdColumn)
                            ->whereIn(DB::raw('UPPER(TRIM(COALESCE(' . $documentKindColumn . ", '')))"), $aliases);
                    });

                return;
            }

            $nested->whereIn(DB::raw('UPPER(TRIM(COALESCE(' . $documentKindColumn . ", '')))"), $aliases);
        });
    }

    private function documentMatchesKind(object $document, string $documentKindCode): bool
    {
        $documentKindId = $this->resolveDocumentKindId($documentKindCode);
        if ($documentKindId !== null && isset($document->document_kind_id) && $document->document_kind_id !== null) {
            return (int) $document->document_kind_id === $documentKindId;
        }

        return in_array(
            strtoupper(trim((string) ($document->document_kind ?? ''))),
            $this->resolveDocumentKindAliases($documentKindCode),
            true
        );
    }

    private function resolveDocumentKindId(string $documentKindCode): ?int
    {
        $normalizedCode = strtoupper(trim($documentKindCode));
        if ($normalizedCode === '') {
            return null;
        }

        $id = DB::table('sales.document_kinds')
            ->whereRaw('UPPER(code) = ?', [$normalizedCode])
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function resolveDocumentKindAliases(string $documentKindCode): array
    {
        $normalizedCode = strtoupper(trim($documentKindCode));
        if ($normalizedCode === '') {
            return [];
        }

        $aliases = [$normalizedCode];
        $row = DB::table('sales.document_kinds')
            ->select('code', 'label')
            ->whereRaw('UPPER(code) = ?', [$normalizedCode])
            ->first();

        if ($row) {
            $aliases[] = strtoupper(trim((string) ($row->code ?? '')));
            $aliases[] = strtoupper(trim((string) ($row->label ?? '')));
        }

        return array_values(array_unique(array_filter($aliases, static fn ($value) => $value !== '')));
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

}
