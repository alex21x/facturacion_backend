<?php

namespace App\Application\UseCases\Sales;

use App\Services\Sales\SalesLookupService;
use App\Services\Sales\Documents\SalesDocumentException;

class PrepareUpdateCommercialDocumentUseCase
{
    public function __construct(private SalesLookupService $salesLookupService)
    {
    }

    public function execute(array $payload): array
    {
        $documentKindId = array_key_exists('document_kind_id', $payload) ? (int) $payload['document_kind_id'] : 0;
        if ($documentKindId <= 0) {
            return $payload;
        }

        $documentKindCode = $this->resolveDocumentKindCodeById($documentKindId);
        if ($documentKindCode === null) {
            throw new SalesDocumentException('document_kind_id invalido');
        }

        $payload['document_kind'] = $documentKindCode;
        $payload['document_kind_id'] = $documentKindId;

        return $payload;
    }

    private function resolveDocumentKindCodeById(int $documentKindId): ?string
    {
        $rows = $this->salesLookupService->listDocumentKindsCatalog();

        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) !== $documentKindId) {
                continue;
            }

            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            return $code !== '' ? $code : null;
        }

        return null;
    }
}
