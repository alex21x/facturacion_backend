<?php

namespace App\Providers;

use App\Domain\Cash\Repositories\CashSessionRepositoryInterface;
use App\Infrastructure\Repositories\Cash\CashSessionRepository;
use Illuminate\Support\ServiceProvider;

class CashRepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            CashSessionRepositoryInterface::class,
            CashSessionRepository::class
        );
    }

    public function boot(): void
    {
    }
}
