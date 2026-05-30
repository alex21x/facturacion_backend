<?php

namespace App\Services\Sales\TaxBridge;

use App\Infrastructure\Repositories\Sales\TaxBridge\GreGuideService as TaxBridgeGreGuideRepositoryService;

class GreGuideService extends TaxBridgeGreGuideRepositoryService
{
	private const TRACEABILITY_FEATURE_CODE = 'SALES_TAX_BRIDGE_DEBUG_VIEW';

	public function canAccessCompanyScope(int $authenticatedCompanyId, int $targetCompanyId): bool
	{
		return $authenticatedCompanyId === $targetCompanyId;
	}

	public function isTraceabilityEnabledForAudit(int $companyId, ?int $branchId): bool
	{
		return $this->isFeatureEnabledForContext($companyId, $branchId, self::TRACEABILITY_FEATURE_CODE, false);
	}

	public function sanitizeLimit(mixed $requestedLimit, int $defaultLimit, int $maxLimit): int
	{
		$limit = (int) ($requestedLimit ?? $defaultLimit);
		if ($limit <= 0) {
			$limit = $defaultLimit;
		}

		return min($limit, $maxLimit);
	}
}
