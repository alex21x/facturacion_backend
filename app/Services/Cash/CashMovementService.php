<?php

namespace App\Services\Cash;

use App\Domain\Cash\Repositories\CashMovementRepositoryInterface;

class CashMovementService
{
    public function __construct(
        private CashMovementRepositoryInterface $repository
    ) {
    }

    public function listMovements(int $companyId, ?int $sessionId, ?int $cashRegisterId, int $limit, array $documentRefTypes, array $excludedDocumentStatuses): array
    {
        return $this->repository->listMovements($companyId, $sessionId, $cashRegisterId, $limit, $documentRefTypes, $excludedDocumentStatuses)
            ->map(static function ($movement): array {
                $issuer = trim((string) ($movement->issuer_user_name ?? ''));
                $seller = trim((string) ($movement->origin_seller_user_name ?? ''));
                $fallbackUser = trim((string) ($movement->user_name ?? ''));

                $actorLabel = $fallbackUser;
                if ($seller !== '' && $issuer !== '' && strtoupper($seller) !== strtoupper($issuer)) {
                    $actorLabel = 'Solicita: ' . $seller . ' | Emite: ' . $issuer;
                } elseif ($issuer !== '') {
                    $actorLabel = $issuer;
                } elseif ($seller !== '') {
                    $actorLabel = $seller;
                }

                $referenceLabel = null;
                if ($movement->document_number) {
                    $kindLabel = trim((string) ($movement->document_kind_label ?? 'Comprobante'));
                    $referenceLabel = $kindLabel . ' ' . $movement->document_number;

                    $sourceNumber = trim((string) ($movement->source_document_number ?? ''));
                    $sourceKindLabel = trim((string) ($movement->source_document_kind_label ?? ''));
                    if ($sourceNumber !== '' && $sourceKindLabel !== '') {
                        $referenceLabel = $sourceKindLabel . ' ' . $sourceNumber . ' -> ' . $referenceLabel;
                    }
                }

                return [
                    'id' => (int) $movement->id,
                    'cash_register_id' => (int) $movement->cash_register_id,
                    'cash_session_id' => $movement->cash_session_id !== null ? (int) $movement->cash_session_id : null,
                    'movement_type' => (string) $movement->movement_type,
                    'amount' => $movement->amount,
                    'description' => $movement->description,
                    'ref_type' => $movement->ref_type,
                    'ref_id' => $movement->ref_id,
                    'document_number' => $movement->document_number,
                    'document_kind_label' => $movement->document_kind_label,
                    'reference_label' => $referenceLabel,
                    'user_id' => $movement->user_id,
                    'user_name' => $actorLabel,
                    'movement_at' => $movement->movement_at,
                    'payment_method_name' => $movement->payment_method_name,
                ];
            })
            ->values()
            ->all();
    }

    public function findMovementById(int $movementId): ?object
    {
        return $this->repository->findMovementById($movementId);
    }

    public function createMovement(array $payload): int
    {
        return $this->repository->createMovement($payload);
    }

    public function findSessionScopeForCommercialDocuments(int $companyId, int $sessionId): ?object
    {
        return $this->repository->findSessionScopeForCommercialDocuments($companyId, $sessionId);
    }

    public function listSessionCommercialDocuments(int $companyId, int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): array
    {
        return $this->repository->listSessionCommercialDocuments($companyId, $sessionId, $documentRefTypes, $excludedDocumentStatuses)->all();
    }

    public function listSyncSessionCommercialDocuments(int $companyId, int $sessionId, array $documentKinds): array
    {
        return $this->repository->listSyncSessionCommercialDocuments($companyId, $sessionId, $documentKinds)->all();
    }

    public function listDocumentItems(int $documentId, int $companyId): array
    {
        return $this->repository->listDocumentItems($documentId, $companyId)->all();
    }

    public function findSessionDetail(int $companyId, int $sessionId): ?object
    {
        return $this->repository->findSessionDetail($companyId, $sessionId);
    }

    public function upsertSessionCommercialDocumentMovement(int $companyId, int $sessionId, object $document, object $session): void
    {
        $this->repository->upsertSessionCommercialDocumentMovement($companyId, $sessionId, $document, $session);
    }

    public function findSessionById(int $sessionId): ?object
    {
        return $this->repository->findSessionById($sessionId);
    }

    public function recalcExpectedBalance(int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): void
    {
        $this->repository->recalcExpectedBalance($sessionId, $documentRefTypes, $excludedDocumentStatuses);
    }
}
