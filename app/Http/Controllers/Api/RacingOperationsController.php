<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RacingOperationsController extends Controller
{
    public function bootstrap(Request $request)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json([
                'message' => 'Racing schema not ready. Run migration 2026_05_08_000201 first.',
            ], 409);
        }

        $companyId = $this->resolveCompanyId($request);

        $vehicles = DB::table('racing.vehicles')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->orderBy('name')
            ->limit(200)
            ->get();

        $events = DB::table('racing.rally_events')
            ->where('company_id', $companyId)
            ->orderByDesc('starts_at')
            ->limit(30)
            ->get();

        $locale = trim((string) $request->query('locale', 'es-PE'));
        if ($locale === '') {
            $locale = 'es-PE';
        }

        $uiTexts = $this->resolveModuleUiTexts($companyId, 'RACING_OPERATIONS', 'RACING_OPERATIONS', $locale);

        return response()->json([
            'vehicles' => $vehicles,
            'events' => $events,
            'ui_texts' => $uiTexts,
            'locale' => $locale,
        ]);
    }

    public function vehicles(Request $request)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json(['vehicles' => []]);
        }

        $companyId = $this->resolveCompanyId($request);
        $query = trim((string) $request->query('q', ''));

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

        return response()->json([
            'vehicles' => $builder->limit(300)->get(),
        ]);
    }

    public function createVehicle(Request $request)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:40',
            'name' => 'required|string|max:120',
            'plate' => 'nullable|string|max:20',
            'model' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $companyId = $this->resolveCompanyId($request);
        $payload = $validator->validated();

        $id = DB::table('racing.vehicles')->insertGetId([
            'company_id' => $companyId,
            'code' => strtoupper(trim((string) $payload['code'])),
            'name' => trim((string) $payload['name']),
            'plate' => isset($payload['plate']) ? strtoupper(trim((string) $payload['plate'])) : null,
            'model' => isset($payload['model']) ? trim((string) $payload['model']) : null,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');

        $vehicle = DB::table('racing.vehicles')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        return response()->json([
            'message' => 'Vehiculo registrado correctamente',
            'vehicle' => $vehicle,
        ], 201);
    }

    public function vehicleHistory(Request $request, int $vehicleId)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        $companyId = $this->resolveCompanyId($request);

        $vehicle = DB::table('racing.vehicles')
            ->where('id', $vehicleId)
            ->where('company_id', $companyId)
            ->first();

        if (!$vehicle) {
            return response()->json(['message' => 'Vehiculo no encontrado'], 404);
        }

        $maintenance = DB::table('racing.maintenance_records')
            ->where('company_id', $companyId)
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $assignments = DB::table('racing.inventory_assignments as a')
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

        return response()->json([
            'vehicle' => $vehicle,
            'maintenance' => $maintenance,
            'assignments' => $assignments,
        ]);
    }

    public function createMaintenance(Request $request, int $vehicleId)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        $validator = Validator::make($request->all(), [
            'component_code' => 'required|string|max:80',
            'action_type' => 'required|string|max:30',
            'service_date' => 'required|date',
            'next_service_date' => 'nullable|date',
            'odometer_km' => 'nullable|numeric|min:0',
            'estimated_life_km' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $companyId = $this->resolveCompanyId($request);
        $authUser = $request->attributes->get('auth_user');

        $exists = DB::table('racing.vehicles')
            ->where('id', $vehicleId)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Vehiculo no encontrado'], 404);
        }

        $payload = $validator->validated();

        DB::table('racing.maintenance_records')->insert([
            'company_id' => $companyId,
            'vehicle_id' => $vehicleId,
            'component_code' => strtoupper(trim((string) $payload['component_code'])),
            'action_type' => strtoupper(trim((string) $payload['action_type'])),
            'service_date' => $payload['service_date'],
            'next_service_date' => $payload['next_service_date'] ?? null,
            'odometer_km' => $payload['odometer_km'] ?? null,
            'estimated_life_km' => $payload['estimated_life_km'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'created_by' => $authUser ? (int) $authUser->id : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->vehicleHistory($request, $vehicleId);
    }

    public function events(Request $request)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json(['events' => []]);
        }

        $companyId = $this->resolveCompanyId($request);

        $events = DB::table('racing.rally_events')
            ->where('company_id', $companyId)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['events' => $events]);
    }

    public function createEvent(Request $request)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:40',
            'name' => 'required|string|max:160',
            'location' => 'nullable|string|max:120',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date',
            'budget_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $companyId = $this->resolveCompanyId($request);
        $authUser = $request->attributes->get('auth_user');
        $payload = $validator->validated();

        $id = DB::table('racing.rally_events')->insertGetId([
            'company_id' => $companyId,
            'code' => strtoupper(trim((string) $payload['code'])),
            'name' => trim((string) $payload['name']),
            'location' => $payload['location'] ?? null,
            'starts_at' => $payload['starts_at'],
            'ends_at' => $payload['ends_at'] ?? null,
            'status' => 'PLANNED',
            'budget_amount' => $payload['budget_amount'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'created_by' => $authUser ? (int) $authUser->id : null,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');

        $event = DB::table('racing.rally_events')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        return response()->json([
            'message' => 'Evento rally creado',
            'event' => $event,
        ], 201);
    }

    public function eventChecklist(Request $request, int $eventId)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json(['items' => []]);
        }

        $companyId = $this->resolveCompanyId($request);

        $eventExists = DB::table('racing.rally_events')
            ->where('id', $eventId)
            ->where('company_id', $companyId)
            ->exists();

        if (!$eventExists) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        $items = DB::table('racing.rally_event_checklist_items')
            ->where('company_id', $companyId)
            ->where('event_id', $eventId)
            ->orderBy('id')
            ->get();

        return response()->json(['items' => $items]);
    }

    public function createChecklistItem(Request $request, int $eventId)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        $validator = Validator::make($request->all(), [
            'item_type' => 'nullable|string|max:30',
            'item_label' => 'required|string|max:180',
            'planned_qty' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $companyId = $this->resolveCompanyId($request);

        $eventExists = DB::table('racing.rally_events')
            ->where('id', $eventId)
            ->where('company_id', $companyId)
            ->exists();

        if (!$eventExists) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        $payload = $validator->validated();

        DB::table('racing.rally_event_checklist_items')->insert([
            'company_id' => $companyId,
            'event_id' => $eventId,
            'item_type' => strtoupper(trim((string) ($payload['item_type'] ?? 'TOOL'))),
            'item_label' => trim((string) $payload['item_label']),
            'planned_qty' => $payload['planned_qty'] ?? 1,
            'loaded_qty' => 0,
            'returned_qty' => 0,
            'status' => 'PENDING',
            'notes' => $payload['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->eventChecklist($request, $eventId);
    }

    public function updateChecklistItem(Request $request, int $eventId, int $itemId)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        $validator = Validator::make($request->all(), [
            'loaded_qty' => 'nullable|numeric|min:0',
            'returned_qty' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $companyId = $this->resolveCompanyId($request);
        $payload = $validator->validated();

        $item = DB::table('racing.rally_event_checklist_items')
            ->where('id', $itemId)
            ->where('event_id', $eventId)
            ->where('company_id', $companyId)
            ->first();

        if (!$item) {
            return response()->json(['message' => 'Checklist item no encontrado'], 404);
        }

        $updates = ['updated_at' => now()];
        if (array_key_exists('loaded_qty', $payload)) {
            $updates['loaded_qty'] = $payload['loaded_qty'];
        }
        if (array_key_exists('returned_qty', $payload)) {
            $updates['returned_qty'] = $payload['returned_qty'];
        }
        if (array_key_exists('status', $payload)) {
            $updates['status'] = strtoupper(trim((string) $payload['status']));
        }
        if (array_key_exists('notes', $payload)) {
            $updates['notes'] = $payload['notes'];
        }

        DB::table('racing.rally_event_checklist_items')
            ->where('id', $itemId)
            ->update($updates);

        return $this->eventChecklist($request, $eventId);
    }

    public function createInventoryAssignment(Request $request)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        $validator = Validator::make($request->all(), [
            'assignment_type' => 'required|string|in:VEHICLE,EVENT',
            'vehicle_id' => 'nullable|integer|min:1',
            'event_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'product_id' => 'nullable|integer|min:1',
            'inventory_ledger_id' => 'nullable|integer|min:1',
            'quantity' => 'required|numeric|not_in:0',
            'assigned_at' => 'nullable|date',
            'notes' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $companyId = $this->resolveCompanyId($request);
        $authUser = $request->attributes->get('auth_user');

        $resolvedLedgerId = $payload['inventory_ledger_id'] ?? null;
        if (!$resolvedLedgerId
            && !empty($payload['product_id'])
            && !empty($payload['warehouse_id'])
            && $this->tableExists('inventory', 'inventory_ledger')) {
            $resolvedLedgerId = DB::table('inventory.inventory_ledger')
                ->where('company_id', $companyId)
                ->where('product_id', (int) $payload['product_id'])
                ->where('warehouse_id', (int) $payload['warehouse_id'])
                ->where('movement_type', 'IN')
                ->orderByDesc('moved_at')
                ->orderByDesc('id')
                ->value('id');
        }

        DB::table('racing.inventory_assignments')->insert([
            'company_id' => $companyId,
            'assignment_type' => strtoupper((string) $payload['assignment_type']),
            'vehicle_id' => $payload['vehicle_id'] ?? null,
            'event_id' => $payload['event_id'] ?? null,
            'product_id' => $payload['product_id'] ?? null,
            'inventory_ledger_id' => $resolvedLedgerId,
            'quantity' => $payload['quantity'],
            'assigned_at' => $payload['assigned_at'] ?? now(),
            'responsible_user_id' => $authUser ? (int) $authUser->id : null,
            'notes' => $payload['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Asignacion registrada'], 201);
    }

    public function alerts(Request $request)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json([
                'stock_threshold' => 5,
                'low_stock' => [],
                'maintenance_pending' => [],
            ]);
        }

        $companyId = $this->resolveCompanyId($request);

        $stockThreshold = 5;
        if ($this->tableExists('inventory', 'inventory_settings')
            && $this->columnExists('inventory', 'inventory_settings', 'low_stock_alert_threshold')) {
            $row = DB::table('inventory.inventory_settings')
                ->where('company_id', $companyId)
                ->first(['low_stock_alert_threshold']);
            if ($row && $row->low_stock_alert_threshold !== null) {
                $stockThreshold = max(0, (int) $row->low_stock_alert_threshold);
            }
        }

        $lowStock = [];
        if ($this->tableExists('inventory', 'current_stock')
            && $this->tableExists('inventory', 'products')
            && $this->tableExists('inventory', 'warehouses')) {
            $lowStock = DB::table('inventory.current_stock as cs')
                ->join('inventory.products as p', 'p.id', '=', 'cs.product_id')
                ->join('inventory.warehouses as w', 'w.id', '=', 'cs.warehouse_id')
                ->where('cs.company_id', $companyId)
                ->where('p.status', 1)
                ->where('cs.stock', '<=', $stockThreshold)
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

        $maintenancePending = DB::table('racing.maintenance_records as mr')
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

        $upcomingDays = max(1, min(60, (int) $request->query('maintenance_window_days', 7)));
        $today = now()->toDateString();
        $until = now()->addDays($upcomingDays)->toDateString();

        $maintenanceUpcoming = DB::table('racing.maintenance_records as mr')
            ->join('racing.vehicles as v', 'v.id', '=', 'mr.vehicle_id')
            ->where('mr.company_id', $companyId)
            ->whereNotNull('mr.next_service_date')
            ->whereDate('mr.next_service_date', '>', $today)
            ->whereDate('mr.next_service_date', '<=', $until)
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

        return response()->json([
            'stock_threshold' => $stockThreshold,
            'low_stock' => $lowStock,
            'maintenance_pending' => $maintenancePending,
            'maintenance_upcoming' => $maintenanceUpcoming,
            'maintenance_window_days' => $upcomingDays,
        ]);
    }

    public function eventCosts(Request $request, int $eventId)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json(['costs' => []]);
        }

        if (!$this->tableExists('racing', 'event_costs')) {
            return response()->json(['costs' => []]);
        }

        $companyId = $this->resolveCompanyId($request);

        $eventExists = DB::table('racing.rally_events')
            ->where('id', $eventId)
            ->where('company_id', $companyId)
            ->exists();

        if (!$eventExists) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        $costs = DB::table('racing.event_costs')
            ->where('company_id', $companyId)
            ->where('event_id', $eventId)
            ->orderByDesc('cost_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        return response()->json(['costs' => $costs]);
    }

    public function createEventCost(Request $request, int $eventId)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        if (!$this->tableExists('racing', 'event_costs')) {
            return response()->json(['message' => 'Event costs table not found'], 409);
        }

        $validator = Validator::make($request->all(), [
            'cost_stage' => 'required|string|in:PRE,DURING,POST',
            'cost_category' => 'required|string|max:40',
            'concept' => 'required|string|max:180',
            'amount' => 'required|numeric|min:0',
            'cost_date' => 'required|date',
            'supplier_name' => 'nullable|string|max:160',
            'notes' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $companyId = $this->resolveCompanyId($request);
        $authUser = $request->attributes->get('auth_user');

        $eventExists = DB::table('racing.rally_events')
            ->where('id', $eventId)
            ->where('company_id', $companyId)
            ->exists();

        if (!$eventExists) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        $payload = $validator->validated();

        DB::table('racing.event_costs')->insert([
            'company_id' => $companyId,
            'event_id' => $eventId,
            'cost_stage' => strtoupper(trim((string) $payload['cost_stage'])),
            'cost_category' => strtoupper(trim((string) $payload['cost_category'])),
            'concept' => trim((string) $payload['concept']),
            'amount' => $payload['amount'],
            'cost_date' => $payload['cost_date'],
            'supplier_name' => $payload['supplier_name'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'created_by' => $authUser ? (int) $authUser->id : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->eventCosts($request, $eventId);
    }

    public function eventCostSummary(Request $request)
    {
        if (!$this->isRacingSchemaReady()) {
            return response()->json(['events' => []]);
        }

        if (!$this->tableExists('racing', 'event_costs')) {
            return response()->json(['events' => []]);
        }

        $companyId = $this->resolveCompanyId($request);

        $events = DB::table('racing.rally_events')
            ->where('company_id', $companyId)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'code', 'name', 'budget_amount', 'starts_at', 'status']);

        if ($events->isEmpty()) {
            return response()->json(['events' => []]);
        }

        $eventIds = $events->pluck('id')->all();

        $costs = DB::table('racing.event_costs')
            ->where('company_id', $companyId)
            ->whereIn('event_id', $eventIds)
            ->selectRaw('event_id, cost_stage, SUM(amount) as total')
            ->groupBy('event_id', 'cost_stage')
            ->get();

        $grouped = $costs->groupBy('event_id');

        $rows = $events->map(function ($event) use ($grouped) {
            $stages = [
                'PRE' => 0,
                'DURING' => 0,
                'POST' => 0,
            ];

            foreach ($grouped->get($event->id, collect()) as $row) {
                $stage = strtoupper((string) $row->cost_stage);
                if (array_key_exists($stage, $stages)) {
                    $stages[$stage] = (float) $row->total;
                }
            }

            $actual = $stages['PRE'] + $stages['DURING'] + $stages['POST'];
            $budget = $event->budget_amount !== null ? (float) $event->budget_amount : null;

            return [
                'event_id' => (int) $event->id,
                'event_code' => (string) $event->code,
                'event_name' => (string) $event->name,
                'starts_at' => $event->starts_at,
                'status' => (string) $event->status,
                'budget_amount' => $budget,
                'actual_amount' => $actual,
                'variance_amount' => $budget !== null ? ($budget - $actual) : null,
                'stages' => $stages,
            ];
        })->values();

        return response()->json(['events' => $rows]);
    }

    private function resolveCompanyId(Request $request): int
    {
        $authUser = $request->attributes->get('auth_user');
        return (int) ($authUser->company_id ?? 0);
    }

    private function isRacingSchemaReady(): bool
    {
        return $this->tableExists('racing', 'vehicles')
            && $this->tableExists('racing', 'maintenance_records')
            && $this->tableExists('racing', 'rally_events')
            && $this->tableExists('racing', 'rally_event_checklist_items')
            && $this->tableExists('racing', 'inventory_assignments');
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    private function columnExists(string $schema, string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }

    private function resolveModuleUiTexts(int $companyId, string $moduleCode, string $verticalCode, string $locale): array
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
}
