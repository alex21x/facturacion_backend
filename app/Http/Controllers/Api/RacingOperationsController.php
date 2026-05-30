<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Racing\CreateChecklistItemRequest;
use App\Http\Requests\Racing\CreateEventCostRequest;
use App\Http\Requests\Racing\CreateEventRequest;
use App\Http\Requests\Racing\CreateInventoryAssignmentRequest;
use App\Http\Requests\Racing\CreateMaintenanceRequest;
use App\Http\Requests\Racing\CreateVehicleRequest;
use App\Http\Requests\Racing\RacingAlertsRequest;
use App\Http\Requests\Racing\RacingBootstrapRequest;
use App\Http\Requests\Racing\RacingEventChecklistRequest;
use App\Http\Requests\Racing\RacingEventCostsRequest;
use App\Http\Requests\Racing\RacingEventCostSummaryRequest;
use App\Http\Requests\Racing\RacingEventsRequest;
use App\Http\Requests\Racing\RacingVehicleHistoryRequest;
use App\Http\Requests\Racing\RacingVehiclesRequest;
use App\Http\Requests\Racing\UpdateChecklistItemRequest;
use App\Services\Racing\RacingOperationsService;
use Illuminate\Http\Request;

class RacingOperationsController extends Controller
{
    public function __construct(
        private RacingOperationsService $service
    ) {
    }

    public function bootstrap(RacingBootstrapRequest $request)
    {
        if (!$this->service->schemaReady()) {
            return response()->json([
                'message' => 'Racing schema not ready. Run migration 2026_05_08_000201 first.',
            ], 409);
        }

        $locale = trim((string) $request->query('locale', 'es-PE'));
        if ($locale === '') {
            $locale = 'es-PE';
        }

        return response()->json($this->service->bootstrap($this->resolveCompanyId($request), $locale));
    }

    public function vehicles(RacingVehiclesRequest $request)
    {
        if (!$this->service->schemaReady()) {
            return response()->json(['vehicles' => []]);
        }

        return response()->json(
            $this->service->vehicles(
                $this->resolveCompanyId($request),
                trim((string) $request->query('q', ''))
            )
        );
    }

    public function createVehicle(CreateVehicleRequest $request)
    {
        if (!$this->service->schemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        return response()->json(
            $this->service->createVehicle($this->resolveCompanyId($request), $request->validated()),
            201
        );
    }

    public function vehicleHistory(RacingVehicleHistoryRequest $request, int $vehicleId)
    {
        if (!$this->service->schemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        $history = $this->service->vehicleHistory($this->resolveCompanyId($request), $vehicleId);
        if ($history === null) {
            return response()->json(['message' => 'Vehiculo no encontrado'], 404);
        }

        return response()->json($history);
    }

    public function createMaintenance(CreateMaintenanceRequest $request, int $vehicleId)
    {
        if (!$this->service->schemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        $authUser = $request->attributes->get('auth_user');
        $created = $this->service->createMaintenance(
            $this->resolveCompanyId($request),
            $vehicleId,
            $authUser ? (int) $authUser->id : null,
            $request->validated()
        );

        if (!$created) {
            return response()->json(['message' => 'Vehiculo no encontrado'], 404);
        }

        return $this->vehicleHistory($request, $vehicleId);
    }

    public function events(RacingEventsRequest $request)
    {
        if (!$this->service->schemaReady()) {
            return response()->json(['events' => []]);
        }

        return response()->json($this->service->events($this->resolveCompanyId($request)));
    }

    public function createEvent(CreateEventRequest $request)
    {
        if (!$this->service->schemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        $authUser = $request->attributes->get('auth_user');

        return response()->json(
            $this->service->createEvent(
                $this->resolveCompanyId($request),
                $authUser ? (int) $authUser->id : null,
                $request->validated()
            ),
            201
        );
    }

    public function eventChecklist(RacingEventChecklistRequest $request, int $eventId)
    {
        if (!$this->service->schemaReady()) {
            return response()->json(['items' => []]);
        }

        $checklist = $this->service->eventChecklist($this->resolveCompanyId($request), $eventId);
        if ($checklist === null) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        return response()->json($checklist);
    }

    public function createChecklistItem(CreateChecklistItemRequest $request, int $eventId)
    {
        if (!$this->service->schemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        $created = $this->service->createChecklistItem(
            $this->resolveCompanyId($request),
            $eventId,
            $request->validated()
        );

        if (!$created) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        return $this->eventChecklist($request, $eventId);
    }

    public function updateChecklistItem(UpdateChecklistItemRequest $request, int $eventId, int $itemId)
    {
        if (!$this->service->schemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        $updated = $this->service->updateChecklistItem(
            $this->resolveCompanyId($request),
            $eventId,
            $itemId,
            $request->validated()
        );

        if (!$updated) {
            return response()->json(['message' => 'Checklist item no encontrado'], 404);
        }

        return $this->eventChecklist($request, $eventId);
    }

    public function createInventoryAssignment(CreateInventoryAssignmentRequest $request)
    {
        if (!$this->service->schemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        $authUser = $request->attributes->get('auth_user');
        $this->service->createInventoryAssignment(
            $this->resolveCompanyId($request),
            $authUser ? (int) $authUser->id : null,
            $request->validated()
        );

        return response()->json(['message' => 'Asignacion registrada'], 201);
    }

    public function alerts(RacingAlertsRequest $request)
    {
        if (!$this->service->schemaReady()) {
            return response()->json([
                'stock_threshold' => 5,
                'low_stock' => [],
                'maintenance_pending' => [],
            ]);
        }

        $upcomingDays = max(1, min(60, (int) $request->query('maintenance_window_days', 7)));

        return response()->json($this->service->alerts($this->resolveCompanyId($request), $upcomingDays));
    }

    public function eventCosts(RacingEventCostsRequest $request, int $eventId)
    {
        if (!$this->service->schemaReady()) {
            return response()->json(['costs' => []]);
        }

        if (!$this->service->hasEventCostsTable()) {
            return response()->json(['costs' => []]);
        }

        $costs = $this->service->eventCosts($this->resolveCompanyId($request), $eventId);
        if ($costs === null) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        return response()->json($costs);
    }

    public function createEventCost(CreateEventCostRequest $request, int $eventId)
    {
        if (!$this->service->schemaReady()) {
            return response()->json(['message' => 'Racing schema not ready'], 409);
        }

        if (!$this->service->hasEventCostsTable()) {
            return response()->json(['message' => 'Event costs table not found'], 409);
        }

        $authUser = $request->attributes->get('auth_user');
        $created = $this->service->createEventCost(
            $this->resolveCompanyId($request),
            $eventId,
            $authUser ? (int) $authUser->id : null,
            $request->validated()
        );

        if (!$created) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        return $this->eventCosts($request, $eventId);
    }

    public function eventCostSummary(RacingEventCostSummaryRequest $request)
    {
        if (!$this->service->schemaReady()) {
            return response()->json(['events' => []]);
        }

        if (!$this->service->hasEventCostsTable()) {
            return response()->json(['events' => []]);
        }

        return response()->json($this->service->eventCostSummary($this->resolveCompanyId($request)));
    }

    private function resolveCompanyId(Request $request): int
    {
        $authUser = $request->attributes->get('auth_user');

        return (int) ($authUser->company_id ?? 0);
    }
}
