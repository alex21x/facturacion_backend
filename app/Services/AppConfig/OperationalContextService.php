<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\OperationalContextRepository;

class OperationalContextService
{
    public function __construct(
        private OperationalContextRepository $operationalContextRepository
    ) {
    }

    public function branchExists(int $companyId, int $branchId): bool
    {
        return $this->operationalContextRepository->branchExists($companyId, $branchId);
    }

    public function validateOperationalContextSelection(
        int $companyId,
        ?int $branchId,
        ?int $preferredWarehouseId,
        ?int $preferredCashRegisterId
    ): ?string {
        return $this->operationalContextRepository->validateOperationalContextSelection(
            $companyId,
            $branchId,
            $preferredWarehouseId,
            $preferredCashRegisterId
        );
    }

    public function resolveDefaultOperationalContext(int $companyId, ?int $branchId): array
    {
        return $this->operationalContextRepository->resolveDefaultOperationalContext($companyId, $branchId);
    }

    public function resolveContextData(
        int $companyId,
        ?int $resolvedBranchId,
        ?int $resolvedWarehouseId,
        ?int $resolvedCashRegisterId
    ): array {
        $company = $this->operationalContextRepository->findCompany($companyId);
        if ($company === null) {
            return [
                'company' => null,
                'branches' => collect(),
                'warehouses' => collect(),
                'cash_registers' => collect(),
                'selected' => [
                    'branch_id' => $resolvedBranchId,
                    'warehouse_id' => $resolvedWarehouseId,
                    'cash_register_id' => $resolvedCashRegisterId,
                ],
            ];
        }

        $branches = $this->operationalContextRepository->listActiveBranches($companyId);
        $warehouses = $this->operationalContextRepository->listActiveWarehouses($companyId, $resolvedBranchId);
        $cashRegisters = $this->operationalContextRepository->listActiveCashRegisters($companyId, $resolvedBranchId, $resolvedWarehouseId);

        if ($resolvedWarehouseId !== null && !$warehouses->contains('id', $resolvedWarehouseId)) {
            $resolvedWarehouseId = $warehouses->first()->id ?? null;
        }

        if ($resolvedCashRegisterId !== null && !$cashRegisters->contains('id', $resolvedCashRegisterId)) {
            $resolvedCashRegisterId = $cashRegisters->first()->id ?? null;
        }

        return [
            'company' => $company,
            'branches' => $branches,
            'warehouses' => $warehouses,
            'cash_registers' => $cashRegisters,
            'selected' => [
                'branch_id' => $resolvedBranchId,
                'warehouse_id' => $resolvedWarehouseId,
                'cash_register_id' => $resolvedCashRegisterId,
            ],
        ];
    }
}
