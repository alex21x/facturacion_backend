<?php

namespace App\Providers;

use App\Contracts\PadronLookupGateway;
use App\Contracts\TaxBridgeGateway;
use App\Infrastructure\External\MundosoftPadronLookupGateway;
use App\Services\Sales\TaxBridge\TaxBridgeService;
use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(PadronLookupGateway::class, MundosoftPadronLookupGateway::class);
        $this->app->bind(TaxBridgeGateway::class, TaxBridgeService::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $timezone = (string) config('app.timezone', 'America/Lima');

        // Keep PHP runtime aligned with app timezone for all modules.
        if ($timezone !== '') {
            date_default_timezone_set($timezone);
        }

        // Enforce DB session timezone on PostgreSQL to avoid date shifts when persisting dates/timestamps.
        if ((string) config('database.default') === 'pgsql' && $timezone !== '') {
            $escapedTimezone = str_replace("'", "''", $timezone);
            try {
                DB::unprepared("SET TIME ZONE '{$escapedTimezone}'");
            } catch (Throwable $exception) {
                // Keep app booting in local/dev even when DB credentials are temporarily invalid.
                Log::warning('Skipping DB timezone session setup during boot', [
                    'connection' => (string) config('database.default'),
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }
}
