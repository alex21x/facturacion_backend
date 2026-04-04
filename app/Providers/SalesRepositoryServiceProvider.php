<?php

namespace App\Providers;

use App\Domain\Sales\Repositories\CommercialDocumentItemLotRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentItemRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentPaymentRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentRepositoryInterface;
use App\Infrastructure\Repositories\Sales\CommercialDocumentItemLotRepository;
use App\Infrastructure\Repositories\Sales\CommercialDocumentItemRepository;
use App\Infrastructure\Repositories\Sales\CommercialDocumentPaymentRepository;
use App\Infrastructure\Repositories\Sales\CommercialDocumentRepository;
use Illuminate\Support\ServiceProvider;

class SalesRepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            CommercialDocumentRepositoryInterface::class,
            CommercialDocumentRepository::class
        );

        $this->app->bind(
            CommercialDocumentItemRepositoryInterface::class,
            CommercialDocumentItemRepository::class
        );

        $this->app->bind(
            CommercialDocumentItemLotRepositoryInterface::class,
            CommercialDocumentItemLotRepository::class
        );

        $this->app->bind(
            CommercialDocumentPaymentRepositoryInterface::class,
            CommercialDocumentPaymentRepository::class
        );
    }

    public function boot(): void
    {
    }
}
