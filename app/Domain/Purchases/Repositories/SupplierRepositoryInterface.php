<?php

namespace App\Domain\Purchases\Repositories;

use App\Application\DTOs\Purchases\PurchaseSupplierDTO;

interface SupplierRepositoryInterface
{
    public function getSuppliers(int $companyId, string $search, int $limit, bool $autocomplete): array;

    public function findSupplierByDocument(int $companyId, string $document): ?PurchaseSupplierDTO;

    public function getExistingSupplierDocumentSet(int $companyId): array;

    public function insertSupplierBatch(array $rows): void;

    public function upsertSupplierByDocument(int $companyId, array $data): void;
}