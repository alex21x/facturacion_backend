<?php

namespace App\Infrastructure\Repositories\AppConfig;

use App\Application\DTOs\AppConfig\CompanyProfileDTO;
use Illuminate\Support\Facades\DB;

class OperationalContextRepository
{
    public function findCompany(int $companyId): ?CompanyProfileDTO
    {
        $row = DB::table('core.companies')
            ->select('id', 'tax_id', 'legal_name', 'trade_name', 'status')
            ->where('id', $companyId)
            ->first();

        return $row ? CompanyProfileDTO::fromRow($row) : null;
    }

    public function branchExists(int $companyId, int $branchId): bool
    {
        return DB::table('core.branches')
            ->where('id', $branchId)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->exists();
    }

    public function validateOperationalContextSelection(
        int $companyId,
        ?int $branchId,
        ?int $preferredWarehouseId,
        ?int $preferredCashRegisterId
    ): ?string {
        if ($preferredWarehouseId !== null) {
            $warehouseExists = DB::table('inventory.warehouses')
                ->where('company_id', $companyId)
                ->where('id', $preferredWarehouseId)
                ->when($branchId !== null, function ($query) use ($branchId) {
                    $query->where(function ($nested) use ($branchId) {
                        $nested->where('branch_id', $branchId)
                            ->orWhereNull('branch_id');
                    });
                })
                ->exists();

            if (!$warehouseExists) {
                return 'Invalid warehouse scope';
            }
        }

        if ($preferredCashRegisterId !== null) {
            $cashRegisterExists = DB::table('sales.cash_registers')
                ->where('company_id', $companyId)
                ->where('id', $preferredCashRegisterId)
                ->when($branchId !== null, function ($query) use ($branchId) {
                    $query->where(function ($nested) use ($branchId) {
                        $nested->where('branch_id', $branchId)
                            ->orWhereNull('branch_id');
                    });
                })
                ->when($preferredWarehouseId !== null, function ($query) use ($preferredWarehouseId) {
                    $query->where(function ($nested) use ($preferredWarehouseId) {
                        $nested->where('warehouse_id', $preferredWarehouseId)
                            ->orWhereNull('warehouse_id');
                    });
                })
                ->exists();

            if (!$cashRegisterExists) {
                return 'Invalid cash register scope';
            }
        }

        return null;
    }

    public function resolveDefaultOperationalContext(int $companyId, ?int $branchId): array
    {
        $warehouseId = DB::table('inventory.warehouses')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where(function ($nested) use ($branchId) {
                    $nested->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->orderByRaw($branchId !== null ? 'CASE WHEN branch_id = ? THEN 0 ELSE 1 END' : 'CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END', $branchId !== null ? [$branchId] : [])
            ->orderBy('name')
            ->value('id');

        $cashRegisterId = DB::table('sales.cash_registers')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where(function ($nested) use ($branchId) {
                    $nested->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->when($warehouseId !== null, function ($query) use ($warehouseId) {
                $query->where(function ($nested) use ($warehouseId) {
                    $nested->where('warehouse_id', (int) $warehouseId)
                        ->orWhereNull('warehouse_id');
                });
            })
            ->orderByRaw($warehouseId !== null ? 'CASE WHEN warehouse_id = ? THEN 0 ELSE 1 END' : 'CASE WHEN warehouse_id IS NULL THEN 0 ELSE 1 END', $warehouseId !== null ? [(int) $warehouseId] : [])
            ->orderBy('name')
            ->value('id');

        return [
            'warehouse_id' => $warehouseId !== null ? (int) $warehouseId : null,
            'cash_register_id' => $cashRegisterId !== null ? (int) $cashRegisterId : null,
        ];
    }

    public function listActiveBranches(int $companyId)
    {
        return DB::table('core.branches')
            ->select('id', 'company_id', 'code', 'name', 'is_main', 'status')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get();
    }

    public function listActiveWarehouses(int $companyId, ?int $branchId)
    {
        return DB::table('inventory.warehouses')
            ->select('id', 'company_id', 'branch_id', 'code', 'name', 'status')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where(function ($nested) use ($branchId) {
                    $nested->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->orderBy('name')
            ->get();
    }

    public function listActiveCashRegisters(int $companyId, ?int $branchId, ?int $warehouseId)
    {
        return DB::table('sales.cash_registers')
            ->select('id', 'company_id', 'branch_id', 'warehouse_id', 'code', 'name', 'status')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where(function ($nested) use ($branchId) {
                    $nested->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->when($warehouseId !== null, function ($query) use ($warehouseId) {
                $query->where(function ($nested) use ($warehouseId) {
                    $nested->where('warehouse_id', $warehouseId)
                        ->orWhereNull('warehouse_id');
                });
            })
            ->orderBy('name')
            ->get();
    }
}
