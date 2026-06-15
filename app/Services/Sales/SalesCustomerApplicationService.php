<?php

namespace App\Services\Sales;

use App\Infrastructure\Repositories\Sales\Documents\SalesDocumentSupportService;
use Illuminate\Http\Request;

class SalesCustomerApplicationService
{
    public function __construct(
        private CustomerManagementService $customerManagementService,
        private CustomerVehicleService $customerVehicleService,
        private CustomerQueryService $customerQueryService,
        private SalesLookupService $salesLookupService,
        private SalesDocumentSupportService $supportService
    ) {
    }

    public function listCustomerTypes(): array
    {
        return $this->customerVehicleService->listCustomerTypes();
    }

    public function listCustomers(Request $request, bool $autocomplete = false): array
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $workshopVehicleSearchEnabled = $this->isWorkshopMultiVehicleEnabledForRequest($request, $companyId)
            && $this->tableExists('sales.customer_vehicles');

        $search = trim((string) $request->query('q', ''));
        $status = $autocomplete ? 1 : $request->query('status');
        $limit = $autocomplete ? (int) $request->query('limit', 12) : (int) $request->query('limit', 10000);

        return $this->customerQueryService->listCustomers(
            $companyId,
            $search,
            $status,
            $limit,
            $autocomplete,
            $workshopVehicleSearchEnabled
        );
    }

    public function resolveCustomerByDocument(Request $request): array
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $document = preg_replace('/\D+/', '', (string) $request->query('document', ''));
        if (!is_string($document)) {
            $document = '';
        }

        if ($document === '' || !in_array(strlen($document), [8, 11], true)) {
            return ['status' => 422, 'body' => ['message' => 'Debe enviar un DNI (8) o RUC (11) valido.']];
        }

        return $this->customerManagementService->resolveCustomerByDocument($companyId, $document);
    }

    public function createCustomer(Request $request): array
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $this->salesLookupService->ensureCustomersPhoneColumn();
        return $this->customerManagementService->createCustomer($companyId, $request->validated());
    }

    public function bulkImportCustomers(Request $request): array
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $this->salesLookupService->ensureCustomersPhoneColumn();
        return $this->customerManagementService->bulkImportCustomers($companyId, $request->validated()['rows']);
    }

    public function updateCustomer(Request $request, int $id): array
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $this->salesLookupService->ensureCustomersPhoneColumn();
        return $this->customerManagementService->updateCustomer($companyId, $id, $request->validated());
    }

    public function listCustomerVehicles(Request $request, int $id): array
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $this->assertWorkshopVehiclesAvailable($request, $companyId);
        $this->assertCustomerExists($companyId, $id);

        return $this->customerVehicleService->listCustomerVehicles($companyId, $id);
    }

    public function createCustomerVehicle(Request $request, int $id): array
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $this->assertWorkshopVehiclesAvailable($request, $companyId);
        $this->assertCustomerExists($companyId, $id);

        $payload = $request->validated();
        if (array_key_exists('doc_number', $payload)) {
            $normalizedDoc = trim((string) ($payload['doc_number'] ?? ''));
            $payload['doc_number'] = $normalizedDoc !== '' ? $normalizedDoc : null;
        }

        $plateNormalized = $this->normalizeVehiclePlate((string) ($payload['plate'] ?? ''));
        if ($plateNormalized === '') {
            return ['status' => 422, 'body' => ['message' => 'La placa ingresada no es valida']];
        }

        if ($this->customerVehicleService->activePlateExists($companyId, $plateNormalized)) {
            return ['status' => 422, 'body' => ['message' => 'La placa ya esta registrada para otro cliente']];
        }

        $created = $this->customerVehicleService->createCustomerVehicle($companyId, $id, $payload, $plateNormalized);

        return [
            'status' => 201,
            'body' => [
                'message' => 'Vehicle created',
                'id' => (int) $created['id'],
                'data' => $created['data'],
            ],
        ];
    }

    public function updateCustomerVehicle(Request $request, int $id, int $vehicleId): array
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $this->assertWorkshopVehiclesAvailable($request, $companyId);
        $this->assertCustomerExists($companyId, $id);

        $vehicle = $this->customerVehicleService->findVehicle($companyId, $id, $vehicleId);
        if (!$vehicle) {
            return ['status' => 404, 'body' => ['message' => 'Vehicle not found']];
        }

        $changes = $request->validated();
        $update = [];

        if (array_key_exists('plate', $changes)) {
            $plateNormalized = $this->normalizeVehiclePlate((string) $changes['plate']);
            if ($plateNormalized === '') {
                return ['status' => 422, 'body' => ['message' => 'La placa ingresada no es valida']];
            }

            if ($this->customerVehicleService->activePlateExists($companyId, $plateNormalized, $vehicleId)) {
                return ['status' => 422, 'body' => ['message' => 'La placa ya esta registrada para otro cliente']];
            }

            $update['plate'] = strtoupper(trim((string) $changes['plate']));
            $update['plate_normalized'] = $plateNormalized;
        }

        foreach (['brand', 'model', 'year', 'color', 'vin', 'status'] as $field) {
            if (array_key_exists($field, $changes)) {
                $update[$field] = $changes[$field];
            }
        }

        if (array_key_exists('is_default', $changes)) {
            $isDefault = (bool) $changes['is_default'];
            if ($isDefault) {
                $this->customerVehicleService->clearDefaultVehicles($companyId, $id);
            }
            $update['is_default'] = $isDefault;
        }

        if (empty($update)) {
            return ['status' => 422, 'body' => ['message' => 'No changes provided']];
        }

        $update['updated_at'] = now();
        $this->customerVehicleService->updateVehicle($companyId, $id, $vehicleId, $update);

        return ['status' => 200, 'body' => ['message' => 'Vehicle updated']];
    }

    public function deleteCustomerVehicle(Request $request, int $id, int $vehicleId): array
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $this->assertWorkshopVehiclesAvailable($request, $companyId);

        $vehicle = $this->customerVehicleService->findVehicle($companyId, $id, $vehicleId, true);
        if (!$vehicle) {
            return ['status' => 404, 'body' => ['message' => 'Vehicle not found']];
        }

        $this->customerVehicleService->handleDeleteVehicleAndDefaultFallback(
            $companyId,
            $id,
            $vehicleId,
            (bool) ($vehicle->is_default ?? false)
        );

        return ['status' => 200, 'body' => ['message' => 'Vehicle deleted']];
    }

    private function assertWorkshopVehiclesAvailable(Request $request, int $companyId): void
    {
        if (!$this->isWorkshopMultiVehicleEnabledForRequest($request, $companyId)) {
            abort(response()->json(['message' => 'Funcionalidad no habilitada para esta empresa'], 404));
        }

        if (!$this->tableExists('sales.customer_vehicles')) {
            abort(response()->json(['message' => 'La tabla de vehiculos aun no existe en esta instancia'], 503));
        }
    }

    private function assertCustomerExists(int $companyId, int $id): void
    {
        if (!$this->customerVehicleService->customerExists($companyId, $id)) {
            abort(response()->json(['message' => 'Customer not found'], 404));
        }
    }

    private function isWorkshopMultiVehicleEnabledForRequest(Request $request, int $companyId): bool
    {
        $authUser = $request->attributes->get('auth_user');
        $branchIdRaw = $request->query('branch_id', $request->input('branch_id', $authUser->branch_id ?? null));
        $branchId = ($branchIdRaw !== null && $branchIdRaw !== '') ? (int) $branchIdRaw : null;

        return $this->supportService->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_WORKSHOP_MULTI_VEHICLE', false);
    }

    private function tableExists(string $qualifiedTable): bool
    {
        return $this->salesLookupService->tableExists($qualifiedTable);
    }

    private function normalizeVehiclePlate(string $plate): string
    {
        $value = strtoupper(trim($plate));
        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }
}
