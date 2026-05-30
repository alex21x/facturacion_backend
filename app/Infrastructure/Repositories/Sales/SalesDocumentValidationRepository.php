<?php

namespace App\Infrastructure\Repositories\Sales;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SalesDocumentValidationRepository
{
    public function firstActiveWarehouseId(int $companyId): ?int
    {
        $value = DB::table('inventory.warehouses')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->orderBy('id')
            ->value('id');

        return $value !== null ? (int) $value : null;
    }

    public function branchExists(int $companyId, int $branchId): bool
    {
        return DB::table('core.branches')
            ->where('id', $branchId)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->exists();
    }

    public function warehouseExists(int $companyId, int $warehouseId, ?int $branchId): bool
    {
        return DB::table('inventory.warehouses')
            ->where('id', $warehouseId)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->when($branchId !== null, function (Builder $query) use ($branchId): void {
                $query->where(function (Builder $nested) use ($branchId): void {
                    $nested->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->exists();
    }

    public function cashRegisterExists(int $companyId, int $cashRegisterId, ?int $branchId): bool
    {
        return DB::table('sales.cash_registers')
            ->where('id', $cashRegisterId)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->when($branchId !== null, function (Builder $query) use ($branchId): void {
                $query->where(function (Builder $nested) use ($branchId): void {
                    $nested->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->exists();
    }

    public function findActiveVehicle(int $companyId, int $customerId, int $vehicleId): ?\App\Application\DTOs\Sales\SalesVehicleSnapshotDTO
    {
        $vehicle = DB::table('sales.customer_vehicles')
            ->select('id', 'plate', 'brand', 'model')
            ->where('id', $vehicleId)
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('status', 1)
            ->first();

        return $vehicle ? \App\Application\DTOs\Sales\SalesVehicleSnapshotDTO::fromRow($vehicle) : null;
    }

    public function findSourceDocument(int $companyId, int $sourceDocumentId): ?\App\Application\DTOs\Sales\SalesSourceDocumentDTO
    {
        $document = DB::table('sales.commercial_documents')
            ->select('id', 'customer_id', 'document_kind', 'series', 'number', 'status')
            ->where('id', $sourceDocumentId)
            ->where('company_id', $companyId)
            ->first();

        return $document ? \App\Application\DTOs\Sales\SalesSourceDocumentDTO::fromRow($document) : null;
    }
}
