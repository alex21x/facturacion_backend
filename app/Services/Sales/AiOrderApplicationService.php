<?php

namespace App\Services\Sales;

use App\Infrastructure\Gateways\OpenRouterGateway;
use App\Services\Inventory\InventoryProductService;
use RuntimeException;

class AiOrderApplicationService
{
    public function __construct(
        private OpenRouterGateway $gateway,
        private CustomerQueryService $customerQueryService,
        private InventoryProductService $inventoryProductService,
        private SalesLookupService $salesLookupService,
        private SalesDocumentApplicationService $salesDocumentApplicationService,
    ) {
    }

    public function execute(object $authUser, int $companyId, array $input): array
    {
        $intent = $this->gateway->completeJson(
            'Eres un asistente operativo de facturación. Devuelve exclusivamente JSON. '
            . 'Para crear pedidos usa {"action":"CREATE_SALES_ORDER","customer":"texto","items":[{"name":"texto","qty":numero}]}. '
            . 'Para reportes de ventas usa {"action":"SALES_REPORT","date_from":"YYYY-MM-DD","date_to":"YYYY-MM-DD"}. '
            . 'Solo identifica la intención y los parámetros explícitos: no inventes IDs, precios, clientes, productos ni fechas. '
            . 'Si faltan datos, usa cadenas vacías o listas vacías.',
            trim((string) $input['prompt'])
        );

        $action = strtoupper(trim((string) ($intent['action'] ?? '')));
        if ($action === 'SALES_REPORT') {
            return $this->buildSalesReport($companyId, $input, $intent);
        }

        if ($action !== 'CREATE_SALES_ORDER') {
            throw new RuntimeException('No pude identificar una acción compatible. Puedes pedir crear un pedido o generar un reporte de ventas por fechas.');
        }

        return $this->createSalesOrder($authUser, $companyId, $input, $intent);
    }

    private function createSalesOrder(object $authUser, int $companyId, array $input, array $intent): array
    {
        $customerTerm = trim((string) ($intent['customer'] ?? ''));
        $rawItems = is_array($intent['items'] ?? null) ? $intent['items'] : [];
        if ($customerTerm === '' || $rawItems === []) {
            throw new RuntimeException('Indica el cliente y al menos un ítem para crear el pedido.');
        }

        $customers = $this->customerQueryService->listCustomers($companyId, $customerTerm, 1, 10, true, false);
        $customer = $this->resolveUniqueMatch($customers, $customerTerm, 'cliente');
        $products = [];
        foreach ($rawItems as $rawItem) {
            $name = trim((string) ($rawItem['name'] ?? ''));
            $qty = (float) ($rawItem['qty'] ?? 1);
            if ($name === '' || $qty <= 0) {
                throw new RuntimeException('Cada ítem debe tener nombre y cantidad válida.');
            }

            $matches = $this->inventoryProductService->listProducts($companyId, $name, 1, 10, true);
            $product = $this->resolveUniqueMatch($matches, $name, 'producto');
            $products[] = ['product' => $product, 'qty' => $qty];
        }

        $branchId = $this->resolveOptionalId($input['branch_id'] ?? ($authUser->branch_id ?? null));
        $warehouseId = $this->resolveOptionalId($input['warehouse_id'] ?? null);
        if ($warehouseId === null && $branchId !== null) {
            $warehouseId = $this->salesLookupService->resolveDefaultWarehouseIdByBranchScope($companyId, $branchId);
        }

        $seriesRows = $this->salesLookupService->listSeriesNumbers($companyId, $branchId, $warehouseId, true, null, 'SALES_ORDER');
        if ($seriesRows->isEmpty()) {
            $seriesRows = $this->salesLookupService->listSeriesNumbers($companyId, $branchId, $warehouseId, true, null, null);
        }
        $series = $seriesRows->first();
        $currency = $this->salesLookupService->listActiveCurrencies()->first();
        if (!$series || !$currency) {
            throw new RuntimeException('No hay una serie de pedidos o moneda activa configurada.');
        }

        $taxCategory = collect($this->salesLookupService->resolveTaxCategoriesRows($companyId))->first();
        $taxRate = (float) data_get($taxCategory, 'rate_percent', 0);
        $taxCategoryId = (int) data_get($taxCategory, 'id', 0);
        $items = array_map(function (array $row) use ($taxCategoryId, $taxRate): array {
            $product = $row['product'];
            $qty = (float) $row['qty'];
            $unitPrice = (float) data_get($product, 'sale_price', 0);
            return [
                'product_id' => (int) data_get($product, 'id'),
                'unit_id' => (int) data_get($product, 'unit_id'),
                'tax_category_id' => $taxCategoryId > 0 ? $taxCategoryId : null,
                'description' => (string) data_get($product, 'name'),
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => round($qty * $unitPrice, 2),
                'tax_total' => round($qty * $unitPrice * $taxRate / 100, 2),
                'total' => round($qty * $unitPrice * (1 + $taxRate / 100), 2),
            ];
        }, $products);

        $payload = [
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'document_kind' => 'SALES_ORDER',
            'series' => (string) $series->series,
            'customer_id' => (int) data_get($customer, 'id'),
            'currency_id' => (int) $currency->id,
            'items' => $items,
            'status' => 'DRAFT',
            'metadata' => ['created_by' => 'AI_AGENT'],
        ];

        $document = $this->salesDocumentApplicationService->createCommercialDocument(
            $authUser,
            $companyId,
            $payload,
            $branchId
        );

        return [
            'action' => 'CREATE_SALES_ORDER',
            'intent' => $intent,
            'document' => $document,
            'customer' => $customer,
            'items' => $items,
        ];
    }

