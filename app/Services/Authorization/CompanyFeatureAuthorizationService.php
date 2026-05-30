<?php

namespace App\Services\Authorization;

use App\Domain\Inventory\Repositories\InventoryControllerSupportRepositoryInterface;

class CompanyFeatureAuthorizationService
{
    public function __construct(private InventoryControllerSupportRepositoryInterface $repository)
    {
    }

    public function isAllowedByProfileFeature($authUser, int $companyId, string $featureCode): bool
    {
        $row = $this->repository->findCompanyFeatureToggle($companyId, $featureCode);

        if (!$row || !(bool) $row->is_enabled) {
            return true;
        }

        $config = [];
        if ($row->config !== null) {
            $decoded = json_decode((string) $row->config, true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }

        $allowSeller = (bool) ($config['allow_seller'] ?? true);
        $allowCashier = (bool) ($config['allow_cashier'] ?? true);
        $allowAdmin = (bool) ($config['allow_admin'] ?? true);

        $roleProfile = strtoupper((string) ($authUser->role_profile ?? ''));
        $roleCode = strtoupper((string) ($authUser->role_code ?? ''));

        if ($roleProfile === 'SELLER') {
            return $allowSeller;
        }
        if ($roleProfile === 'CASHIER') {
            return $allowCashier;
        }
        if ($roleCode === 'ADMIN' || $roleProfile === 'GENERAL') {
            return $allowAdmin;
        }

        return $allowAdmin;
    }
}