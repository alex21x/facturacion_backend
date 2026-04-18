<?php

namespace App\Infrastructure\Repositories\Purchases;

use App\Domain\Purchases\Repositories\PurchasesStockEntryRepositoryInterface;
use App\Services\AppConfig\CompanyIgvRateService;
use Illuminate\Support\Facades\DB;

class PurchasesStockEntryRepository implements PurchasesStockEntryRepositoryInterface
{
	public function __construct(private CompanyIgvRateService $companyIgvRateService)
	{
	}

	public function listPaginated(int $companyId, $branchId, array $filters, int $page, int $perPage): array
	{
		$query = $this->buildBaseEntriesQuery($companyId, $branchId, $filters);
		$total = $query->count();

		$offset = ($page - 1) * $perPage;
		$entries = $query->orderBy('se.id', 'desc')
			->orderBy('se.issue_at', 'desc')
			->offset($offset)
			->limit($perPage)
			->get();

		return [
			'data' => $this->attachEntryItems($entries, $companyId),
			'total' => (int) $total,
		];
	}

	public function listForExport(int $companyId, $branchId, array $filters, bool $includeItems): array
	{
		$entries = $this->buildBaseEntriesQuery($companyId, $branchId, $filters)
			->orderBy('se.id', 'desc')
			->orderBy('se.issue_at', 'desc')
			->get();

		if (!$includeItems) {
			return collect($entries)->all();
		}

		return $this->attachEntryItems($entries, $companyId);
	}

