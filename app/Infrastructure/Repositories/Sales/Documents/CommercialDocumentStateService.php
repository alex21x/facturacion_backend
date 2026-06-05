<?php

namespace App\Infrastructure\Repositories\Sales\Documents;

use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

class CommercialDocumentStateService
{
    private const ALLOWED_DOCUMENT_STATUSES = [
        'DRAFT',
        'APPROVED',
        'ISSUED',
        'VOID',
        'CANCELED',
    ];

    public function updateState(
        int $companyId,
        int $documentId,
        array $metadataUpdates = [],
        ?string $status = null,
        array $extraUpdates = []
    ): bool {
        $extraKeys = [];
        foreach ($extraUpdates as $key => $value) {
            if ($key === 'metadata' || $key === 'status') {
                continue;
            }

            $extraKeys[] = $key;
        }

        $selectColumns = array_values(array_unique(array_merge(['metadata', 'status'], $extraKeys)));

        $row = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->select($selectColumns)
            ->first();

        if (!$row) {
            return false;
        }

        $meta = json_decode((string) ($row->metadata ?? '{}'), true);
        if (!is_array($meta)) {
            $meta = [];
        }

        foreach ($metadataUpdates as $key => $value) {
            if ($value === null) {
                unset($meta[$key]);
                continue;
            }

            $meta[$key] = $value;
        }

        $metadataChanged = !empty($metadataUpdates)
            && json_encode($meta) !== json_encode(json_decode((string) ($row->metadata ?? '{}'), true) ?: []);

        $updatePayload = [];

        if ($metadataChanged) {
            $meta['sunat_last_sync_at'] = now()->toDateTimeString();
            $updatePayload['metadata'] = json_encode($meta);
        }

        if ($status !== null) {
            $normalizedStatus = strtoupper(trim($status));

            if (!in_array($normalizedStatus, self::ALLOWED_DOCUMENT_STATUSES, true)) {
                throw new InvalidArgumentException('Invalid commercial document status: ' . $status);
            }

            if (strtoupper((string) ($row->status ?? '')) !== $normalizedStatus) {
                $updatePayload['status'] = $normalizedStatus;
            }
        }

        foreach ($extraUpdates as $key => $value) {
            if ($key === 'metadata' || $key === 'status') {
                continue;
            }

            $currentValue = $row->{$key} ?? null;
            if ($currentValue !== $value) {
                $updatePayload[$key] = $value;
            }
        }

        if (empty($updatePayload)) {
            return true;
        }

        $updatePayload['updated_at'] = now();

        DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->update($updatePayload);

        return true;
    }
}