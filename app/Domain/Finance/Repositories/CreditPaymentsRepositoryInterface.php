<?php

namespace App\Domain\Finance\Repositories;

interface CreditPaymentsRepositoryInterface
{
    public function paginateCustomerCreditDocuments(int $companyId, ?int $branchId, int $page, int $perPage, ?string $search = null, ?string $paymentStatus = null): array;

    public function listCustomerDocumentPayments(int $companyId, int $documentId): array;

    public function createCustomerDocumentPayment(int $documentId, array $payload): int;

    public function updateCustomerDocumentPayment(int $companyId, int $documentId, int $paymentId, array $payload): bool;

    public function deleteCustomerDocumentPayment(int $companyId, int $documentId, int $paymentId): bool;

    public function findCustomerDocument(int $companyId, int $documentId): ?object;

    public function recalculateCustomerDocumentTotals(int $documentId): void;

    public function paginateSupplierCreditDocuments(int $companyId, ?int $branchId, int $page, int $perPage, ?string $search = null, ?string $paymentStatus = null): array;

    public function listSupplierDocumentPayments(int $companyId, int $stockEntryId): array;

    public function createSupplierDocumentPayment(int $stockEntryId, array $payload): int;

    public function updateSupplierDocumentPayment(int $companyId, int $stockEntryId, int $paymentId, array $payload): bool;

    public function deleteSupplierDocumentPayment(int $companyId, int $stockEntryId, int $paymentId): bool;

    public function findSupplierDocument(int $companyId, int $stockEntryId): ?object;

    public function hasSupplierPaymentsModule(): bool;
}