    private function buildSalesReport(int $companyId, array $input, array $intent): array
    {
        $dateFrom = trim((string) ($intent['date_from'] ?? ''));
        $dateTo = trim((string) ($intent['date_to'] ?? ''));
        if (!$this->isDate($dateFrom) || !$this->isDate($dateTo) || $dateFrom > $dateTo) {
            throw new RuntimeException('Indica un rango de fechas válido para el reporte de ventas.');
        }

        $documents = $this->salesLookupService->listCommercialDocumentsForExport($companyId, [
            'branch_id' => $this->resolveOptionalId($input['branch_id'] ?? null),
            'warehouse_id' => $this->resolveOptionalId($input['warehouse_id'] ?? null),
            'document_kind' => 'INVOICE,RECEIPT',
            'issue_date_from' => $dateFrom,
            'issue_date_to' => $dateTo,
        ], 5000);

        $validDocuments = $documents->filter(fn ($document) => !in_array(strtoupper((string) data_get($document, 'status')), ['VOID', 'CANCELED'], true));
        $dailySales = $validDocuments
            ->groupBy(function ($document): string {
                $issueAt = data_get($document, 'issue_at');

                return $issueAt ? (new \DateTimeImmutable((string) $issueAt))->format('Y-m-d') : 'SIN_FECHA';
            })
            ->map(function ($dayDocuments, string $date): array {
                return [
                    'date' => $date,
                    'documents_count' => $dayDocuments->count(),
                    'total_amount' => round($dayDocuments->sum(fn ($document) => (float) data_get($document, 'total', 0)), 2),
                ];
            })
            ->sortKeys()
            ->values();

        return [
            'action' => 'SALES_REPORT',
            'intent' => $intent,
            'report' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'documents_count' => $validDocuments->count(),
                'total_amount' => round($validDocuments->sum(fn ($document) => (float) data_get($document, 'total', 0)), 2),
                'daily_sales' => $dailySales,
                'documents' => $validDocuments->take(100)->values(),
            ],
        ];
    }

    private function resolveUniqueMatch(array $matches, string $term, string $label): array|object
    {
        if (count($matches) !== 1) {
            $names = collect($matches)->map(fn ($row) => (string) data_get($row, 'name', ''))->filter()->take(5)->implode(', ');
            $suffix = $names !== '' ? ' Opciones: ' . $names . '.' : '';
            throw new RuntimeException("No se pudo identificar un único {$label} para \"{$term}\"." . $suffix);
        }

        return $matches[0];
    }

    private function resolveOptionalId(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
