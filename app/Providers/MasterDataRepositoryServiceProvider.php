<?php

namespace App\Providers;

use App\Domain\Masters\Repositories\MasterDataOptionsRepositoryInterface;
use App\Infrastructure\Repositories\Masters\MasterDataOptionsRepository;
use Illuminate\Support\ServiceProvider;

class MasterDataRepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            MasterDataOptionsRepositoryInterface::class,
            MasterDataOptionsRepository::class
        );
    }

    public function boot(): void
    {
    }
}
