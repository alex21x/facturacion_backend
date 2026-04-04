<?php

namespace App\Services\Sales\Documents;

use Illuminate\Support\Facades\DB;

class SalesDocumentNoteValidationService
{
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

        $sourceTotal = (float) (DB::table('sales.commercial_documents')
            ->where('company_id', $companyId)
            ->where('id', $sourceDocumentId)
            ->value('total') ?? 0);

        $alreadyApplied = (float) (DB::table('sales.commercial_documents as d')
            ->where('d.company_id', $companyId)
            ->where('d.document_kind', $payload['document_kind'])
            ->whereNotIn('d.status', ['VOID', 'CANCELED'])
            ->whereRaw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0) = ?", [$sourceDocumentId])
            ->sum('d.total'));

        $remainingAmount = $sourceTotal - $alreadyApplied;

        if ($remainingAmount <= 0.00001) {
            throw new SalesDocumentException('El documento afectado ya no tiene saldo disponible para esta nota.');
        }

        if ($grandTotal - $remainingAmount > 0.00001) {
            throw new SalesDocumentException('El total de la nota excede el saldo disponible del comprobante afectado.');
        }
    }
}
