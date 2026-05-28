<?php

namespace App\Domain\Sales\Repositories;

interface CommercialDocumentItemLotRepositoryInterface
{
    public function create(array $data): void;

    public function deleteByDocumentIds(array $documentItemIds): void;
}
