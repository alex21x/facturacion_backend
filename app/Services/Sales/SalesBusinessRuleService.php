<?php

namespace App\Services\Sales;

class SalesBusinessRuleService
{
    public function resolveNoteBaseKind(string $documentKind): ?string
    {
        $normalized = strtoupper(trim($documentKind));
        if ($normalized === 'CREDIT_NOTE' || strpos($normalized, 'CREDIT_NOTE_') === 0) {
            return 'CREDIT_NOTE';
        }

        if ($normalized === 'DEBIT_NOTE' || strpos($normalized, 'DEBIT_NOTE_') === 0) {
            return 'DEBIT_NOTE';
        }

        return null;
    }

    public function documentKindRequiresRucCustomer(string $documentKind): bool
    {
        return in_array(strtoupper(trim($documentKind)), ['INVOICE', 'CREDIT_NOTE', 'DEBIT_NOTE'], true);
    }

    public function documentKindDisallowsRucCustomer(string $documentKind, bool $allowReceiptRuc): bool
    {
        $normalized = strtoupper(trim($documentKind));

        if ($normalized !== 'RECEIPT') {
            return false;
        }

        return !$allowReceiptRuc;
    }

    public function customerHasRucIdentity($customer): bool
    {
        if (!$customer) {
            return false;
        }

        $docType = strtoupper(trim((string) ($customer->doc_type ?? '')));
        $docDigits = preg_replace('/\D+/', '', (string) ($customer->doc_number ?? ''));
        $sunatCode = isset($customer->customer_type_sunat_code) ? (int) $customer->customer_type_sunat_code : null;

        $hasRucDocType = in_array($docType, ['6', '06', 'RUC'], true);
        $hasRucCustomerType = $sunatCode === 6;
        $hasValidRucNumber = is_string($docDigits) && strlen($docDigits) === 11;

        return $hasValidRucNumber && ($hasRucDocType || $hasRucCustomerType);
    }

    public function validateSourceDocumentForNote(object $sourceDocument, int $customerId): ?string
    {
        if (!in_array((string) $sourceDocument->document_kind, ['INVOICE', 'RECEIPT'], true)) {
            return 'Solo se puede afectar Factura o Boleta';
        }

        if (in_array((string) $sourceDocument->status, ['VOID', 'CANCELED'], true)) {
            return 'No se puede afectar un documento anulado/cancelado';
        }

        if ((int) $sourceDocument->customer_id !== $customerId) {
            return 'El documento afectado no corresponde al cliente seleccionado';
        }

        $sunatStatus = '';
        $sunatStatusLabel = '';
        if (isset($sourceDocument->metadata) && $sourceDocument->metadata !== null) {
            $decodedMetadata = json_decode((string) $sourceDocument->metadata, true);
            if (is_array($decodedMetadata)) {
                $sunatStatus = strtoupper(trim((string) ($decodedMetadata['sunat_status'] ?? '')));
                $sunatStatusLabel = strtoupper(trim((string) ($decodedMetadata['sunat_status_label'] ?? '')));
            }
        }

        $isAccepted = $sunatStatus === 'ACCEPTED'
            || str_contains($sunatStatusLabel, 'ACEPTAD');

        if (!$isAccepted) {
            return 'Solo se puede afectar comprobantes aceptados por SUNAT';
        }

        return null;
    }

    public function resolveNoteReason(array $noteReasons, array $metadata): ?array
    {
        $noteReasonCode = trim((string) ($metadata['note_reason_code'] ?? ''));
        $noteReasonId = isset($metadata['note_reason_id']) ? (int) $metadata['note_reason_id'] : 0;

        $resolvedReason = null;

        if ($noteReasonCode !== '') {
            $resolvedReason = collect($noteReasons)->first(function ($row) use ($noteReasonCode) {
                return strtoupper((string) ($row['code'] ?? '')) === strtoupper($noteReasonCode);
            });
        }

        if (!$resolvedReason && $noteReasonId > 0) {
            $resolvedReason = collect($noteReasons)->first(function ($row) use ($noteReasonId) {
                return (int) ($row['id'] ?? 0) === $noteReasonId;
            });
        }

        return is_array($resolvedReason) ? $resolvedReason : null;
    }

    public function buildNoteMetadata(object $sourceDocument, array $resolvedReason): array
    {
        return [
            'source_document_id' => (int) $sourceDocument->id,
            'source_document_kind' => (string) $sourceDocument->document_kind,
            'source_document_number' => (string) $sourceDocument->series . '-' . (string) $sourceDocument->number,
            'note_reason_id' => (int) ($resolvedReason['id'] ?? 0),
            'note_reason_code' => (string) ($resolvedReason['code'] ?? ''),
            'note_reason_description' => (string) ($resolvedReason['description'] ?? ''),
        ];
    }
}
