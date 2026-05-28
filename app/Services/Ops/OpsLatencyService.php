<?php

namespace App\Services\Ops;

use App\Domain\Ops\Repositories\OpsLatencyRepositoryInterface;

class OpsLatencyService
{
    public function __construct(
        private OpsLatencyRepositoryInterface $repository
    ) {
    }

    public function summaryByCompanyWindow(int $companyId, int $windowMinutes, int $limit): array
    {
        return $this->repository->summaryByCompanyWindow($companyId, $windowMinutes, $limit);
    }
}
