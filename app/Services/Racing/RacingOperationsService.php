<?php

namespace App\Services\Racing;

use App\Infrastructure\Repositories\Racing\RacingOperationsRepository;

class RacingOperationsService
{
    public function __construct(
        private RacingOperationsRepository $repository
    ) {
    }

    public function schemaReady(): bool
    {
        return $this->repository->isRacingSchemaReady();
    }

    public function bootstrap(int $companyId, string $locale): array
    {
        return [
            'vehicles' => $this->repository->listBootstrapVehicles($companyId),
            'events' => $this->repository->listBootstrapEvents($companyId),
            'ui_texts' => $this->repository->resolveModuleUiTexts($companyId, 'RACING_OPERATIONS', 'RACING_OPERATIONS', $locale),
            'locale' => $locale,
        ];
    }

    public function vehicles(int $companyId, string $query): array
    {
        return [
            'vehicles' => $this->repository->listVehicles($companyId, $query),
        ];
    }

    public function createVehicle(int $companyId, array $payload): array
    {
        $id = $this->repository->createVehicle([
            'company_id' => $companyId,
            'code' => strtoupper(trim((string) $payload['code'])),
            'name' => trim((string) $payload['name']),
            'plate' => isset($payload['plate']) ? strtoupper(trim((string) $payload['plate'])) : null,
            'model' => isset($payload['model']) ? trim((string) $payload['model']) : null,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'message' => 'Vehiculo registrado correctamente',
            'vehicle' => $this->repository->findVehicle($companyId, $id),
        ];
    }

    public function vehicleHistory(int $companyId, int $vehicleId): ?array
    {
        $vehicle = $this->repository->findVehicle($companyId, $vehicleId);
        if (!$vehicle) {
            return null;
        }

        return [
            'vehicle' => $vehicle,
            'maintenance' => $this->repository->listVehicleMaintenance($companyId, $vehicleId),
            'assignments' => $this->repository->listVehicleAssignments($companyId, $vehicleId),
        ];
    }

    public function createMaintenance(int $companyId, int $vehicleId, ?int $userId, array $payload): bool
    {
        if (!$this->repository->vehicleExists($companyId, $vehicleId)) {
            return false;
        }

        $this->repository->insertMaintenanceRecord([
            'company_id' => $companyId,
            'vehicle_id' => $vehicleId,
            'component_code' => strtoupper(trim((string) $payload['component_code'])),
            'action_type' => strtoupper(trim((string) $payload['action_type'])),
            'service_date' => $payload['service_date'],
            'next_service_date' => $payload['next_service_date'] ?? null,
            'odometer_km' => $payload['odometer_km'] ?? null,
            'estimated_life_km' => $payload['estimated_life_km'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    public function events(int $companyId): array
    {
        return ['events' => $this->repository->listEvents($companyId)];
    }

    public function createEvent(int $companyId, ?int $userId, array $payload): array
    {
        $id = $this->repository->createEvent([
            'company_id' => $companyId,
            'code' => strtoupper(trim((string) $payload['code'])),
            'name' => trim((string) $payload['name']),
            'location' => $payload['location'] ?? null,
            'starts_at' => $payload['starts_at'],
            'ends_at' => $payload['ends_at'] ?? null,
            'status' => 'PLANNED',
            'budget_amount' => $payload['budget_amount'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'message' => 'Evento rally creado',
            'event' => $this->repository->findEvent($companyId, $id),
        ];
    }

    public function eventChecklist(int $companyId, int $eventId): ?array
    {
        if (!$this->repository->eventExists($companyId, $eventId)) {
            return null;
        }

        return ['items' => $this->repository->listChecklistItems($companyId, $eventId)];
    }

    public function createChecklistItem(int $companyId, int $eventId, array $payload): bool
    {
        if (!$this->repository->eventExists($companyId, $eventId)) {
            return false;
        }

        $this->repository->createChecklistItem([
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

        return true;
    }

    public function updateChecklistItem(int $companyId, int $eventId, int $itemId, array $payload): bool
    {
        $item = $this->repository->findChecklistItem($companyId, $eventId, $itemId);
        if (!$item) {
            return false;
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

        $this->repository->updateChecklistItem($itemId, $updates);

        return true;
    }

    public function createInventoryAssignment(int $companyId, ?int $userId, array $payload): void
    {
        $resolvedLedgerId = $payload['inventory_ledger_id'] ?? null;
        if (!$resolvedLedgerId
            && !empty($payload['product_id'])
            && !empty($payload['warehouse_id'])
            && $this->repository->tableExists('inventory', 'inventory_ledger')) {
            $resolvedLedgerId = $this->repository->findLatestInventoryLedgerId(
                $companyId,
                (int) $payload['product_id'],
                (int) $payload['warehouse_id']
            );
        }

        $this->repository->createInventoryAssignment([
            'company_id' => $companyId,
            'assignment_type' => strtoupper((string) $payload['assignment_type']),
            'vehicle_id' => $payload['vehicle_id'] ?? null,
            'event_id' => $payload['event_id'] ?? null,
            'product_id' => $payload['product_id'] ?? null,
            'inventory_ledger_id' => $resolvedLedgerId,
            'quantity' => $payload['quantity'],
            'assigned_at' => $payload['assigned_at'] ?? now(),
            'responsible_user_id' => $userId,
            'notes' => $payload['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function alerts(int $companyId, int $upcomingDays): array
    {
        $threshold = $this->repository->getLowStockThreshold($companyId) ?? 5;
        $today = now()->toDateString();
        $until = now()->addDays($upcomingDays)->toDateString();

        return [
            'stock_threshold' => $threshold,
            'low_stock' => $this->repository->listLowStock($companyId, $threshold),
            'maintenance_pending' => $this->repository->listMaintenancePending($companyId),
            'maintenance_upcoming' => $this->repository->listMaintenanceUpcoming($companyId, $today, $until),
            'maintenance_window_days' => $upcomingDays,
        ];
    }

    public function eventCosts(int $companyId, int $eventId): ?array
    {
        if (!$this->repository->eventExists($companyId, $eventId)) {
            return null;
        }

        return ['costs' => $this->repository->listEventCosts($companyId, $eventId)];
    }

    public function createEventCost(int $companyId, int $eventId, ?int $userId, array $payload): bool
    {
        if (!$this->repository->eventExists($companyId, $eventId)) {
            return false;
        }

        $this->repository->createEventCost([
            'company_id' => $companyId,
            'event_id' => $eventId,
            'cost_stage' => strtoupper(trim((string) $payload['cost_stage'])),
            'cost_category' => strtoupper(trim((string) $payload['cost_category'])),
            'concept' => trim((string) $payload['concept']),
            'amount' => $payload['amount'],
            'cost_date' => $payload['cost_date'],
            'supplier_name' => $payload['supplier_name'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    public function eventCostSummary(int $companyId): array
    {
        $events = $this->repository->listEventsForCostSummary($companyId);
        if ($events->isEmpty()) {
            return ['events' => []];
        }

        $costs = $this->repository->listGroupedEventCosts($companyId, $events->pluck('id')->all());
        $grouped = $costs->groupBy('event_id');

        $rows = $events->map(function ($event) use ($grouped) {
            $stages = ['PRE' => 0, 'DURING' => 0, 'POST' => 0];

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

        return ['events' => $rows];
    }

    public function hasEventCostsTable(): bool
    {
        return $this->repository->hasEventCostsTable();
    }
}
