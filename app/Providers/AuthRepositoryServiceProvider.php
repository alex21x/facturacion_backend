<?php

namespace App\Providers;

use App\Domain\Auth\Repositories\AuthSessionRepositoryInterface;
use App\Infrastructure\Repositories\Auth\AuthSessionRepository;
use Illuminate\Support\ServiceProvider;

class AuthRepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            AuthSessionRepositoryInterface::class,
            AuthSessionRepository::class
        );
    }

    public function boot(): void
    {
    }
}
