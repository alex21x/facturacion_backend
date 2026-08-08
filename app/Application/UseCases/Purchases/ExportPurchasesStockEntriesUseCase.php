<?php

namespace App\Application\UseCases\Purchases;

use App\Application\Commands\Purchases\ExportPurchasesStockEntriesCommand;
use App\Domain\Purchases\Repositories\PurchasesStockEntryRepositoryInterface;
use Carbon\Carbon;

class ExportPurchasesStockEntriesUseCase
{
    public function __construct(private PurchasesStockEntryRepositoryInterface $repository)
    {
    }

    public function execute(ExportPurchasesStockEntriesCommand $command): array
    {
        $entries = $this->repository->listForExport(
            $command->companyId,
            $command->branchId,
            $command->filters(),
            $command->includeItems
        );

        if ($command->format === 'json') {
            return [
                'format' => 'json',
                'data' => $entries,
            ];
        }

        $extension = $command->format === 'xlsx' ? 'xlsx' : 'csv';

        return [
            'format' => $extension,
            'filename' => 'reporte_compras_' . Carbon::now('America/Lima')->format('Y-m-d_h-i-s_A') . '.' . $extension,
            'headers' => ['ID', 'Tipo', 'Referencia', 'Referencia_Proveedor', 'FechaHora', 'Almacen', 'Cantidad_Items', 'Cantidad_Total', 'Descuento_Item', 'Descuento_Global', 'Descuento_Total', 'Importe_Total', 'Metodo_Pago', 'Notas'],
            'rows' => array_map(fn ($entry) => $this->mapTabularRow($entry), $entries),
        ];
    }

    private function mapTabularRow(object $entry): array
    {
        $metadata = [];
        if (isset($entry->metadata) && $entry->metadata !== null && $entry->metadata !== '') {
            $decoded = is_string($entry->metadata) ? json_decode($entry->metadata, true) : $entry->metadata;
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $itemDiscount = (float) ($metadata['item_discount_total'] ?? 0);
        $globalDiscount = (float) ($metadata['discount_total'] ?? 0);

        return [
            $entry->id,
            $this->entryTypeLabel((string) $entry->entry_type),
            $entry->reference_no ?? '',
            $entry->supplier_reference ?? '',
            $this->formatDateTime($entry->issue_at ?? null),
            $entry->warehouse_name ?? $entry->warehouse_code ?? '',
            $entry->total_items,
            number_format((float) $entry->total_qty, 3, '.', ''),
            number_format($itemDiscount, 2, '.', ''),
            number_format($globalDiscount, 2, '.', ''),
            number_format($itemDiscount + $globalDiscount, 2, '.', ''),
            number_format((float) $entry->total_amount, 2, '.', ''),
            $entry->payment_method ?? '',
            $entry->notes ?? '',
        ];
    }

    private function entryTypeLabel(string $entryType): string
    {
        return match ($entryType) {
            'PURCHASE' => 'Compra',
            'PURCHASE_ORDER' => 'Orden de compra',
            'NON_TAX_IN' => 'Ingreso no tributario',
            'NON_TAX_OUT' => 'Salida no tributaria',
            default => 'Ajuste',
        };
    }

    private function formatDateTime($raw): string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return '';
        }

        try {
            return Carbon::parse($value, 'America/Lima')
                ->setTimezone('America/Lima')
                ->format('d/m/Y H:i:s');
        } catch (\Throwable $e) {
            return $value;
        }
    }
}
