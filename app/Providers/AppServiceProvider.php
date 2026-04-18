<?php

namespace App\Providers;

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
        //
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
            DB::unprepared("SET TIME ZONE '{$escapedTimezone}'");
        }
    }
}