	private function buildBaseEntriesQuery(int $companyId, $branchId, array $filters)
	{
		$stockEntryColumns = $this->tableColumns('inventory.stock_entries');
		$hasPaymentMethodId = in_array('payment_method_id', $stockEntryColumns, true);
		$hasMetadata = in_array('metadata', $stockEntryColumns, true);

		$summarySubquery = DB::table('inventory.stock_entry_items as sei')
			->selectRaw('sei.entry_id, COUNT(*) as total_items, COALESCE(SUM(sei.qty), 0) as total_qty, COALESCE(SUM(sei.qty * sei.unit_cost), 0) as total_amount')
			->groupBy('sei.entry_id');

		$query = DB::table('inventory.stock_entries as se')
			->leftJoin('inventory.warehouses as w', 'se.warehouse_id', '=', 'w.id')
			->leftJoinSub($summarySubquery, 's', function ($join) {
				$join->on('s.entry_id', '=', 'se.id');
			})
			->select(
				'se.id',
				'se.entry_type',
				'se.status',
				DB::raw("CASE UPPER(COALESCE(se.status, ''))
					WHEN 'APPLIED' THEN 'Aplicado'
					WHEN 'OPEN' THEN 'Abierto'
					WHEN 'PARTIAL' THEN 'Parcial'
					WHEN 'CLOSED' THEN 'Cerrado'
					WHEN 'VOID' THEN 'Anulado'
					WHEN 'CANCELED' THEN 'Cancelado'
					ELSE COALESCE(se.status, '-')
				END as status_label"),
				'se.reference_no',
				'se.supplier_reference',
				'se.issue_at',
				'se.notes',
				$hasMetadata ? 'se.metadata' : DB::raw('NULL as metadata'),
				DB::raw('COALESCE(s.total_items, 0) as total_items'),
				DB::raw('COALESCE(s.total_qty, 0) as total_qty'),
				DB::raw('COALESCE(s.total_amount, 0) as total_amount'),
				'w.code as warehouse_code',
				'w.name as warehouse_name'
			)
			->where('se.company_id', $companyId)
			->whereIn('se.status', ['APPLIED', 'OPEN', 'PARTIAL', 'CLOSED']);

		if ($hasPaymentMethodId) {
			$query->leftJoin('master.payment_types as pm', 'se.payment_method_id', '=', 'pm.id')
				->addSelect(DB::raw('COALESCE(pm.name, \'No especificado\') as payment_method'));
		} else {
			$query->addSelect(DB::raw('\'No especificado\' as payment_method'));
		}

		if ($branchId !== null) {
			$query->where(function ($q) use ($branchId) {
				$q->where('se.branch_id', (int) $branchId)
					->orWhereNull('se.branch_id');
			});
		}

		$entryType = $filters['entry_type'] ?? null;
		$reference = $filters['reference'] ?? null;
		$dateFrom = $filters['date_from'] ?? null;
		$dateTo = $filters['date_to'] ?? null;
		$warehouseId = $filters['warehouse_id'] ?? null;

		if ($entryType && in_array($entryType, ['PURCHASE', 'ADJUSTMENT', 'PURCHASE_ORDER'], true)) {
			$query->where('se.entry_type', $entryType);
		}

		if ($reference) {
			$searchTerm = '%' . $reference . '%';
			$query->where(function ($q) use ($searchTerm) {
				$q->where('se.reference_no', 'ilike', $searchTerm)
					->orWhere('se.supplier_reference', 'ilike', $searchTerm);
			});
		}

		if ($dateFrom) {
			$query->whereDate('se.issue_at', '>=', $dateFrom);
		}

		if ($dateTo) {
			$query->whereDate('se.issue_at', '<=', $dateTo);
		}

		if ($warehouseId) {
			$query->where('se.warehouse_id', (int) $warehouseId);
		}

		return $query;
	}

	private function attachEntryItems($entries, int $companyId): array
	{
		$entryIds = collect($entries)->pluck('id')->map(function ($id) {
			return (int) $id;
		})->filter(function ($id) {
			return $id > 0;
		})->values();

		if ($entryIds->isEmpty()) {
			return collect($entries)->all();
		}

		$itemColumns = $this->tableColumns('inventory.stock_entry_items');
		$hasTaxCategory = in_array('tax_category_id', $itemColumns, true);
		$hasTaxRate = in_array('tax_rate', $itemColumns, true);
		$taxById = collect($this->resolveTaxCategories($companyId))->keyBy('id');

		$items = DB::table('inventory.stock_entry_items as sei')
			->leftJoin('inventory.products as p', 'sei.product_id', '=', 'p.id')
			->leftJoin('inventory.product_lots as pl', 'sei.lot_id', '=', 'pl.id')
			->whereIn('sei.entry_id', $entryIds->all())
			->select([
				'sei.entry_id',
				'sei.product_id',
				'sei.lot_id',
				DB::raw('COALESCE(p.name, CONCAT(\'Producto #\', sei.product_id)) as product_name'),
				'sei.qty',
				'sei.unit_cost',
				$hasTaxCategory ? 'sei.tax_category_id' : DB::raw('NULL as tax_category_id'),
				$hasTaxRate ? 'sei.tax_rate' : DB::raw('0 as tax_rate'),
				'sei.notes',
				'pl.lot_code',
			])
			->orderBy('sei.entry_id')
			->orderBy('p.name')
			->orderBy('sei.id')
			->get()
			->map(function ($row) use ($taxById) {
				$subtotal = (float) $row->qty * (float) $row->unit_cost;
				$taxRate = (float) ($row->tax_rate ?? 0);
				$taxAmount = $subtotal * ($taxRate / 100);
				$taxCategoryId = $row->tax_category_id ? (int) $row->tax_category_id : null;
				$taxRow = $taxCategoryId ? $taxById->get($taxCategoryId) : null;

				return [
					'entry_id' => (int) $row->entry_id,
					'product_id' => (int) $row->product_id,
					'lot_id' => $row->lot_id ? (int) $row->lot_id : null,
					'product_name' => (string) $row->product_name,
					'qty' => (float) $row->qty,
					'unit_cost' => (float) $row->unit_cost,
					'subtotal' => round($subtotal, 4),
					'tax_category_id' => $taxCategoryId,
					'tax_label' => $taxRow['label'] ?? 'Sin IGV',
					'tax_rate' => $taxRate,
					'tax_amount' => round($taxAmount, 4),
					'line_total' => round($subtotal + $taxAmount, 4),
					'lot_code' => $row->lot_code,
					'notes' => $row->notes,
				];
			})
			->groupBy('entry_id');

		return collect($entries)->map(function ($entry) use ($items) {
			$row = (array) $entry;
			$row['items'] = $items->get((int) $entry->id, collect())->values()->all();
			return $row;
		})->values()->all();
	}

	private function resolveTaxCategories(int $companyId): array
	{
		$sourceTable = null;

		foreach (['core.tax_categories', 'sales.tax_categories', 'appcfg.tax_categories'] as $candidate) {
			if ($this->tableExists($candidate)) {
				$sourceTable = $candidate;
				break;
			}
		}

		if (!$sourceTable) {
			return [];
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
		})->values()->all();

		return $this->companyIgvRateService->applyActiveRateToTaxCategories($companyId, $rows);
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

		$rows = DB::select(
			'select column_name from information_schema.columns where table_schema = ? and table_name = ?',
			[$schema, $table]
		);

		return collect($rows)->map(function ($row) {
			return (string) $row->column_name;
		})->values()->all();
	}

	private function firstExistingColumn(array $columns, array $candidates)
	{
		foreach ($candidates as $candidate) {
			if (in_array($candidate, $columns, true)) {
				return $candidate;
			}
		}

		return null;
	}

	private function splitQualifiedTable(string $qualifiedTable): array
	{
		if (strpos($qualifiedTable, '.') !== false) {
			return explode('.', $qualifiedTable, 2);
		}

		return ['public', $qualifiedTable];
	}
}
