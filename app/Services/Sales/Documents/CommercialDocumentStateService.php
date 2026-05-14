<?php

namespace App\Services\Sales\Documents;

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
        $row = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->select('metadata')
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

        if (!empty($metadataUpdates)) {
            $meta['sunat_last_sync_at'] = now()->toDateTimeString();
        }

        $updatePayload = [
            'updated_at' => now(),
        ];

        if (!empty($metadataUpdates)) {
            $updatePayload['metadata'] = json_encode($meta);
        }

        if ($status !== null) {
            $normalizedStatus = strtoupper(trim($status));

            if (!in_array($normalizedStatus, self::ALLOWED_DOCUMENT_STATUSES, true)) {
                throw new InvalidArgumentException('Invalid commercial document status: ' . $status);
            }

            $updatePayload['status'] = $normalizedStatus;
        }

        foreach ($extraUpdates as $key => $value) {
            if ($key === 'metadata' || $key === 'status') {
                continue;
            }

            $updatePayload[$key] = $value;
        }

        DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->update($updatePayload);

        return true;
    }
}