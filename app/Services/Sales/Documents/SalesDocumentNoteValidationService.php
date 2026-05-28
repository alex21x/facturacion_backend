<?php

namespace App\Services\Sales\Documents;

use App\Domain\Sales\Repositories\CommercialDocumentRepositoryInterface;

class SalesDocumentNoteValidationService
{
    public function __construct(
        private CommercialDocumentRepositoryInterface $commercialDocumentRepository
    ) {
    }

    public function validateSourceAndAvailableAmount(
        array $payload,
        array $metadata,
        int $companyId,
        float $grandTotal
    ): void {
        if (!in_array($payload['document_kind'], ['CREDIT_NOTE', 'DEBIT_NOTE'], true)) {
            return;
        }

        $sourceDocumentId = isset($metadata['source_document_id']) ? (int) $metadata['source_document_id'] : 0;
        if ($sourceDocumentId <= 0) {
            throw new SalesDocumentException('Documento afectado invalido para nota.');
        }

        $sourceTotal = $this->commercialDocumentRepository->getDocumentTotalById($companyId, $sourceDocumentId);

        $alreadyApplied = $this->commercialDocumentRepository->getAppliedNoteTotalForSource(
            $companyId,
            $sourceDocumentId,
            (string) $payload['document_kind']
        );

        $remainingAmount = $sourceTotal - $alreadyApplied;

        if ($remainingAmount <= 0.00001) {
            throw new SalesDocumentException('El documento afectado ya no tiene saldo disponible para esta nota.');
        }

        if ($grandTotal - $remainingAmount > 0.00001) {
            throw new SalesDocumentException('El total de la nota excede el saldo disponible del comprobante afectado.');
        }
    }
}
