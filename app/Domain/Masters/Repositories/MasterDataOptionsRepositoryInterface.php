<?php

namespace App\Domain\Masters\Repositories;

interface MasterDataOptionsRepositoryInterface
{
    public function getOptions(int $companyId): array;
}
