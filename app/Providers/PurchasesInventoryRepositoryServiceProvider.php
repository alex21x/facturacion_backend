<?php

namespace App\Providers;

use App\Domain\Inventory\Repositories\ProductLookupRepositoryInterface;
use App\Domain\Inventory\Repositories\InventoryProductRepositoryInterface;
use App\Domain\Inventory\Repositories\InventoryStockEntryRepositoryInterface;
use App\Domain\Inventory\Repositories\InventoryReadRepositoryInterface;
use App\Domain\Inventory\Repositories\InventoryProductCommercialRepositoryInterface;
use App\Domain\Inventory\Repositories\InventoryControllerSupportRepositoryInterface;
use App\Domain\Purchases\Repositories\PurchasesLookupRepositoryInterface;
use App\Domain\Purchases\Repositories\PurchasesStockEntryRepositoryInterface;
use App\Domain\Purchases\Repositories\SupplierRepositoryInterface;
use App\Infrastructure\Repositories\Inventory\InventoryReadRepository;
use App\Infrastructure\Repositories\Inventory\InventoryProductCommercialRepository;
use App\Infrastructure\Repositories\Inventory\InventoryProductRepository;
use App\Infrastructure\Repositories\Inventory\InventoryStockEntryRepository;
use App\Infrastructure\Repositories\Inventory\ProductLookupRepository;
use App\Infrastructure\Repositories\Inventory\InventoryControllerSupportRepository;
use App\Infrastructure\Repositories\Purchases\PurchasesLookupRepository;
use App\Infrastructure\Repositories\Purchases\PurchasesStockEntryRepository;
use App\Infrastructure\Repositories\Purchases\SupplierRepository;
use Illuminate\Support\ServiceProvider;

class PurchasesInventoryRepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            PurchasesLookupRepositoryInterface::class,
            PurchasesLookupRepository::class
        );

        $this->app->bind(
            PurchasesStockEntryRepositoryInterface::class,
            PurchasesStockEntryRepository::class
        );

        $this->app->bind(
            ProductLookupRepositoryInterface::class,
            ProductLookupRepository::class
        );

        $this->app->bind(
            InventoryProductRepositoryInterface::class,
            InventoryProductRepository::class
        );

        $this->app->bind(
            InventoryStockEntryRepositoryInterface::class,
            InventoryStockEntryRepository::class
        );

        $this->app->bind(
            InventoryReadRepositoryInterface::class,
            InventoryReadRepository::class
        );

        $this->app->bind(
            InventoryProductCommercialRepositoryInterface::class,
            InventoryProductCommercialRepository::class
        );

        $this->app->bind(
            InventoryControllerSupportRepositoryInterface::class,
            InventoryControllerSupportRepository::class
        );

        $this->app->bind(
            SupplierRepositoryInterface::class,
            SupplierRepository::class
        );
    }

    public function boot(): void
    {
    }
}
