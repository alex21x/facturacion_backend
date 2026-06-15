<?php

namespace App\Contracts\Sales;

interface SalesDocumentApplicationServiceInterface
{
    public function buildPrintableCommercialDocumentHtml(int $companyId, int $documentId, string $format = 'ticket'): string;

    public function createCommercialDocument(object $authUser, int $companyId, array $payload, ?int $branchId = null): array;

    public function convertCommercialDocument(object $authUser, int $companyId, int $sourceId, array $payload): array;

    public function updateCommercialDocument(object $authUser, int $companyId, int $documentId, array $payload): array;

    public function voidCommercialDocument(object $authUser, int $companyId, int $documentId, array $payload): array;

    public function applyAcceptedSunatVoid(
        object $authUser,
        int $companyId,
        int $documentId,
        ?string $reason = null,
        ?string $notes = null
    ): void;

    public function paginateCommercialDocuments(
        object $authUser,
        int $companyId,
        array $queryParams,
        int $page,
        int $limit
    ): array;

    public function generateCommercialDocumentShareLink(
        int $companyId,
        int $documentId,
        string $format,
        bool $isHttpsContext
    ): array;

    public function getTaxBridgeDebug(
        object $authUser,
        int $companyId,
        int $documentId
    ): array;

    public function buildCommercialDocumentDetail(int $companyId, int $documentId): array;

    public function buildCommercialDocumentPdfBinary(
        int $companyId,
        int $documentId,
        string $format,
        bool $isPublicPdfLink
    ): array;

    public function exportCommercialDocumentsData(
        object $authUser,
        int $companyId,
        array $queryParams,
        string $detailMode,
        int $max
    ): array;

    public function sendCommercialDocumentShareEmail(
        int $companyId,
        int $documentId,
        array $emailParams,
        bool $isHttpsContext
    ): array;
}
