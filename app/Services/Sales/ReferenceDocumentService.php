<?php

namespace App\Services\Sales;

use App\Infrastructure\Repositories\Sales\ReferenceDocumentRepository;

class ReferenceDocumentService
{
    public function __construct(private ReferenceDocumentRepository $repository)
    {
    }

    public function listReferenceDocuments(
        int $companyId,
        int $customerId,
        ?int $branchId,
        ?string $noteTargetKind,
        string $noteKind,
        int $limit,
        ?int $sellerUserId = null
    ): array {
        return $this->repository->listReferenceDocuments(
            $companyId,
            $customerId,
            $branchId,
            $noteTargetKind,
            $noteKind,
            $limit,
            $sellerUserId
        )->all();
    }

    public function listPriceTiers(int $companyId): array
    {
        return $this->repository->listPriceTiers($companyId)
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'min_qty' => $row->min_qty,
                    'max_qty' => $row->max_qty,
                    'priority' => (int) $row->priority,
                    'status' => (int) $row->status,
                ];
            })
            ->values()
            ->all();
    }

    public function createPriceTier(int $companyId, array $payload): int
    {
        return $this->repository->createPriceTier($companyId, $payload);
    }

    public function updatePriceTier(int $companyId, int $id, array $payload): void
    {
        $this->repository->updatePriceTier($companyId, $id, $payload);
    }
}
