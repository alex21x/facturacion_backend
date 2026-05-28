<?php

namespace App\Domain\Sales\Repositories;

interface CustomerRepositoryInterface
{
    public function getCustomers(int $companyId, string $search, $status, int $limit, bool $autocomplete, bool $workshopVehicleSearchEnabled): array;

    public function findCustomerByDocument(int $companyId, string $document): ?object;

    public function findCustomerById(int $companyId, int $id): ?object;

    public function findCustomerIdentityByDocument(int $companyId, string $document): ?object;

    public function insertCustomer(array $data): int;

    public function updateCustomerById(int $companyId, int $id, array $data): void;

    public function customerExists(int $companyId, int $id): bool;

    public function resolveCustomerTypeIdBySunatCode(int $sunatCode): ?int;

    public function getActiveCustomerTypeIdsMap(): array;

    public function getCustomerTypeSunatCodeById(int $typeId): ?int;

    public function getExistingCustomersByDocument(int $companyId): array;

    public function findCustomerPriceProfile(int $companyId, int $customerId): ?object;

    public function upsertCustomerPriceProfile(int $companyId, int $customerId, ?int $defaultTierId, float $discountPercent, int $status): void;

    public function tierExists(int $companyId, int $tierId): bool;
}