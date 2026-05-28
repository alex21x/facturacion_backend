<?php

namespace App\Providers;

use App\Domain\Ops\Repositories\OpsLatencyRepositoryInterface;
use App\Infrastructure\Repositories\Ops\OpsLatencyRepository;
use Illuminate\Support\ServiceProvider;

class OpsRepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            OpsLatencyRepositoryInterface::class,
            OpsLatencyRepository::class
        );
    }

    public function boot(): void
    {
    }
}
