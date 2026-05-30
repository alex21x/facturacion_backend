<?php

namespace App\Infrastructure\Repositories\Racing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RacingOperationsRepository
{
    public function isRacingSchemaReady(): bool
    {
        return $this->tableExists('racing', 'vehicles')
            && $this->tableExists('racing', 'maintenance_records')
            && $this->tableExists('racing', 'rally_events')
            && $this->tableExists('racing', 'rally_event_checklist_items')
            && $this->tableExists('racing', 'inventory_assignments');
    }

    public function listBootstrapVehicles(int $companyId): Collection
    {
        return DB::table('racing.vehicles')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->orderBy('name')
            ->limit(200)
            ->get();
    }

    public function listBootstrapEvents(int $companyId): Collection
    {
        return DB::table('racing.rally_events')
            ->where('company_id', $companyId)
            ->orderByDesc('starts_at')
            ->limit(30)
            ->get();
    }

    public function listVehicles(int $companyId, string $query): Collection
    {
        $builder = DB::table('racing.vehicles')
            ->where('company_id', $companyId)
            ->orderBy('name');

        if ($query !== '') {
            $q = '%' . mb_strtolower($query) . '%';
            $builder->where(function ($inner) use ($q) {
                $inner->whereRaw('LOWER(code) LIKE ?', [$q])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$q])
                    ->orWhereRaw('LOWER(COALESCE(plate, \'\')) LIKE ?', [$q]);
            });
        }

        return $builder->limit(300)->get();
    }

    public function createVehicle(array $payload): int
    {
        return (int) DB::table('racing.vehicles')->insertGetId($payload, 'id');
    }

    public function findVehicle(int $companyId, int $vehicleId): ?object
    {
        return DB::table('racing.vehicles')
            ->where('id', $vehicleId)
            ->where('company_id', $companyId)
            ->first();
    }

    public function vehicleExists(int $companyId, int $vehicleId): bool
    {
        return DB::table('racing.vehicles')
            ->where('id', $vehicleId)
            ->where('company_id', $companyId)
            ->exists();
    }

    public function insertMaintenanceRecord(array $payload): bool
    {
        return DB::table('racing.maintenance_records')->insert($payload);
    }

    public function listVehicleMaintenance(int $companyId, int $vehicleId): Collection
    {
        return DB::table('racing.maintenance_records')
            ->where('company_id', $companyId)
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    public function listVehicleAssignments(int $companyId, int $vehicleId): Collection
    {
        return DB::table('racing.inventory_assignments as a')
            ->leftJoin('racing.rally_events as e', 'e.id', '=', 'a.event_id')
            ->leftJoin('inventory.inventory_ledger as il', 'il.id', '=', 'a.inventory_ledger_id')
            ->leftJoin('inventory.warehouses as w', 'w.id', '=', 'il.warehouse_id')
            ->where('a.company_id', $companyId)
            ->where('a.vehicle_id', $vehicleId)
            ->orderByDesc('a.assigned_at')
            ->orderByDesc('a.id')
            ->limit(200)
            ->get([
                'a.*',
                'e.code as event_code',
                'e.name as event_name',
                'il.ref_type as ledger_ref_type',
                'il.ref_id as ledger_ref_id',
                'il.warehouse_id as ledger_warehouse_id',
                'w.code as ledger_warehouse_code',
            ]);
    }

    public function listEvents(int $companyId): Collection
    {
        return DB::table('racing.rally_events')
            ->where('company_id', $companyId)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    public function createEvent(array $payload): int
    {
        return (int) DB::table('racing.rally_events')->insertGetId($payload, 'id');
    }

    public function findEvent(int $companyId, int $eventId): ?object
    {
        return DB::table('racing.rally_events')
            ->where('id', $eventId)
            ->where('company_id', $companyId)
            ->first();
    }

    public function eventExists(int $companyId, int $eventId): bool
    {
        return DB::table('racing.rally_events')
            ->where('id', $eventId)
            ->where('company_id', $companyId)
            ->exists();
    }

    public function listChecklistItems(int $companyId, int $eventId): Collection
    {
        return DB::table('racing.rally_event_checklist_items')
            ->where('company_id', $companyId)
            ->where('event_id', $eventId)
            ->orderBy('id')
            ->get();
    }

    public function createChecklistItem(array $payload): bool
    {
        return DB::table('racing.rally_event_checklist_items')->insert($payload);
    }

    public function findChecklistItem(int $companyId, int $eventId, int $itemId): ?object
    {
        return DB::table('racing.rally_event_checklist_items')
            ->where('id', $itemId)
            ->where('event_id', $eventId)
            ->where('company_id', $companyId)
            ->first();
    }

    public function updateChecklistItem(int $itemId, array $updates): int
    {
        return DB::table('racing.rally_event_checklist_items')
            ->where('id', $itemId)
            ->update($updates);
    }

    public function findLatestInventoryLedgerId(int $companyId, int $productId, int $warehouseId): ?int
    {
        $id = DB::table('inventory.inventory_ledger')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('movement_type', 'IN')
            ->orderByDesc('moved_at')
            ->orderByDesc('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function createInventoryAssignment(array $payload): bool
    {
        return DB::table('racing.inventory_assignments')->insert($payload);
    }

    public function getLowStockThreshold(int $companyId): ?int
    {
        if (!$this->tableExists('inventory', 'inventory_settings')
            || !$this->columnExists('inventory', 'inventory_settings', 'low_stock_alert_threshold')) {
            return null;
        }

        $row = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first(['low_stock_alert_threshold']);

        if (!$row || $row->low_stock_alert_threshold === null) {
            return null;
        }

        return max(0, (int) $row->low_stock_alert_threshold);
    }

    public function listLowStock(int $companyId, int $threshold): array
    {
        if (!$this->tableExists('inventory', 'current_stock')
            || !$this->tableExists('inventory', 'products')
            || !$this->tableExists('inventory', 'warehouses')) {
            return [];
        }

        return DB::table('inventory.current_stock as cs')
            ->join('inventory.products as p', 'p.id', '=', 'cs.product_id')
            ->join('inventory.warehouses as w', 'w.id', '=', 'cs.warehouse_id')
            ->where('cs.company_id', $companyId)
            ->where('p.status', 1)
            ->where('cs.stock', '<=', $threshold)
            ->orderBy('cs.stock')
            ->orderBy('p.name')
            ->limit(50)
            ->get([
                'cs.product_id',
                'p.sku',
                'p.name as product_name',
                'cs.warehouse_id',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
                'cs.stock',
            ])
            ->all();
    }

    public function listMaintenancePending(int $companyId): array
    {
        return DB::table('racing.maintenance_records as mr')
            ->join('racing.vehicles as v', 'v.id', '=', 'mr.vehicle_id')
            ->where('mr.company_id', $companyId)
            ->whereNotNull('mr.next_service_date')
            ->whereDate('mr.next_service_date', '<=', now()->toDateString())
            ->orderBy('mr.next_service_date')
            ->limit(50)
            ->get([
                'mr.id',
                'mr.vehicle_id',
                'v.code as vehicle_code',
                'v.name as vehicle_name',
                'mr.component_code',
                'mr.action_type',
                'mr.service_date',
                'mr.next_service_date',
                'mr.odometer_km',
            ])
            ->all();
    }

    public function listMaintenanceUpcoming(int $companyId, string $fromDate, string $toDate): array
    {
        return DB::table('racing.maintenance_records as mr')
            ->join('racing.vehicles as v', 'v.id', '=', 'mr.vehicle_id')
            ->where('mr.company_id', $companyId)
            ->whereNotNull('mr.next_service_date')
            ->whereDate('mr.next_service_date', '>', $fromDate)
            ->whereDate('mr.next_service_date', '<=', $toDate)
            ->orderBy('mr.next_service_date')
            ->limit(50)
            ->get([
                'mr.id',
                'mr.vehicle_id',
                'v.code as vehicle_code',
                'v.name as vehicle_name',
                'mr.component_code',
                'mr.action_type',
                'mr.service_date',
                'mr.next_service_date',
                'mr.odometer_km',
            ])
            ->all();
    }

    public function hasEventCostsTable(): bool
    {
        return $this->tableExists('racing', 'event_costs');
    }

    public function listEventCosts(int $companyId, int $eventId): Collection
    {
        return DB::table('racing.event_costs')
            ->where('company_id', $companyId)
            ->where('event_id', $eventId)
            ->orderByDesc('cost_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get();
    }

    public function createEventCost(array $payload): bool
    {
        return DB::table('racing.event_costs')->insert($payload);
    }

    public function listEventsForCostSummary(int $companyId): Collection
    {
        return DB::table('racing.rally_events')
            ->where('company_id', $companyId)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'code', 'name', 'budget_amount', 'starts_at', 'status']);
    }

    public function listGroupedEventCosts(int $companyId, array $eventIds): Collection
    {
        return DB::table('racing.event_costs')
            ->where('company_id', $companyId)
            ->whereIn('event_id', $eventIds)
            ->selectRaw('event_id, cost_stage, SUM(amount) as total')
            ->groupBy('event_id', 'cost_stage')
            ->get();
    }

    public function resolveModuleUiTexts(int $companyId, string $moduleCode, string $verticalCode, string $locale): array
    {
        if (!$this->tableExists('appcfg', 'module_ui_texts')) {
            return [];
        }

        if (!$this->columnExists('appcfg', 'module_ui_texts', 'module_code')
            || !$this->columnExists('appcfg', 'module_ui_texts', 'text_key')
            || !$this->columnExists('appcfg', 'module_ui_texts', 'text_value')) {
            return [];
        }

        $query = DB::table('appcfg.module_ui_texts')
            ->where('module_code', strtoupper(trim($moduleCode)));

        if ($this->columnExists('appcfg', 'module_ui_texts', 'locale')) {
            $query->where('locale', $locale);
        }

        if ($this->columnExists('appcfg', 'module_ui_texts', 'status')) {
            $query->where('status', 1);
        }

        if ($this->columnExists('appcfg', 'module_ui_texts', 'vertical_code')) {
            $query->where(function ($inner) use ($verticalCode) {
                $inner->whereNull('vertical_code')
                    ->orWhere('vertical_code', strtoupper(trim($verticalCode)));
            });
        }

        if ($this->columnExists('appcfg', 'module_ui_texts', 'company_id')) {
            $query->where(function ($inner) use ($companyId) {
                $inner->whereNull('company_id')
                    ->orWhere('company_id', $companyId);
            });
        }

        $rows = $query
            ->orderByRaw('CASE WHEN company_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->get(['text_key', 'text_value']);

        $map = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row->text_key ?? ''));
            if ($key === '') {
                continue;
            }
            $map[$key] = (string) ($row->text_value ?? '');
        }

        return $map;
    }

    public function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    public function columnExists(string $schema, string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }
}
