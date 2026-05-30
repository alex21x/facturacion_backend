<?php

namespace App\Domain\Purchases\Repositories;

interface SupplierRepositoryInterface
{
    public function getSuppliers(int $companyId, string $search, int $limit, bool $autocomplete): array;

    public function findSupplierByDocument(int $companyId, string $document): ?object;

    public function getExistingSupplierDocumentSet(int $companyId): array;

    public function insertSupplierBatch(array $rows): void;

    public function upsertSupplierByDocument(int $companyId, array $data): void;
}