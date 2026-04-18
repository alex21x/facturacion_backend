<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnsureAppcfgCompanyUnitsAndCustomerPriceProfiles extends Migration
{
    public function up(): void
    {
        // appcfg.company_units — was previously created inline on every enabledUnits() call
        if (!Schema::hasTable('appcfg.company_units')) {
            DB::statement('
                CREATE TABLE IF NOT EXISTS appcfg.company_units (
                    company_id   BIGINT NOT NULL,
                    unit_id      BIGINT NOT NULL,
                    is_enabled   BOOLEAN NOT NULL DEFAULT FALSE,
                    updated_by   BIGINT NULL,
                    updated_at   TIMESTAMP NULL,
                    PRIMARY KEY (company_id, unit_id)
                )
            ');
        }

        // sales.customer_price_profiles — was previously created inline on every customer autocomplete call
        if (!Schema::hasTable('sales.customer_price_profiles')) {
            DB::statement('
                CREATE TABLE IF NOT EXISTS sales.customer_price_profiles (
                    id               BIGSERIAL PRIMARY KEY,
                    company_id       BIGINT NOT NULL,
                    customer_id      BIGINT NOT NULL,
                    default_tier_id  BIGINT NULL,
                    discount_percent NUMERIC(8,4) NOT NULL DEFAULT 0,
                    status           SMALLINT NOT NULL DEFAULT 1,
                    UNIQUE(company_id, customer_id)
                )
            ');
        }
    }

    public function down(): void
    {
        // Intentionally not dropping — these are core tables.
    }
}
