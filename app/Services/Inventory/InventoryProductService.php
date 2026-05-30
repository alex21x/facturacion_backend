<?php

namespace App\Services\Inventory;

use App\Infrastructure\Repositories\Inventory\InventoryProductCommandRepository;

class InventoryProductService
{
	public function __construct(
		private InventoryProductCommandRepository $repository
	) {
	}

	public function listProducts(int $companyId, string $search, $status, int $limit, bool $autocomplete): array
	{
		return $this->repository->listProducts($companyId, $search, $status, $limit, $autocomplete);
	}

	public function createProduct(int $companyId, array $validated): array
	{
		return $this->repository->createProduct($companyId, $validated);
	}

	public function updateProduct(int $companyId, int $id, array $validated): array
	{
		return $this->repository->updateProduct($companyId, $id, $validated);
	}

	public function bulkImportProducts(int $companyId, int $userId, array $validated): array
	{
		return $this->repository->bulkImportProducts($companyId, $userId, $validated);
	}

	public function bulkUpdateProductStock(int $companyId, int $userId, array $validated): array
	{
		return $this->repository->bulkUpdateProductStock($companyId, $userId, $validated);
	}

	public function listImportBatches(int $companyId, int $limit): array
	{
		return $this->repository->listImportBatches($companyId, $limit);
	}

	public function getImportBatchDetail(int $companyId, int $batchId, int $itemsLimit): array
	{
		return $this->repository->getImportBatchDetail($companyId, $batchId, $itemsLimit);
	}

	public function listProductMasters(int $companyId): array
	{
		return $this->repository->listProductMasters($companyId);
	}

	public function createProductMaster(int $companyId, array $validated): array
	{
		return $this->repository->createProductMaster($companyId, $validated);
	}

	public function updateProductMaster(int $companyId, int $id, array $validated): array
	{
		return $this->repository->updateProductMaster($companyId, $id, $validated);
	}
}
