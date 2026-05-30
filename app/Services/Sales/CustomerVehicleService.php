<?php

namespace App\Services\Sales;

use App\Application\DTOs\Sales\SalesVehicleSnapshotDTO;
use App\Infrastructure\Repositories\Sales\CustomerVehicleRepository;

class CustomerVehicleService
{
    public function __construct(private CustomerVehicleRepository $repository)
    {
    }

    public function customerExists(int $companyId, int $customerId): bool
    {
        return $this->repository->customerExists($companyId, $customerId);
    }

    public function listCustomerVehicles(int $companyId, int $customerId): array
    {
        return $this->repository->listActiveVehiclesByCustomer($companyId, $customerId)
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'customer_id' => (int) $row->customer_id,
                'plate' => (string) $row->plate,
                'brand' => $row->brand !== null ? (string) $row->brand : null,
                'model' => $row->model !== null ? (string) $row->model : null,
                'year' => $row->year !== null ? (int) $row->year : null,
                'color' => $row->color !== null ? (string) $row->color : null,
                'vin' => $row->vin !== null ? (string) $row->vin : null,
                'is_default' => (bool) ($row->is_default ?? false),
                'status' => (int) ($row->status ?? 1),
            ])
            ->values()
            ->all();
    }

    public function activePlateExists(int $companyId, string $plateNormalized, ?int $excludeVehicleId = null): bool
    {
        return $this->repository->activePlateExists($companyId, $plateNormalized, $excludeVehicleId);
    }

    public function clearDefaultVehicles(int $companyId, int $customerId): void
    {
        $this->repository->clearDefaultVehicles($companyId, $customerId);
    }

    public function createCustomerVehicle(int $companyId, int $customerId, array $payload, string $plateNormalized): array
    {
        $isDefault = (bool) ($payload['is_default'] ?? false);
        if ($isDefault) {
            $this->repository->clearDefaultVehicles($companyId, $customerId);
        }

        $newId = $this->repository->createVehicle([
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'plate' => strtoupper(trim((string) $payload['plate'])),
            'plate_normalized' => $plateNormalized,
            'brand' => $payload['brand'] ?? null,
            'model' => $payload['model'] ?? null,
            'year' => $payload['year'] ?? null,
            'color' => $payload['color'] ?? null,
            'vin' => $payload['vin'] ?? null,
            'is_default' => $isDefault,
            'status' => (int) ($payload['status'] ?? 1),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $newId,
            'is_default' => $isDefault,
            'data' => [
                'id' => $newId,
                'customer_id' => $customerId,
                'plate' => strtoupper(trim((string) $payload['plate'])),
                'brand' => $payload['brand'] ?? null,
                'model' => $payload['model'] ?? null,
                'year' => $payload['year'] ?? null,
                'color' => $payload['color'] ?? null,
                'vin' => $payload['vin'] ?? null,
                'is_default' => $isDefault,
                'status' => (int) ($payload['status'] ?? 1),
            ],
        ];
    }

    public function findVehicle(int $companyId, int $customerId, int $vehicleId, bool $mustBeActive = false): ?SalesVehicleSnapshotDTO
    {
        return $this->repository->findVehicle($companyId, $customerId, $vehicleId, $mustBeActive);
    }

    public function updateVehicle(int $companyId, int $customerId, int $vehicleId, array $update): void
    {
        $this->repository->updateVehicle($companyId, $customerId, $vehicleId, $update);
    }

    public function handleDeleteVehicleAndDefaultFallback(int $companyId, int $customerId, int $vehicleId, bool $wasDefault): void
    {
        $this->repository->updateVehicle($companyId, $customerId, $vehicleId, [
            'status' => 0,
            'is_default' => false,
            'updated_at' => now(),
        ]);

        if (!$wasDefault) {
            return;
        }

        $replacementId = $this->repository->findFirstActiveVehicleId($companyId, $customerId);
        if ($replacementId === null) {
            return;
        }

        $this->repository->updateVehicle($companyId, $customerId, $replacementId, [
            'is_default' => true,
            'updated_at' => now(),
        ]);
    }

    public function listCustomerTypes(): array
    {
        return $this->repository->listCustomerTypes()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'sunat_code' => (int) $row->sunat_code,
                'sunat_abbr' => $row->sunat_abbr,
                'is_active' => (bool) $row->is_active,
            ])
            ->values()
            ->all();
    }

    public function customerTypeExists(int $customerTypeId): bool
    {
        return $this->repository->customerTypeExists($customerTypeId);
    }

    public function findVehicleSnapshotById(int $companyId, int $customerId, int $vehicleId): ?SalesVehicleSnapshotDTO
    {
        return $this->repository->findVehicleSnapshotById($companyId, $customerId, $vehicleId);
    }
}
