<?php

namespace App\Contracts\Sales;

interface SalesDocumentApplicationServiceInterface
{
    public function buildPrintableCommercialDocumentHtml(int $companyId, int $documentId, string $format = 'ticket'): string;
}
