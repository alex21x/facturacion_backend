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
        int $perPage
    ): array {
        $base = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->where('d.company_id', $companyId)
            ->where('d.document_kind', 'SALES_ORDER')
            ->whereNotIn('d.status', ['VOID', 'CANCELED'])
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

        $total = (clone $base)->count();

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

        // Enrich with items — single query, counts derived in-memory
        $ids = $rows->pluck('id')->all();
        $itemMap = [];
        if (!empty($ids)) {
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

        $data = $rows->map(function ($row) use ($itemMap) {
            $arr   = (array) $row;
            $items = $itemMap[(int) $row->id] ?? [];
            $arr['items']      = $items;
            $arr['line_count'] = count($items);
            $arr['total_qty']  = array_sum(array_column($items, 'quantity'));
            return $arr;
        });

        return [
            'data' => $data,
            'meta' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) ceil(max(1, $total) / $perPage),
            ],
        ];
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
        $allowed = ['INVOICE', 'RECEIPT'];
        if (!in_array(strtoupper($targetDocumentKind), $allowed, true)) {
            throw new \RuntimeException('Tipo de documento no válido para cobro. Use INVOICE o RECEIPT.', 422);
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

        if ((string) $source->document_kind !== 'SALES_ORDER') {
            throw new \RuntimeException('Solo se puede cobrar un pedido de venta (SALES_ORDER)', 422);
        }

        if (in_array((string) $source->status, ['VOID', 'CANCELED'], true)) {
            throw new \RuntimeException('No se puede cobrar un pedido anulado o cancelado', 422);
        }

        // Guard: check if already converted to avoid double billing
        $already = DB::table('sales.commercial_documents')
            ->where('company_id', $companyId)
            ->where('document_kind', $targetDocumentKind)
            ->whereNotIn('status', ['VOID', 'CANCELED'])
            ->whereRaw("COALESCE((metadata->>'source_document_id')::BIGINT, 0) = ?", [$orderId])
            ->exists();

        if ($already) {
            throw new \RuntimeException('Este pedido ya fue cobrado', 409);
        }

        // ── 2. Resolve series ────────────────────────────────────────────────
        if ($series === null || trim($series) === '') {
            $candidateSeries = DB::table('sales.series_numbers')
                ->where('company_id', $companyId)
                ->where('document_kind', $targetDocumentKind)
                ->where('is_enabled', true)
                ->when($source->branch_id !== null, fn ($q) => $q->where(function ($n) use ($source) {
                    $n->where('branch_id', (int) $source->branch_id)->orWhereNull('branch_id');
                }))
                ->orderByDesc('branch_id')
                ->orderBy('series')
                ->value('series');

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
                'stock_already_discounted' => false,
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
}
