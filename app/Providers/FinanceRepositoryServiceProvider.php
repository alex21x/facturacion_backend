<?php

namespace App\Providers;

use App\Domain\Finance\Repositories\CreditPaymentsRepositoryInterface;
use App\Infrastructure\Repositories\Finance\CreditPaymentsRepository;
use Illuminate\Support\ServiceProvider;

class FinanceRepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            CreditPaymentsRepositoryInterface::class,
            CreditPaymentsRepository::class
        );
    }

    public function boot(): void
    {
    }
}
