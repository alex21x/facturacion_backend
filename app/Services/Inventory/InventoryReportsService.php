<?php

namespace App\Services\Inventory;

use App\Application\DTOs\Inventory\InventoryReportRequestDTO;
use App\Application\DTOs\Inventory\InventorySettingsDTO;
use App\Application\DTOs\Inventory\InventoryStockSummaryDTO;
use App\Infrastructure\Repositories\Inventory\InventoryReportsRepository;
use Illuminate\Support\Collection;

class InventoryReportsService
{
    private const BASIC_ALLOWED_REPORT_TYPES = [
        'STOCK_SNAPSHOT',
        'KARDEX_PHYSICAL',
        'KARDEX_VALUED',
        'INVENTORY_CUT',
    ];

    public function __construct(private InventoryReportsRepository $repository)
    {
    }

    public function inventorySettingsForCompany(int $companyId): array
    {
        $settings = $this->repository->findInventorySettings($companyId);

        if (!$settings) {
            return [
                'enable_inventory_pro' => false,
                'enable_advanced_reporting' => false,
                'enable_graphical_dashboard' => false,
                'enable_expiry_tracking' => false,
            ];
        }

        return [
            'enable_inventory_pro' => $settings->enable_inventory_pro,
            'enable_advanced_reporting' => $settings->enable_advanced_reporting,
            'enable_graphical_dashboard' => $settings->enable_graphical_dashboard,
            'enable_expiry_tracking' => $settings->enable_expiry_tracking,
        ];
    }

    public function ensureInventoryProEnabled(
        int $companyId,
        bool $requireAdvancedReporting = false,
        bool $requireDashboard = false,
        bool $requireExpiry = false
    ): void {
        $settings = $this->inventorySettingsForCompany($companyId);

        if (!(bool) $settings['enable_inventory_pro']) {
            throw new \RuntimeException('Inventory Pro is disabled for this company');
        }
        if ($requireAdvancedReporting && !(bool) $settings['enable_advanced_reporting']) {
            throw new \RuntimeException('Advanced reporting is disabled for this company');
        }
        if ($requireDashboard && !(bool) $settings['enable_graphical_dashboard']) {
            throw new \RuntimeException('Graphical dashboard is disabled for this company');
        }
        if ($requireExpiry && !(bool) $settings['enable_expiry_tracking']) {
            throw new \RuntimeException('Expiry tracking is disabled for this company');
        }
    }

    public function reportTypeAllowedForSettings(string $reportType, array $settings): bool
    {
        $normalized = strtoupper($reportType);
        $inventoryPro = (bool) ($settings['enable_inventory_pro'] ?? false);

        if ($inventoryPro) {
            if ($normalized === 'LOT_EXPIRY' && !(bool) ($settings['enable_expiry_tracking'] ?? false)) {
                return false;
            }

            return true;
        }

        return in_array($normalized, self::BASIC_ALLOWED_REPORT_TYPES, true);
    }

    public function stockSummary(int $companyId, ?int $warehouseId): ?InventoryStockSummaryDTO
    {
        return $this->repository->findStockSummary($companyId, $warehouseId);
    }

    public function expiryBuckets(int $companyId, ?int $warehouseId): Collection
    {
        return $this->repository->listExpiryBuckets($companyId, $warehouseId);
    }

    public function movementTrendAdvanced(int $companyId, string $snapshotFrom, ?int $warehouseId): Collection
    {
        return $this->repository->listMovementTrendAdvanced($companyId, $snapshotFrom, $warehouseId);
    }

    public function topProductsAdvanced(int $companyId, string $snapshotFrom, ?int $warehouseId): Collection
    {
        return $this->repository->listTopProductsAdvanced($companyId, $snapshotFrom, $warehouseId);
    }

    public function movementTrendBasic(int $companyId, string $snapshotFrom, ?int $warehouseId): Collection
    {
        return $this->repository->listMovementTrendBasic($companyId, $snapshotFrom, $warehouseId);
    }

    public function topProductsBasic(int $companyId, string $snapshotFrom, ?int $warehouseId): Collection
    {
        return $this->repository->listTopProductsBasic($companyId, $snapshotFrom, $warehouseId);
    }

    public function dailySnapshotRows(
        int $companyId,
        string $dateFrom,
        string $dateTo,
        ?int $warehouseId,
        ?int $productId,
        int $limit,
        bool $advancedReporting
    ): Collection {
        if ($advancedReporting) {
            return $this->repository->listDailySnapshotAdvanced($companyId, $dateFrom, $dateTo, $warehouseId, $productId, $limit);
        }

        return $this->repository->listDailySnapshotBasic($companyId, $dateFrom, $dateTo, $warehouseId, $productId, $limit);
    }

    public function lotExpiryRows(
        int $companyId,
        ?int $warehouseId,
        ?int $productId,
        ?string $bucket,
        int $limit
    ): Collection {
        return $this->repository->listLotExpiryRows($companyId, $warehouseId, $productId, $bucket, $limit);
    }

    public function listRequests(int $companyId, ?string $status, ?string $reportType, int $limit): Collection
    {
        return $this->repository->listReportRequests($companyId, $status, $reportType, $limit);
    }

    public function createRequest(array $payload): int
    {
        return $this->repository->createReportRequest($payload);
    }

    public function findRequestById(int $id): ?InventoryReportRequestDTO
    {
        return $this->repository->findReportRequestById($id);
    }

    public function findRequestByCompany(int $id, int $companyId): ?InventoryReportRequestDTO
    {
        return $this->repository->findReportRequestByCompany($id, $companyId);
    }
}
