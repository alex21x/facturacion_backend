<?php

namespace App\Contracts;

interface TaxBridgeGateway
{
    public function retry(int $companyId, int $documentId, bool $isAutomatedRetry = false): array;

    public function preview(int $companyId, int $documentId): array;

    public function sendVoidCommunication(int $companyId, int $documentId, ?string $reason = null): array;

    public function getLastDispatchDebug(int $companyId, int $documentId): ?array;

    public function summarizeBridgeDiagnostic($response): array;

    public function downloadDocument(int $companyId, int $documentId, string $downloadMethod): array;
}
