<?php

namespace App\Services\Sales;

use App\Application\DTOs\Sales\SalesSourceDocumentDTO;
use App\Application\DTOs\Sales\SalesVehicleSnapshotDTO;
use App\Infrastructure\Repositories\Sales\SalesDocumentValidationRepository;

class SalesDocumentValidationService
{
    public function __construct(private SalesDocumentValidationRepository $repository)
    {
    }

    public function firstActiveWarehouseId(int $companyId): ?int
    {
        return $this->repository->firstActiveWarehouseId($companyId);
    }

    public function branchExists(int $companyId, int $branchId): bool
    {
        return $this->repository->branchExists($companyId, $branchId);
    }

    public function warehouseExists(int $companyId, int $warehouseId, ?int $branchId): bool
    {
        return $this->repository->warehouseExists($companyId, $warehouseId, $branchId);
    }

    public function cashRegisterExists(int $companyId, int $cashRegisterId, ?int $branchId): bool
    {
        return $this->repository->cashRegisterExists($companyId, $cashRegisterId, $branchId);
    }

    public function findActiveVehicle(int $companyId, int $customerId, int $vehicleId): ?SalesVehicleSnapshotDTO
    {
        return $this->repository->findActiveVehicle($companyId, $customerId, $vehicleId);
    }

    public function findSourceDocument(int $companyId, int $sourceDocumentId): ?SalesSourceDocumentDTO
    {
        return $this->repository->findSourceDocument($companyId, $sourceDocumentId);
    }
}
