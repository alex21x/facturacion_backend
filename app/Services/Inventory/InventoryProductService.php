<?php

namespace App\Services\Inventory;

use App\Infrastructure\Repositories\Inventory\InventoryProductCommandRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

	public function createProduct(int $companyId, array $validated, ?int $userId = null): array
	{
		return $this->repository->createProduct($companyId, $validated, $userId);
	}

	public function updateProduct(int $companyId, int $id, array $validated, ?int $userId = null): array
	{
		return $this->repository->updateProduct($companyId, $id, $validated, $userId);
	}

	public function uploadProductImage(int $companyId, int $id, UploadedFile $image, ?int $userId = null): array
	{
		$product = $this->repository->findProductImage($companyId, $id);
		if (!$product) {
			return ['ok' => false, 'status' => 404, 'message' => 'Product not found'];
		}

		$directory = "products/{$companyId}/{$id}";
		$filename = 'image-' . Str::uuid() . '.' . ($image->guessExtension() ?: 'jpg');
		$path = $image->storeAs($directory, $filename, 'public');
		if ($path === false) {
			return ['ok' => false, 'status' => 500, 'message' => 'No se pudo guardar la imagen del producto.'];
		}

		$imageUrl = Storage::disk('public')->url($path);
		$result = $this->repository->updateProduct(
			$companyId,
			$id,
			['image_url' => $imageUrl],
			$userId
		);
		if (!$result['ok']) {
			Storage::disk('public')->delete($path);
			return $result;
		}
		$this->repository->invalidateProductCacheForCompany($companyId);

		$previousPath = parse_url((string) $product->image_url, PHP_URL_PATH);
		$managedPrefix = '/storage/' . $directory . '/';
		if (
			is_string($previousPath)
			&& str_starts_with($previousPath, $managedPrefix)
		) {
			Storage::disk('public')->delete(substr($previousPath, strlen('/storage/')));
		}

		return ['ok' => true, 'status' => 200, 'image_url' => $imageUrl];
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

	public function createProductMaster(int $companyId, int $userId, string $kind, string $name): array
	{
		return $this->repository->createProductMaster($companyId, $userId, $kind, $name);
	}

	public function updateProductMaster(int $companyId, int $id, array $validated): array
	{
		return $this->repository->updateProductMaster($companyId, $id, $validated);
	}
}
