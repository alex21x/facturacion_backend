<?php

namespace App\Domain\Ops\Repositories;

interface OpsLatencyRepositoryInterface
{
    public function summaryByCompanyWindow(int $companyId, int $windowMinutes, int $limit): array;

    public function isSamplesTableAvailable(): bool;
}
