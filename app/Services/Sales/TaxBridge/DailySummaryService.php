<?php

namespace App\Services\Sales\TaxBridge;

use App\Infrastructure\Repositories\Sales\TaxBridge\DailySummaryService as TaxBridgeDailySummaryRepositoryService;

class DailySummaryService extends TaxBridgeDailySummaryRepositoryService
{
	public function canAccessCompanyScope(int $authenticatedCompanyId, int $targetCompanyId): bool
	{
		return $authenticatedCompanyId === $targetCompanyId;
	}
}
