<?php

namespace App\Providers;

use App\Domain\Cash\Repositories\CashMovementRepositoryInterface;
use App\Infrastructure\Repositories\Cash\CashMovementRepository;
use Illuminate\Support\ServiceProvider;

class CashMovementRepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            CashMovementRepositoryInterface::class,
            CashMovementRepository::class
        );
    }

    public function boot(): void
    {
    }
}
