<?php

namespace App\Infrastructure\Repositories\Sales;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerVehicleRepository
{
    public function customerExists(int $companyId, int $customerId): bool
    {
        return DB::table('sales.customers')
            ->where('id', $customerId)
            ->where('company_id', $companyId)
            ->exists();
    }

    public function listActiveVehiclesByCustomer(int $companyId, int $customerId): Collection
    {
        return DB::table('sales.customer_vehicles')
            ->select('id', 'customer_id', 'plate', 'brand', 'model', 'year', 'color', 'vin', 'is_default', 'status')
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('status', 1)
            ->orderByDesc('is_default')
            ->orderBy('brand')
            ->orderBy('model')
            ->orderBy('plate')
            ->get();
    }

    public function activePlateExists(int $companyId, string $plateNormalized, ?int $excludeVehicleId = null): bool
    {
        $query = DB::table('sales.customer_vehicles')
            ->where('company_id', $companyId)
            ->where('plate_normalized', $plateNormalized)
            ->where('status', 1);

        if ($excludeVehicleId !== null) {
            $query->where('id', '<>', $excludeVehicleId);
        }

        return $query->exists();
    }

    public function clearDefaultVehicles(int $companyId, int $customerId): void
    {
        DB::table('sales.customer_vehicles')
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->update(['is_default' => false, 'updated_at' => now()]);
    }

    public function createVehicle(array $values): int
    {
        return (int) DB::table('sales.customer_vehicles')->insertGetId($values);
    }

    public function findVehicle(int $companyId, int $customerId, int $vehicleId, bool $mustBeActive = false): ?\App\Application\DTOs\Sales\SalesVehicleSnapshotDTO
    {
        $query = DB::table('sales.customer_vehicles')
            ->where('id', $vehicleId)
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId);

        if ($mustBeActive) {
            $query->where('status', 1);
        }

        $vehicle = $query->first();

        return $vehicle ? \App\Application\DTOs\Sales\SalesVehicleSnapshotDTO::fromRow($vehicle) : null;
    }

    public function updateVehicle(int $companyId, int $customerId, int $vehicleId, array $values): void
    {
        DB::table('sales.customer_vehicles')
            ->where('id', $vehicleId)
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->update($values);
    }

    public function findFirstActiveVehicleId(int $companyId, int $customerId): ?int
    {
        $replacement = DB::table('sales.customer_vehicles')
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('status', 1)
            ->orderBy('id')
            ->first(['id']);

        return $replacement ? (int) $replacement->id : null;
    }

    public function listCustomerTypes(): Collection
    {
        return DB::table('sales.customer_types')
            ->select('id', 'name', 'sunat_code', 'sunat_abbr', 'is_active')
            ->where('is_active', true)
            ->orderBy('sunat_code')
            ->get();
    }

    public function customerTypeExists(int $customerTypeId): bool
    {
        return DB::table('sales.customer_types')->where('id', $customerTypeId)->exists();
    }

    public function findVehicleSnapshotById(int $companyId, int $customerId, int $vehicleId): ?\App\Application\DTOs\Sales\SalesVehicleSnapshotDTO
    {
        $vehicle = DB::table('sales.customer_vehicles')
            ->select(['plate', 'brand', 'model'])
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('id', $vehicleId)
            ->first();

        return $vehicle ? \App\Application\DTOs\Sales\SalesVehicleSnapshotDTO::fromRow($vehicle) : null;
    }
}
