<?php

namespace App\Application\Commands\Sales;

final class VoidCommercialDocumentCommand
{
    public function __construct(
        public readonly object $authUser,
        public readonly int $companyId,
        public readonly int $documentId,
        public readonly array $payload
    ) {
    }

    public static function fromInput(object $authUser, int $companyId, int $documentId, array $payload): self
    {
        return new self($authUser, $companyId, $documentId, $payload);
    }
}
