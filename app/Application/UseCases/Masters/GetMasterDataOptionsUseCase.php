<?php

namespace App\Application\UseCases\Masters;

use App\Domain\Masters\Repositories\MasterDataOptionsRepositoryInterface;

class GetMasterDataOptionsUseCase
{
    public function __construct(private MasterDataOptionsRepositoryInterface $repository)
    {
    }

    public function execute(int $companyId): array
    {
        return $this->repository->getOptions($companyId);
    }
}
